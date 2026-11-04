<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\wardrobe;

use ninjaknights\cosmetics\event\WardrobeEnterEvent;
use ninjaknights\cosmetics\event\WardrobeExitEvent;
use ninjaknights\cosmetics\manager\CosmeticManager;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMissSwingEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use pocketmine\network\mcpe\protocol\SetHudPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionColor;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionTime;
use pocketmine\network\mcpe\protocol\types\camera\CameraPreset;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstruction;
use pocketmine\network\mcpe\protocol\types\hud\HudElement;
use pocketmine\network\mcpe\protocol\types\hud\HudVisibility;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;
use function spl_object_id;

/**
 * Runs players' visits to wardrobe zones: fade, the player frozen on the zone's stand with a fixed
 * camera aimed at them, punch for the cosmetics menu, sneak to leave.
 *
 * Everything a visit changes is recorded in its WardrobeSession and undone by end(), which every
 * way out goes through (sneaking, quitting, death, outside teleports, reload, shutdown).
 */
final class WardrobeSessionManager implements Listener{

	/** Length of the fade to black, in ticks; the player is moved while the screen is black. */
	private const FADE_TICKS = 7;
	private const MENU_DEBOUNCE_TICKS = 10;
	private const PROMPT_INTERVAL_TICKS = 40;
	private const EYE_HEIGHT = 1.62;
	private const CHEST_HEIGHT = 1.0;

	/** Index of the free camera in the preset list sent at join. */
	private const PRESET_FREE = 0;

	/** Touch controls stay, so that touchscreen players keep their sneak button. */
	private const HIDDEN_HUD = [
		HudElement::PAPER_DOLL, HudElement::ARMOR, HudElement::CROSSHAIR, HudElement::HOTBAR,
		HudElement::HEALTH, HudElement::XP, HudElement::FOOD, HudElement::AIR_BUBBLES,
		HudElement::HORSE_HEALTH, HudElement::STATUS_EFFECTS, HudElement::ITEM_TEXT
	];

	/** @var \WeakMap<Player, WardrobeSession> */
	private \WeakMap $sessions;
	private bool $movingPlayer = false;

	/**
	 * @param \Closure(Player) : void $openMenu
	 * @param \Closure() : int        $currentTick
	 */
	public function __construct(
		private Plugin $plugin,
		private CosmeticManager $cosmetics,
		private \Closure $openMenu,
		private \Closure $currentTick
	){
		$this->sessions = new \WeakMap();
	}

	public function isInWardrobe(Player $player) : bool{
		return isset($this->sessions[$player]);
	}

	/**
	 * Starts a visit: fades to black, then moves the player in while the screen is dark.
	 */
	public function enter(Player $player, WardrobeZone $zone) : void{
		if(isset($this->sessions[$player]) || $zone->stand === null || $zone->camera === null || $zone->exit === null){
			return;
		}
		$event = new WardrobeEnterEvent($player, $zone);
		$event->call();
		if($event->isCancelled()){
			return;
		}
		$session = new WardrobeSession($zone);
		$this->sessions[$player] = $session;
		$this->fade($player);
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $session) : void{
			if(($this->sessions[$player] ?? null) === $session && $player->isConnected()){
				$this->begin($player, $session);
			}
		}), self::FADE_TICKS);
	}

	private function begin(Player $player, WardrobeSession $session) : void{
		$zone = $session->zone;
		if($zone->stand === null || $zone->camera === null){
			return;
		}
		$session->restricted = true;
		$this->moveTo($player, $zone->stand->facing($zone->camera->toVector()));
		$player->setNoClientPredictions(true);
		foreach($player->getServer()->getOnlinePlayers() as $viewer){
			$this->hideFrom($player, $session, $viewer);
		}
		//hiding the player also hides their balloon, pet and particles from everyone else
		$this->cosmetics->setGadgetsSuppressed($player, true);

		$network = $player->getNetworkSession();
		$network->sendDataPacket(SetHudPacket::create(self::HIDDEN_HUD, HudVisibility::HIDE));
		$network->sendDataPacket(CameraInstructionPacket::create(
			new CameraSetInstruction(
				self::PRESET_FREE,
				null,
				$zone->camera->toVector()->add(0, self::EYE_HEIGHT, 0),
				null,
				$zone->stand->toVector()->add(0, self::CHEST_HEIGHT, 0),
				null,
				null,
				null,
				false
			),
			null, null, null, null, null, null, null, null
		));

		$session->phase = WardrobeSession::PHASE_ACTIVE;
		$this->tickSession($player, $session, ($this->currentTick)());
	}

	/**
	 * Fades out, then returns the player to the zone's exit.
	 */
	public function leave(Player $player) : void{
		$session = $this->sessions[$player] ?? null;
		if($session === null || $session->phase !== WardrobeSession::PHASE_ACTIVE){
			return;
		}
		$session->phase = WardrobeSession::PHASE_LEAVING;
		$this->fade($player);
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $session) : void{
			if(($this->sessions[$player] ?? null) === $session){
				$this->end($player, true);
			}
		}), self::FADE_TICKS);
	}

	/**
	 * Undoes everything the visit changed.
	 *
	 * @param bool $toExit move the player to the zone's exit location
	 */
	public function end(Player $player, bool $toExit) : void{
		$session = $this->sessions[$player] ?? null;
		if($session === null){
			return;
		}
		unset($this->sessions[$player]);
		if($session->restricted){
			if($toExit && $session->zone->exit !== null){
				$this->moveTo($player, $session->zone->exit);
			}
			$player->setNoClientPredictions(false);
			if($player->isConnected()){
				$network = $player->getNetworkSession();
				$network->sendDataPacket(CameraInstructionPacket::create(null, true, null, null, null, null, null, null, null));
				$network->sendDataPacket(SetHudPacket::create(self::HIDDEN_HUD, HudVisibility::RESET));
			}
			foreach($session->hiddenFrom as $viewer){
				if($viewer->isConnected()){
					$viewer->showPlayer($player);
				}
			}
			$this->cosmetics->setGadgetsSuppressed($player, false);
		}
		(new WardrobeExitEvent($player, $session->zone))->call();
	}

	/**
	 * Ends every visit, for a reload or shutdown.
	 */
	public function endAll() : void{
		foreach($this->sessions as $player => $_){
			$this->end($player, true);
		}
	}

	/**
	 * Keeps the on-screen prompt showing.
	 */
	public function tick(int $tick) : void{
		foreach($this->sessions as $player => $session){
			if($session->phase === WardrobeSession::PHASE_ACTIVE){
				$this->tickSession($player, $session, $tick);
			}
		}
	}

	private function tickSession(Player $player, WardrobeSession $session, int $tick) : void{
		if($tick >= $session->nextPromptTick){
			$session->nextPromptTick = $tick + self::PROMPT_INTERVAL_TICKS;
			$player->sendTip(TF::GOLD . "Punch to open the catalogue\n" . TF::RED . "Sneak to leave the wardrobe");
		}
	}

	private function moveTo(Player $player, WardrobeSpot $spot) : void{
		$this->movingPlayer = true;
		try{
			$player->teleport($spot->toLocation($player->getWorld()));
		}finally{
			$this->movingPlayer = false;
		}
	}

	private function hideFrom(Player $player, WardrobeSession $session, Player $viewer) : void{
		//leave alone anyone another plugin (a vanish plugin, say) already hid this player from
		if($viewer !== $player && $viewer->canSee($player)){
			$viewer->hidePlayer($player);
			$session->hiddenFrom[spl_object_id($viewer)] = $viewer;
		}
	}

	private function fade(Player $player) : void{
		$seconds = self::FADE_TICKS / 20;
		$player->getNetworkSession()->sendDataPacket(CameraInstructionPacket::create(
			null,
			null,
			new CameraFadeInstruction(new CameraFadeInstructionTime($seconds, 0.2, $seconds), new CameraFadeInstructionColor(0.0, 0.0, 0.0)),
			null, null, null, null, null, null
		));
	}

	private function openMenu(Player $player) : void{
		$session = $this->sessions[$player] ?? null;
		if($session === null || $session->phase !== WardrobeSession::PHASE_ACTIVE){
			return;
		}
		$tick = ($this->currentTick)();
		if($tick >= $session->nextMenuTick){
			$session->nextMenuTick = $tick + self::MENU_DEBOUNCE_TICKS;
			($this->openMenu)($player);
		}
	}

	/**
	 * Whether a player's action should be cancelled because they are in a wardrobe; a punch or
	 * tap opens the menu instead.
	 */
	private function intercept(Player $player, bool $opensMenu) : bool{
		if(!isset($this->sessions[$player])){
			return false;
		}
		if($opensMenu){
			$this->openMenu($player);
		}
		return true;
	}

	/**
	 * The client only knows camera presets the server sends. Axolotl-PM sends none.
	 *
	 * @priority MONITOR
	 */
	public function onJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();
		$player->getNetworkSession()->sendDataPacket(CameraPresetsPacket::create([new CameraPreset(
			name: "minecraft:free",
			parent: "",
			xPosition: 0.0,
			yPosition: 0.0,
			zPosition: 0.0,
			pitch: 0.0,
			yaw: 0.0,
			rotationSpeed: null,
			snapToTarget: null,
			horizontalRotationLimit: null,
			verticalRotationLimit: null,
			continueTargeting: null,
			blockListeningRadius: null,
			viewOffset: null,
			entityOffset: null,
			radius: null,
			yawLimitMin: null,
			yawLimitMax: null,
			audioListenerType: null,
			playerEffects: null,
			aimAssist: null,
			controlScheme: null,
			applyInheritedStartingRotation: false,
			startingRotation: null
		)]));
		foreach($this->sessions as $inside => $session){
			if($session->restricted){
				$this->hideFrom($inside, $session, $player);
			}
		}
	}

	/**
	 * Runs before cosmetic state is closed at MONITOR, and before the player's position is saved.
	 *
	 * @priority HIGH
	 */
	public function onQuit(PlayerQuitEvent $event) : void{
		$this->end($event->getPlayer(), true);
	}

	/**
	 * @priority MONITOR
	 */
	public function onDeath(PlayerDeathEvent $event) : void{
		$this->end($event->getPlayer(), false);
	}

	/**
	 * Another plugin moved the player: follow its decision rather than pull them back.
	 *
	 * @priority MONITOR
	 */
	public function onTeleport(EntityTeleportEvent $event) : void{
		$entity = $event->getEntity();
		if(!$this->movingPlayer && $entity instanceof Player){
			$this->end($entity, false);
		}
	}

	/**
	 * @priority HIGHEST
	 */
	public function onSneak(PlayerToggleSneakEvent $event) : void{
		if($event->isSneaking()){
			$this->leave($event->getPlayer());
		}
	}

	/**
	 * /kill stays usable as a way out; nothing else can hurt a player in a wardrobe.
	 *
	 * @priority HIGHEST
	 */
	public function onDamage(EntityDamageEvent $event) : void{
		$entity = $event->getEntity();
		if($entity instanceof Player && $event->getCause() !== EntityDamageEvent::CAUSE_SUICIDE && $this->intercept($entity, false)){
			$event->cancel();
		}
		if($event instanceof EntityDamageByEntityEvent && ($damager = $event->getDamager()) instanceof Player && $this->intercept($damager, true)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onMissSwing(PlayerMissSwingEvent $event) : void{
		if($this->intercept($event->getPlayer(), true)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onInteract(PlayerInteractEvent $event) : void{
		if($this->intercept($event->getPlayer(), true)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onItemUse(PlayerItemUseEvent $event) : void{
		if($this->intercept($event->getPlayer(), true)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onEntityInteract(PlayerEntityInteractEvent $event) : void{
		if($this->intercept($event->getPlayer(), true)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onBlockBreak(BlockBreakEvent $event) : void{
		if($this->intercept($event->getPlayer(), false)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onBlockPlace(BlockPlaceEvent $event) : void{
		if($this->intercept($event->getPlayer(), false)){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onDrop(PlayerDropItemEvent $event) : void{
		if($this->intercept($event->getPlayer(), false)){
			$event->cancel();
		}
	}
}
