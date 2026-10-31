<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\listener;

use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\skin\SkinGeometryManager;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;

/**
 * Player lifecycle: load and restore cosmetics on join, clean up on quit, and keep visuals in
 * sync with deaths, world changes, skin changes and armour/offhand changes.
 */
final class PlayerListener implements Listener{

	public function __construct(
		private Plugin $plugin,
		private CosmeticManager $cosmetics,
		private SkinGeometryManager $skins
	){}

	/**
	 * @priority LOW
	 */
	public function onJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();
		$this->cosmetics->getSessions()->open($player);
		$this->skins->attach($player);

		//a real helmet, elytra or offhand item can hide the matching cosmetic
		$watch = CallbackInventoryListener::onAnyChange(function() use ($player) : void{
			$this->skins->requestUpdate($player);
		});
		$player->getArmorInventory()->getListeners()->add($watch);
		$player->getOffHandInventory()->getListeners()->add($watch);

		$this->cosmetics->restore($player);
	}

	/**
	 * @priority MONITOR
	 */
	public function onQuit(PlayerQuitEvent $event) : void{
		$player = $event->getPlayer();
		$this->cosmetics->hidePlayer($player);
		$this->skins->detach($player);
		$this->cosmetics->getSessions()->close($player);
	}

	/**
	 * @priority MONITOR
	 */
	public function onSkinChange(PlayerChangeSkinEvent $event) : void{
		//the new skin is set after this event; rebuilding later picks it up as the new original
		$this->skins->requestUpdate($event->getPlayer());
	}

	/**
	 * @priority MONITOR
	 */
	public function onDeath(PlayerDeathEvent $event) : void{
		//entity cosmetics (balloons, pets) are removed while dead; skins are untouched
		$this->cosmetics->hidePlayer($event->getPlayer(), false);
	}

	/**
	 * @priority MONITOR
	 */
	public function onRespawn(PlayerRespawnEvent $event) : void{
		$this->later($event->getPlayer());
	}

	/**
	 * @priority MONITOR
	 */
	public function onTeleport(EntityTeleportEvent $event) : void{
		$entity = $event->getEntity();
		if($entity instanceof Player && $event->getFrom()->getWorld() !== $event->getTo()->getWorld()){
			$this->later($entity);
		}
	}

	/**
	 * Re-applies visibility once the player is in place (world rules, entity respawn).
	 */
	private function later(Player $player) : void{
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
			if($player->isConnected() && $player->isAlive()){
				$this->cosmetics->refreshVisibility($player);
			}
		}), 2);
	}
}
