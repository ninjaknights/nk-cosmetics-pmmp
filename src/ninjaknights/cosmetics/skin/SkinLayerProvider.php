<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\skin;

use ninjaknights\cosmetics\geometry\CosmeticLayer;
use pocketmine\player\Player;

/**
 * Supplies the cosmetic layer one skin-attached category currently contributes for a player.
 */
interface SkinLayerProvider{

	/**
	 * @return array{CosmeticLayer, string}|null the layer and a fingerprint identifying it for caching, or null if nothing should be drawn right now
	 */
	public function getLayer(Player $player) : ?array;
}
