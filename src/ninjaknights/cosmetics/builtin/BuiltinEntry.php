<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin;

use ninjaknights\cosmetics\cosmetic\Rarity;

/**
 * Catalog metadata of one built-in pet, particle effect or gadget.
 */
final class BuiltinEntry{

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $description,
		public readonly Rarity $rarity
	){}
}
