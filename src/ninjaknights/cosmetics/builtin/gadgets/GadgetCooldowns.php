<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\gadgets;

/** Shared across gadget types, so switching or hiding gadgets cannot reset the cooldown. */
final class GadgetCooldowns{
	/** @var array<int, int> */
	private array $readyAt = [];

	public function remaining(int $playerId, int $tick) : int{
		return max(0, ($this->readyAt[$playerId] ?? 0) - $tick);
	}

	public function start(int $playerId, int $tick, int $seconds) : void{
		$this->readyAt[$playerId] = $tick + $seconds * 20;
	}

	public function forget(int $playerId) : void{
		unset($this->readyAt[$playerId]);
	}
}
