<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\pets;

use ninjaknights\cosmetics\builtin\BuiltinEntry;
use ninjaknights\cosmetics\cosmetic\Rarity;

/**
 * The built-in pets: baby versions of vanilla mobs, so they render on every client without a
 * resource pack. Adding a pet means adding a case here.
 */
enum PetType : string{

	case MINI_WOLF = "mini_wolf";
	case MINI_CAT = "mini_cat";
	case MINI_FOX = "mini_fox";
	case MINI_PANDA = "mini_panda";
	case MINI_PIG = "mini_pig";
	case MINI_CHICKEN = "mini_chicken";
	case MINI_RABBIT = "mini_rabbit";
	case MINI_AXOLOTL = "mini_axolotl";
	case MINI_BEE = "mini_bee";
	case MINI_PARROT = "mini_parrot";

	public function getEntityId() : string{
		return match($this){
			self::MINI_WOLF => "minecraft:wolf",
			self::MINI_CAT => "minecraft:cat",
			self::MINI_FOX => "minecraft:fox",
			self::MINI_PANDA => "minecraft:panda",
			self::MINI_PIG => "minecraft:pig",
			self::MINI_CHICKEN => "minecraft:chicken",
			self::MINI_RABBIT => "minecraft:rabbit",
			self::MINI_AXOLOTL => "minecraft:axolotl",
			self::MINI_BEE => "minecraft:bee",
			self::MINI_PARROT => "minecraft:parrot"
		};
	}

	/** Parrots have no baby form; they are scaled down instead. */
	public function isBaby() : bool{
		return $this !== self::MINI_PARROT;
	}

	public function getScale() : float{
		return $this === self::MINI_PARROT ? 0.6 : 1.0;
	}

	public function flies() : bool{
		return $this === self::MINI_BEE || $this === self::MINI_PARROT;
	}

	/** Mobs with a sitting pose the client renders from the SITTING flag. */
	public function canSit() : bool{
		return $this === self::MINI_WOLF || $this === self::MINI_CAT || $this === self::MINI_FOX || $this === self::MINI_PARROT;
	}

	public function getEntry() : BuiltinEntry{
		return match($this){
			self::MINI_WOLF => new BuiltinEntry($this->value, "Mini Wolf", "A loyal wolf pup.", Rarity::RARE),
			self::MINI_CAT => new BuiltinEntry($this->value, "Mini Cat", "A curious kitten.", Rarity::UNCOMMON),
			self::MINI_FOX => new BuiltinEntry($this->value, "Mini Fox", "A sleepy fox kit.", Rarity::EPIC),
			self::MINI_PANDA => new BuiltinEntry($this->value, "Mini Panda", "A clumsy panda cub.", Rarity::LEGENDARY),
			self::MINI_PIG => new BuiltinEntry($this->value, "Mini Pig", "A happy piglet.", Rarity::COMMON),
			self::MINI_CHICKEN => new BuiltinEntry($this->value, "Mini Chicken", "A fluffy chick.", Rarity::COMMON),
			self::MINI_RABBIT => new BuiltinEntry($this->value, "Mini Rabbit", "A bouncy bunny.", Rarity::UNCOMMON),
			self::MINI_AXOLOTL => new BuiltinEntry($this->value, "Mini Axolotl", "A tiny axolotl, happy on land.", Rarity::EPIC),
			self::MINI_BEE => new BuiltinEntry($this->value, "Mini Bee", "A friendly bee that never stings.", Rarity::RARE),
			self::MINI_PARROT => new BuiltinEntry($this->value, "Mini Parrot", "A small parrot that flutters along.", Rarity::LEGENDARY)
		};
	}

	/**
	 * @return list<BuiltinEntry>
	 */
	public static function entries() : array{
		$entries = [];
		foreach(self::cases() as $type){
			$entries[] = $type->getEntry();
		}
		return $entries;
	}
}
