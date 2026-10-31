<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\gadgets;

use ninjaknights\cosmetics\builtin\BuiltinEntry;
use ninjaknights\cosmetics\cosmetic\Rarity;
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\world\particle\BubbleParticle;
use pocketmine\world\particle\CriticalParticle;
use pocketmine\world\particle\DustParticle;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\particle\Particle;

/** Instant, visual-only effects: no entities, projectiles, block changes or movement. */
enum GadgetType : string{
	case CONFETTI_CANNON = "confetti_cannon";
	case BUBBLE_WAND = "bubble_wand";
	case STARBURST = "starburst";
	case FLOWER_SHOWER = "flower_shower";

	public function cooldownSeconds() : int{
		return match($this){
			self::CONFETTI_CANNON => 5,
			self::BUBBLE_WAND => 3,
			self::STARBURST => 5,
			self::FLOWER_SHOWER => 4
		};
	}

	/** @return list<BuiltinEntry> */
	public static function entries() : array{
		return [
			new BuiltinEntry(self::CONFETTI_CANNON->value, "Confetti Cannon", "Use to spray colourful confetti.", Rarity::EPIC),
			new BuiltinEntry(self::BUBBLE_WAND->value, "Bubble Wand", "Use to blow a swirl of bubbles.", Rarity::COMMON),
			new BuiltinEntry(self::STARBURST->value, "Starburst", "Use to surround yourself with sparkling stars.", Rarity::RARE),
			new BuiltinEntry(self::FLOWER_SHOWER->value, "Flower Shower", "Use to sprinkle green sparkles overhead.", Rarity::UNCOMMON)
		];
	}

	/** @return list<array{Vector3, Particle}> Positions relative to the player's feet. */
	public function samples(Vector3 $direction) : array{
		$samples = [];
		for($i = 0; $i < 18; ++$i){
			$angle = $i * 2.0 * M_PI / 9.0;
			$offset = match($this){
				self::CONFETTI_CANNON, self::BUBBLE_WAND => $direction->multiply(0.5 + $i / 6.0)->add(cos($angle) * 0.3, 1.4 + sin($angle) * 0.3, sin($angle) * 0.3),
				self::STARBURST => new Vector3(cos($angle) * (0.7 + $i / 18.0), 0.6 + ($i % 3) * 0.5, sin($angle) * (0.7 + $i / 18.0)),
				self::FLOWER_SHOWER => new Vector3(cos($angle) * 0.9, 2.0 + ($i % 4) * 0.25, sin($angle) * 0.9)
			};
			$particle = match($this){
				self::CONFETTI_CANNON => new DustParticle(new Color(($i * 71) % 256, ($i * 113 + 80) % 256, ($i * 47 + 160) % 256)),
				self::BUBBLE_WAND => new BubbleParticle(),
				self::STARBURST => new CriticalParticle(),
				self::FLOWER_SHOWER => new HappyVillagerParticle()
			};
			$samples[] = [$offset, $particle];
		}
		return $samples;
	}
}
