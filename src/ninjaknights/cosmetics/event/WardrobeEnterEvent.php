<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\wardrobe\WardrobeZone;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\player\PlayerEvent;
use pocketmine\player\Player;

/**
 * Called when a player walks into a wardrobe zone, before anything changes for them.
 * Cancelling leaves the player where they are; they must walk out and back in to try again.
 */
final class WardrobeEnterEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

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
