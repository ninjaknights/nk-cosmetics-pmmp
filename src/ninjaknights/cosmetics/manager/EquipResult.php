<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\manager;

enum EquipResult : string{

	case SUCCESS = "success";
	case ALREADY_EQUIPPED = "already_equipped";
	case UNKNOWN_COSMETIC = "unknown_cosmetic";
	case DISABLED = "disabled";
	case LOCKED = "locked";
	case WORLD_DISABLED = "world_disabled";
	case CANCELLED = "cancelled";
	case NOT_LOADED = "not_loaded";

	public function getMessage() : string{
		return match($this){
			self::SUCCESS => "Equipped.",
			self::ALREADY_EQUIPPED => "You already have that equipped.",
			self::UNKNOWN_COSMETIC => "That cosmetic does not exist.",
			self::DISABLED => "That cosmetic is currently disabled.",
			self::LOCKED => "You have not unlocked that cosmetic.",
			self::WORLD_DISABLED => "Cosmetics are disabled in this world.",
			self::CANCELLED => "You cannot equip that right now.",
			self::NOT_LOADED => "Your cosmetics are still loading, try again in a moment."
		};
	}
}
