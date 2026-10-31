<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\entity\BalloonEntity;
use ninjaknights\cosmetics\entity\FollowerEntity;
use pocketmine\entity\Location;
use pocketmine\player\Player;
use function is_array;
use function is_bool;
use function is_float;
use function is_string;

/**
 * Balloons are custom client entities (resource-pack client_entity + geometry + animation)
 * rather than skin geometry: they must lag behind the player and bob independently, which skin
 * bones cannot do, and an entity can carry a lead to the player.
 *
 * Clients that did not accept the resource pack do not know the entity type and see nothing.
 */
final class BalloonRenderer extends FollowerRenderer{

	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::BALLOON;
	}

	protected function createEntity(Player $player, CosmeticDefinition $definition) : ?FollowerEntity{
		$entityId = $definition->getSetting("entity");
		if(!is_string($entityId)){
			return null;
		}
		$height = self::float($definition->getSetting("height"), 2.4);
		$distance = self::float($definition->getSetting("distance"), 1.2);
		$rawOffset = $definition->getSetting("offset");
		$offset = is_array($rawOffset) && isset($rawOffset[0], $rawOffset[1], $rawOffset[2]) ? [(float) $rawOffset[0], (float) $rawOffset[1], (float) $rawOffset[2]] : [0.6, 0.0, 0.0];
		$tether = $definition->getSetting("tether");

		$ownerLocation = $player->getLocation();
		$start = BalloonEntity::restingPosition($ownerLocation, $height, $distance, $offset);
		$entity = new BalloonEntity(
			Location::fromObject($start, $player->getWorld(), $ownerLocation->yaw, 0.0),
			$player,
			$entityId,
			$this->getUpdateInterval(),
			$this->createViewerFilter($player),
			$height,
			$distance,
			$offset,
			is_bool($tether) ? $tether : true
		);
		$entity->setScale(self::float($definition->getSetting("scale"), 1.0));
		return $entity;
	}

	private static function float(mixed $value, float $default) : float{
		return is_float($value) ? $value : $default;
	}
}
