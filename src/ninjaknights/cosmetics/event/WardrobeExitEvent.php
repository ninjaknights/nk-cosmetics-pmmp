<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\wardrobe\WardrobeZone;
use pocketmine\event\player\PlayerEvent;
use pocketmine\player\Player;

/**
 * Called when a player has left a wardrobe zone for any reason, including sneaking, quitting,
 * dying, being teleported by another plugin, a reload and shutdown.
 */
final class WardrobeExitEvent extends PlayerEvent{

	public function __construct(
		Player $player,
		private WardrobeZone $zone
	){
		$this->player = $player;
	}

	public function getZone() : WardrobeZone{
		return $this->zone;
	}
}
