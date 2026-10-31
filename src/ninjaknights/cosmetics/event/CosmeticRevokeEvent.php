<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

/**
 * Called before a granted cosmetic is taken away from a player. Cancelling keeps the grant.
 * If the revoke goes ahead and the player has the cosmetic equipped without another way to
 * access it (permission or default unlock), it is unequipped with ChangeCause::ACCESS_LOST.
 */
final class CosmeticRevokeEvent extends CosmeticEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private string $playerName,
		private ?Player $onlinePlayer,
		CosmeticDefinition $definition,
		private ChangeCause $cause
	){
		parent::__construct($definition);
	}

	public function getPlayerName() : string{
		return $this->playerName;
	}

	public function getOnlinePlayer() : ?Player{
		return $this->onlinePlayer;
	}

	public function getCause() : ChangeCause{
		return $this->cause;
	}
}
