<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

/**
 * Called before a cosmetic is permanently granted to a player (online or offline).
 * Cancelling prevents the grant.
 */
final class CosmeticUnlockEvent extends CosmeticEvent implements Cancellable{
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

	/**
	 * The player, if online.
	 */
	public function getOnlinePlayer() : ?Player{
		return $this->onlinePlayer;
	}

	public function getCause() : ChangeCause{
		return $this->cause;
	}
}
