<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

/**
 * Called before a player equips a cosmetic, after access checks passed.
 *
 * Cancelling keeps the player's current cosmetic in that slot. If another cosmetic occupied the
 * slot it is replaced without a separate unequip event; see getReplaced().
 */
final class CosmeticEquipEvent extends CosmeticEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private Player $player,
		CosmeticDefinition $definition,
		private ?CosmeticDefinition $replaced,
		private ChangeCause $cause
	){
		parent::__construct($definition);
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	public function getReplaced() : ?CosmeticDefinition{
		return $this->replaced;
	}

	public function getCause() : ChangeCause{
		return $this->cause;
	}
}
