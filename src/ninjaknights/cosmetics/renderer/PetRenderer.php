<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\builtin\pets\PetType;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\entity\FollowerEntity;
use ninjaknights\cosmetics\entity\PetEntity;
use pocketmine\entity\Location;
use pocketmine\player\Player;

final class PetRenderer extends FollowerRenderer{
	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::PET;
	}

	protected function createEntity(Player $player, CosmeticDefinition $definition) : ?FollowerEntity{
		$type = PetType::tryFrom($definition->id);
		if($type === null){
			return null;
		}
		$location = $player->getLocation();
		$start = $location->add(0.0, $type->flies() ? 1.2 : 0.0, 0.0);
		return new PetEntity(Location::fromObject($start, $player->getWorld(), $location->yaw), $player, $type, $this->getUpdateInterval(), $this->createViewerFilter($player));
	}
}
