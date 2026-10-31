<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

/**
 * Called before a cosmetic is unequipped.
 *
 * Cancelling keeps it equipped, except when getCause()->isForced() (the player lost access or the
 * world forbids it): forced removals always happen and the cancelled flag is ignored.
 */
final class CosmeticUnequipEvent extends CosmeticEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private Player $player,
		CosmeticDefinition $definition,
		private ChangeCause $cause
	){
		parent::__construct($definition);
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	public function getCause() : ChangeCause{
		return $this->cause;
	}
}
