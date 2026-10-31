<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\player\Player;

/**
 * Hats ride on the helmet bone (a child of the head), so they follow head rotation. On persona
 * skins the client bakes hair, headwear and face accessories into that bone.
 */
final class HatRenderer extends SkinAttachedRenderer{

	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::HAT;
	}

	protected function getSlotBone() : string{
		return "helmet";
	}

	protected function getFallbackBones() : array{
		return ["head"];
	}

	protected function isSuppressed(Player $player, CosmeticDefinition $definition) : bool{
		return $this->option($definition, "hide-with-helmet", true) && !$player->getArmorInventory()->getHelmet()->isNull();
	}
}
