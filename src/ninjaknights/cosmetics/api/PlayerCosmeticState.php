<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\api;

/**
 * Read-only snapshot returned by NKCosmeticsAPI::getState().
 */
final class PlayerCosmeticState{

	/**
	 * @param array<string, string> $equipped category value => cosmetic id
	 * @param array<string, int>    $granted  cosmetic id => unix time granted (permission and default unlocks are not listed)
	 */
	public function __construct(
		public readonly string $playerName,
		public readonly array $equipped,
		public readonly array $granted,
		public readonly bool $showsOwnCosmetics,
		public readonly bool $seesOtherCosmetics
	){}
}
