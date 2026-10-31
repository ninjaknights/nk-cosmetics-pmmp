<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\cosmetic;

use pocketmine\utils\TextFormat;

enum Rarity : string{

	case COMMON = "common";
	case UNCOMMON = "uncommon";
	case RARE = "rare";
	case EPIC = "epic";
	case LEGENDARY = "legendary";
	case MYTHIC = "mythic";

	public function getColor() : string{
		return match($this){
			self::COMMON => TextFormat::GRAY,
			self::UNCOMMON => TextFormat::GREEN,
			self::RARE => TextFormat::AQUA,
			self::EPIC => TextFormat::LIGHT_PURPLE,
			self::LEGENDARY => TextFormat::GOLD,
			self::MYTHIC => TextFormat::RED
		};
	}

	/**
	 * Sort position, rarest first.
	 */
	public function ordinal() : int{
		return match($this){
			self::MYTHIC => 0,
			self::LEGENDARY => 1,
			self::EPIC => 2,
			self::RARE => 3,
			self::UNCOMMON => 4,
			self::COMMON => 5
		};
	}

	public function getDisplayName() : string{
		return ucfirst($this->value);
	}
}
