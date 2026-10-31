<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\cosmetic;

/**
 * The seven cosmetic categories. Each category is also an equipment slot: a player can wear one
 * cosmetic per category at a time.
 */
enum CosmeticCategory : string{

	case HAT = "hat";
	case BACK = "back";
	case OFFHAND = "offhand";
	case BALLOON = "balloon";
	case PET = "pet";
	case PARTICLE = "particle";
	case GADGET = "gadget";

	/**
	 * Configurable categories accept new cosmetics from their cosmetics/*.yml file or the API. The others are
	 * fixed catalogs implemented in PHP.
	 */
	public function isConfigurable() : bool{
		return match($this){
			self::HAT, self::BACK, self::OFFHAND, self::BALLOON => true,
			self::PET, self::PARTICLE, self::GADGET => false
		};
	}

	/**
	 * Whether this category is rendered by grafting geometry onto the player's skin.
	 */
	public function isSkinAttached() : bool{
		return match($this){
			self::HAT, self::BACK, self::OFFHAND => true,
			default => false
		};
	}

	public function getDisplayName() : string{
		return match($this){
			self::HAT => "Hats",
			self::BACK => "Back",
			self::OFFHAND => "Offhand",
			self::BALLOON => "Balloons",
			self::PET => "Pets",
			self::PARTICLE => "Particles",
			self::GADGET => "Gadgets"
		};
	}

	public function getSingularName() : string{
		return match($this){
			self::HAT => "Hat",
			self::BACK => "Back accessory",
			self::OFFHAND => "Offhand",
			self::BALLOON => "Balloon",
			self::PET => "Pet",
			self::PARTICLE => "Particle effect",
			self::GADGET => "Gadget"
		};
	}

	/**
	 * Menu order.
	 *
	 * @return list<self>
	 */
	public static function ordered() : array{
		return [self::HAT, self::BACK, self::OFFHAND, self::BALLOON, self::PET, self::PARTICLE, self::GADGET];
	}
}
