<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\manager;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\event\ChangeCause;
use ninjaknights\cosmetics\event\CosmeticEquipEvent;
use ninjaknights\cosmetics\event\CosmeticRevokeEvent;
use ninjaknights\cosmetics\event\CosmeticUnequipEvent;
use ninjaknights\cosmetics\event\CosmeticUnlockEvent;
use ninjaknights\cosmetics\PluginSettings;
use ninjaknights\cosmetics\registry\CosmeticRegistry;
use ninjaknights\cosmetics\renderer\CosmeticRendererInterface;
use ninjaknights\cosmetics\storage\PlayerRecord;
use ninjaknights\cosmetics\storage\StorageException;
use pocketmine\player\Player;
use pocketmine\Server;
use function in_array;
use function time;

/**
 * Owns equipment and unlock state. Every equip, unequip, unlock and revoke goes through here so
 * access checks, events, persistence and rendering stay consistent.
 */
final class CosmeticManager{

	/** @var array<string, CosmeticRendererInterface> category value => renderer */
	private array $renderers = [];
	/** @var list<\Closure(Player) : void> */
	private array $viewerHooks = [];

	public function __construct(
		private CosmeticRegistry $registry,
		private SessionManager $sessions,
		private PluginSettings $settings,
		private \Logger $logger
	){}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
	}

	public function registerRenderer(CosmeticRendererInterface $renderer) : void{
		$this->renderers[$renderer->getCategory()->value] = $renderer;
	}

	public function getRenderer(CosmeticCategory $category) : ?CosmeticRendererInterface{
		return $this->renderers[$category->value] ?? null;
	}

	/**
	 * @return array<string, CosmeticRendererInterface>
	 */
	public function getRenderers() : array{
		return $this->renderers;
	}

	public function getRegistry() : CosmeticRegistry{
		return $this->registry;
	}

	public function getSessions() : SessionManager{
		return $this->sessions;
	}

	public function isCategoryAvailable(CosmeticCategory $category) : bool{
		return $this->settings->isCategoryEnabled($category) && isset($this->renderers[$category->value]);
	}

	public function isWorldAllowed(Player $player) : bool{
		return !in_array($player->getWorld()->getFolderName(), $this->settings->getDisabledWorlds(), true);
	}

	/**
	 * Whether the player may use a cosmetic: enabled, and unlocked by default, by permission or by a grant.
	 */
	public function canUse(Player $player, CosmeticDefinition $definition) : bool{
		if(!$definition->enabled || !$this->isCategoryAvailable($definition->category)){
			return false;
		}
		return $this->isUnlocked($player, $definition);
	}

	public function isUnlocked(Player $player, CosmeticDefinition $definition) : bool{
		if($definition->defaultUnlocked){
			return true;
		}
		if($definition->permissionUnlocks && $player->hasPermission($definition->permission)){
			return true;
		}
		return $this->sessions->get($player)?->hasUnlock($definition->id) ?? false;
	}

	public function getEquipped(Player $player, CosmeticCategory $category) : ?CosmeticDefinition{
		$id = $this->sessions->get($player)?->getEquippedId($category);
		return $id !== null ? $this->registry->get($id) : null;
	}

	/**
	 * @return array<string, CosmeticDefinition> category value => definition
	 */
	public function getAllEquipped(Player $player) : array{
		$result = [];
		foreach(CosmeticCategory::ordered() as $category){
			$definition = $this->getEquipped($player, $category);
			if($definition !== null){
				$result[$category->value] = $definition;
			}
		}
		return $result;
	}

	public function equip(Player $player, string $id, ChangeCause $cause) : EquipResult{
		$session = $this->sessions->get($player);
		if($session === null){
			return EquipResult::NOT_LOADED;
		}
		$definition = $this->registry->get($id);
		if($definition === null){
			return EquipResult::UNKNOWN_COSMETIC;
		}
		if(!$definition->enabled || !$this->isCategoryAvailable($definition->category)){
			return EquipResult::DISABLED;
		}
		if(!$this->isUnlocked($player, $definition)){
			return EquipResult::LOCKED;
		}
		if(!$this->isWorldAllowed($player)){
			return EquipResult::WORLD_DISABLED;
		}
		$currentId = $session->getEquippedId($definition->category);
		if($currentId === $definition->id){
			return EquipResult::ALREADY_EQUIPPED;
		}
		$replaced = $currentId !== null ? $this->registry->get($currentId) : null;

		$event = new CosmeticEquipEvent($player, $definition, $replaced, $cause);
		$event->call();
		if($event->isCancelled()){
			return EquipResult::CANCELLED;
		}

		$session->setEquipped($definition->category, $definition->id);
		if($this->isVisible($player)){
			$this->renderers[$definition->category->value]->show($player, $definition);
		}
		return EquipResult::SUCCESS;
	}

	/**
	 * @return bool whether something was unequipped
	 */
	public function unequip(Player $player, CosmeticCategory $category, ChangeCause $cause) : bool{
		$session = $this->sessions->get($player);
		$currentId = $session?->getEquippedId($category);
		if($session === null || $currentId === null){
			return false;
		}
		$definition = $this->registry->get($currentId);
		if($definition !== null){
			$event = new CosmeticUnequipEvent($player, $definition, $cause);
			$event->call();
			if($event->isCancelled() && !$cause->isForced()){
				return false;
			}
		}
		$session->setEquipped($category, null);
		($this->renderers[$category->value] ?? null)?->hide($player);
		return true;
	}

	/**
	 * @return int number of cosmetics unequipped
	 */
	public function clear(Player $player, ChangeCause $cause) : int{
		$count = 0;
		foreach(CosmeticCategory::ordered() as $category){
			if($this->unequip($player, $category, $cause)){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Shows equipped cosmetics after joining, dropping any the player can no longer use.
	 */
	public function restore(Player $player) : void{
		$session = $this->sessions->get($player);
		if($session === null){
			return;
		}
		foreach(CosmeticCategory::ordered() as $category){
			$id = $session->getEquippedId($category);
			if($id === null){
				continue;
			}
			$definition = $this->registry->get($id);
			if($definition === null || !$this->canUse($player, $definition)){
				$session->setEquipped($category, null);
				$this->logger->debug("Dropped equipped $id from {$player->getName()}: no longer available");
			}
		}
		$this->refreshVisibility($player);
	}

	/**
	 * Re-checks equipped cosmetics, e.g. after a reload, a permission change or a revoke.
	 */
	public function revalidate(Player $player) : void{
		$session = $this->sessions->get($player);
		if($session === null){
			return;
		}
		foreach(CosmeticCategory::ordered() as $category){
			$id = $session->getEquippedId($category);
			if($id === null){
				continue;
			}
			$definition = $this->registry->get($id);
			if($definition === null || !$this->canUse($player, $definition)){
				$this->unequip($player, $category, ChangeCause::ACCESS_LOST);
			}
		}
		$this->refreshVisibility($player);
	}

	/**
	 * Whether cosmetics are currently drawn for this player (own preference and world rules).
	 */
	public function isVisible(Player $player) : bool{
		$session = $this->sessions->get($player);
		return $session !== null && $session->getPreference(PlayerRecord::PREF_SHOW_OWN) && $this->isWorldAllowed($player);
	}

	/**
	 * Shows or hides every equipped cosmetic according to isVisible(). Equipment is unchanged.
	 */
	public function refreshVisibility(Player $player) : void{
		$visible = $this->isVisible($player);
		foreach($this->renderers as $categoryValue => $renderer){
			$definition = $this->getEquipped($player, CosmeticCategory::from($categoryValue));
			if($visible && $definition !== null && $this->canUse($player, $definition)){
				$renderer->show($player, $definition);
			}else{
				$renderer->hide($player);
			}
		}
	}

	public function setShowOwn(Player $player, bool $show) : void{
		$this->sessions->get($player)?->setPreference(PlayerRecord::PREF_SHOW_OWN, $show);
		$this->refreshVisibility($player);
	}

	/**
	 * Whether $viewer wants to see other players' cosmetics.
	 */
	public function showsOthers(Player $viewer) : bool{
		return $this->sessions->get($viewer)?->getPreference(PlayerRecord::PREF_SHOW_OTHERS) ?? true;
	}

	/**
	 * Registers a callback run when a viewer toggles seeing other players' cosmetics, so
	 * renderers can resend or despawn what that viewer sees.
	 *
	 * @param \Closure(Player) : void $hook
	 */
	public function onViewerPreferenceChange(\Closure $hook) : void{
		$this->viewerHooks[] = $hook;
	}

	public function setShowOthers(Player $viewer, bool $show) : void{
		$session = $this->sessions->get($viewer);
		if($session === null || $session->getPreference(PlayerRecord::PREF_SHOW_OTHERS) === $show){
			return;
		}
		$session->setPreference(PlayerRecord::PREF_SHOW_OTHERS, $show);
		foreach($this->viewerHooks as $hook){
			$hook($viewer);
		}
	}

	/**
	 * Hides cosmetics without unequipping them.
	 *
	 * @param bool $includeSkin also hide skin-attached cosmetics (false on death, where only entities need removing)
	 */
	public function hidePlayer(Player $player, bool $includeSkin = true) : void{
		foreach($this->renderers as $renderer){
			if($includeSkin || !$renderer->getCategory()->isSkinAttached()){
				$renderer->hide($player);
			}
		}
	}

	/**
	 * Grants a cosmetic. Works for offline players who have joined before.
	 *
	 * @return bool false if the player is unknown, already had it, or a listener cancelled
	 */
	public function unlock(string $playerName, CosmeticDefinition $definition, ChangeCause $cause) : bool{
		$online = Server::getInstance()->getPlayerExact($playerName);
		$event = new CosmeticUnlockEvent($online?->getName() ?? $playerName, $online, $definition, $cause);
		return $this->modifyRecord($playerName, $online, function(PlayerRecord $record) use ($definition, $event) : bool{
			if(isset($record->unlocks[$definition->id])){
				return false;
			}
			$event->call();
			if($event->isCancelled()){
				return false;
			}
			$record->unlocks[$definition->id] = time();
			return true;
		});
	}

	/**
	 * Takes a granted cosmetic away. If the player loses access it is also unequipped.
	 */
	public function revoke(string $playerName, CosmeticDefinition $definition, ChangeCause $cause) : bool{
		$online = Server::getInstance()->getPlayerExact($playerName);
		$event = new CosmeticRevokeEvent($online?->getName() ?? $playerName, $online, $definition, $cause);
		$changed = $this->modifyRecord($playerName, $online, function(PlayerRecord $record) use ($definition, $event) : bool{
			if(!isset($record->unlocks[$definition->id])){
				return false;
			}
			$event->call();
			if($event->isCancelled()){
				return false;
			}
			unset($record->unlocks[$definition->id]);
			//offline players: their equipped slot is re-checked when they next join
			return true;
		});
		if($changed && $online !== null){
			$this->revalidate($online);
		}
		return $changed;
	}

	/**
	 * Unequips everything for a player, online or offline.
	 */
	public function clearOffline(string $playerName) : bool{
		return $this->modifyRecord($playerName, null, function(PlayerRecord $record) : bool{
			$had = $record->equipped !== [];
			$record->equipped = [];
			return $had;
		});
	}

	/**
	 * @param \Closure(PlayerRecord) : bool $modifier returns whether it changed the record
	 */
	private function modifyRecord(string $playerName, ?Player $online, \Closure $modifier) : bool{
		if($online !== null){
			$session = $this->sessions->get($online);
			if($session === null){
				return false;
			}
			$changed = $modifier($session->getRecord());
			if($changed){
				//grants and revokes are saved straight away rather than at the next autosave
				$session->markDirty();
				$this->sessions->save($session);
			}
			return $changed;
		}
		$storage = $this->sessions->getStorage();
		try{
			$key = $storage->findKeyByName($playerName);
			if($key === null){
				return false;
			}
			$record = $storage->load($key, $playerName);
			if(!$modifier($record)){
				return false;
			}
			$storage->save($record);
			return true;
		}catch(StorageException $e){
			$this->logger->error($e->getMessage());
			return false;
		}
	}
}
