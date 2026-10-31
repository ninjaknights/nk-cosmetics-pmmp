<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\format\io\GlobalItemDataHandlers;

/**
 * Back items ride on the bodyarmor bone (a child of the body), where persona back items such as
 * wings and backpacks are baked.
 */
final class BackRenderer extends SkinAttachedRenderer{

	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::BACK;
	}

	protected function getSlotBone() : string{
		return "bodyarmor";
	}

	protected function getFallbackBones() : array{
		return ["body"];
	}

	protected function isSuppressed(Player $player, CosmeticDefinition $definition) : bool{
		return $this->option($definition, "hide-with-elytra", true) && self::isElytra($player->getArmorInventory()->getChestplate());
	}

	private static function isElytra(Item $item) : bool{
		if($item->isNull()){
			return false;
		}
		try{
			return GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName() === ItemTypeNames::ELYTRA;
		}catch(\Exception|SavedDataLoadingException){
			return false;
		}
	}
}
