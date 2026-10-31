<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\player\Player;

/**
 * Shows the cosmetic equipped in one category. The cosmetic manager owns equipment state and
 * calls show()/hide(); renderers only deal with visuals.
 */
interface CosmeticRendererInterface{

	public function getCategory() : CosmeticCategory;

	/**
	 * Displays $definition for $player, replacing whatever this renderer showed before.
	 */
	public function show(Player $player, CosmeticDefinition $definition) : void;

	/**
	 * Removes this renderer's visuals for $player. Must be safe to call when nothing is shown.
	 */
	public function hide(Player $player) : void;

	/**
	 * Drops any cached data derived from definitions or resource-pack assets (after a reload).
	 */
	public function clearCache() : void;
}
