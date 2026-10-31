<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\particles;

use ninjaknights\cosmetics\builtin\BuiltinEntry;
use ninjaknights\cosmetics\cosmetic\Rarity;
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\world\particle\DustParticle;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\particle\InstantEnchantParticle;
use pocketmine\world\particle\Particle;
use pocketmine\world\particle\SnowballPoofParticle;

/** The available effects and their shapes are fixed in PHP, never loaded from YAML. */
enum ParticleType : string{
	case HEART_AURA = "heart_aura";
	case EMERALD_HALO = "emerald_halo";
	case ENCHANT_SPIRAL = "enchant_spiral";
	case FLAME_TRAIL = "flame_trail";
	case RAINBOW_TRAIL = "rainbow_trail";
	case SNOW_TRAIL = "snow_trail";

	public function isTrail() : bool{
		return $this === self::FLAME_TRAIL || $this === self::RAINBOW_TRAIL || $this === self::SNOW_TRAIL;
	}

	/** @return list<BuiltinEntry> */
	public static function entries() : array{
		return [
			new BuiltinEntry(self::HEART_AURA->value, "Heart Aura", "Hearts circle around you.", Rarity::RARE),
			new BuiltinEntry(self::EMERALD_HALO->value, "Emerald Halo", "Green sparkles orbit above your head.", Rarity::UNCOMMON),
			new BuiltinEntry(self::ENCHANT_SPIRAL->value, "Enchant Spiral", "Enchanting sparkles spiral around you.", Rarity::EPIC),
			new BuiltinEntry(self::FLAME_TRAIL->value, "Flame Trail", "Harmless flames follow your footsteps.", Rarity::RARE),
			new BuiltinEntry(self::RAINBOW_TRAIL->value, "Rainbow Trail", "A changing rainbow follows your footsteps.", Rarity::LEGENDARY),
			new BuiltinEntry(self::SNOW_TRAIL->value, "Snow Trail", "Snow puffs follow your footsteps.", Rarity::COMMON)
		];
	}

	/**
	 * Samples relative to the player's feet. Trails emit only while moving.
	 * @return list<array{Vector3, Particle}>
	 */
	public function samples(int $tick, bool $moving) : array{
		if($this->isTrail() && !$moving){
			return [];
		}
		$result = [];
		for($i = 0; $i < 3; ++$i){
			$angle = $tick * 0.08 + $i * 2.0 * M_PI / 3.0;
			$offset = match($this){
				self::HEART_AURA => new Vector3(cos($angle) * 0.65, 1.0 + sin($angle * 2.0) * 0.25, sin($angle) * 0.65),
				self::EMERALD_HALO => new Vector3(cos($angle) * 0.5, 2.15, sin($angle) * 0.5),
				self::ENCHANT_SPIRAL => new Vector3(cos($angle) * 0.65, (($tick + $i * 20) % 60) / 30.0, sin($angle) * 0.65),
				default => new Vector3(cos($angle) * 0.2, 0.1, sin($angle) * 0.2)
			};
			$particle = match($this){
				self::HEART_AURA => new HeartParticle(),
				self::EMERALD_HALO => new HappyVillagerParticle(),
				self::ENCHANT_SPIRAL => new InstantEnchantParticle(new Color(170, 100, 255)),
				self::FLAME_TRAIL => new FlameParticle(),
				self::SNOW_TRAIL => new SnowballPoofParticle(),
				self::RAINBOW_TRAIL => new DustParticle(new Color(
					(int) round(127.5 + 127.5 * sin($angle)),
					(int) round(127.5 + 127.5 * sin($angle + 2.0 * M_PI / 3.0)),
					(int) round(127.5 + 127.5 * sin($angle + 4.0 * M_PI / 3.0))
				))
			};
			$result[] = [$offset, $particle];
		}
		return $result;
	}
}
