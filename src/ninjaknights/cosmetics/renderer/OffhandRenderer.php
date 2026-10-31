<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\player\Player;

/**
 * Offhand cosmetics ride on the leftitem bone, the offhand attachment point inside the left arm,
 * so they swing with the arm. They are purely visual: the offhand inventory is never touched.
 */
final class OffhandRenderer extends SkinAttachedRenderer{

	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::OFFHAND;
	}

	protected function getSlotBone() : string{
		return "leftitem";
	}

	protected function getFallbackBones() : array{
		return ["leftarm"];
	}

	protected function isSuppressed(Player $player, CosmeticDefinition $definition) : bool{
		return $this->option($definition, "hide-with-offhand-item", true) && !$player->getOffHandInventory()->getItem(0)->isNull();
	}
}
