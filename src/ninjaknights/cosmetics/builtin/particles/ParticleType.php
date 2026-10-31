<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\particles;

use ninjaknights\cosmetics\builtin\BuiltinEntry;
use ninjaknights\cosmetics\cosmetic\Rarity;
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\world\particle\DustParticle;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\particle\Particle;

/** The available effects and their shapes are fixed in PHP, never loaded from YAML. */
enum ParticleType : string{
	case VORTEX = "vortex";
	case TWINS = "twins";
	case THICK = "thick";
	case SPIRAL = "spiral";
	case SPIN = "spin";
	case SPHERE = "sphere";
	case RINGS = "rings";
	case QUADHELIX = "quadhelix";
	case PULSE = "pulse";
	case POPPER = "popper";
	case ORBIT = "orbit";
	case NORMAL = "normal";
	case MOVE = "move";
	case HALO = "halo";
	case FEET = "feet";
	case CUBE = "cube";
	case COMPANION = "companion";
	case CELEBRATION = "celebration";
	case BEAM = "beam";

	public function isTrail() : bool{
		return $this === self::MOVE;
	}

	public function getUpdateInterval(float $frequency) : int{
		return max(1, (int) round(($this === self::VORTEX || $this === self::TWINS ? 1.0 : 4.0) / $frequency));
	}

	/** @return list<BuiltinEntry> */
	public static function entries() : array{
		return [
			new BuiltinEntry(self::VORTEX->value, "Vortex", "Four slow flames while still; a flame trail while moving.", Rarity::EPIC),
			new BuiltinEntry(self::TWINS->value, "Twins", "Two opposite particles circle you while rising and falling.", Rarity::RARE),
			new BuiltinEntry(self::THICK->value, "Thick", "A dense cloud of sparkles surrounds you.", Rarity::UNCOMMON),
			new BuiltinEntry(self::SPIRAL->value, "Spiral", "A helix winds from your feet upwards.", Rarity::RARE),
			new BuiltinEntry(self::SPIN->value, "Spin", "Three sparkling spokes spin around you.", Rarity::RARE),
			new BuiltinEntry(self::SPHERE->value, "Sphere", "A rotating shell of particles surrounds you.", Rarity::EPIC),
			new BuiltinEntry(self::RINGS->value, "Rings", "Three rings shimmer around your body.", Rarity::RARE),
			new BuiltinEntry(self::QUADHELIX->value, "Quadhelix", "Four twisting strands surround you.", Rarity::LEGENDARY),
			new BuiltinEntry(self::PULSE->value, "Pulse", "A ring repeatedly expands from your feet.", Rarity::RARE),
			new BuiltinEntry(self::POPPER->value, "Popper", "Sparkling bubbles of light burst outwards.", Rarity::UNCOMMON),
			new BuiltinEntry(self::ORBIT->value, "Orbit", "A waving band of lights orbits your body.", Rarity::RARE),
			new BuiltinEntry(self::NORMAL->value, "Normal", "A gentle scattering of sparkles.", Rarity::COMMON),
			new BuiltinEntry(self::MOVE->value, "Move", "Sparkles appear at your feet while moving.", Rarity::COMMON),
			new BuiltinEntry(self::HALO->value, "Halo", "A golden halo glows above your head.", Rarity::UNCOMMON),
			new BuiltinEntry(self::FEET->value, "Feet", "A circle of lights surrounds your feet.", Rarity::COMMON),
			new BuiltinEntry(self::CUBE->value, "Cube", "A sparkling cube frames your body.", Rarity::EPIC),
			new BuiltinEntry(self::COMPANION->value, "Companion", "A tiny sparkling light floats beside you.", Rarity::RARE),
			new BuiltinEntry(self::CELEBRATION->value, "Celebration", "A colourful fountain of confetti.", Rarity::EPIC),
			new BuiltinEntry(self::BEAM->value, "Beam", "A slender column of light rises around you.", Rarity::RARE)
		];
	}

	/**
	 * Samples relative to the player's feet. Auras/shapes become small trails while moving.
	 * @return list<array{Vector3, Particle}>
	 */
	public function samples(int $tick, bool $moving) : array{
		if(!$this->isTrail() && $moving){
			return [[new Vector3(0.0, 0.1, 0.0), $this->createParticle($tick, 0)]];
		}
		if($this->isTrail() && !$moving){
			return [];
		}
		$result = [];
		foreach(ParticleShapes::offsets($this, $tick) as $i => $offset){
			$result[] = [$offset, $this->createParticle($tick, $i)];
		}
		return $result;
	}

	/** Use the same particle type and colour for the idle animation and its movement trail. */
	private function createParticle(int $tick, int $index) : Particle{
		return match($this){
			self::VORTEX => new FlameParticle(),
			self::TWINS => new DustParticle(self::twinsColor($tick)),
			default => new DustParticle(match($this){
				self::HALO, self::FEET, self::BEAM => new Color(255, 210, 90),
				self::SPIRAL, self::QUADHELIX => new Color(185, 110, 255),
				self::CELEBRATION => new Color(($index * 71 + $tick) % 256, ($index * 113 + 80) % 256, ($index * 47 + 160) % 256),
				default => new Color(100, 200, 255)
			})
		};
	}

	/** A shared ten-second rainbow cycle colours the two trails equally. */
	private static function twinsColor(int $tick) : Color{
		$phase = ($tick % 200) / 200.0 * 6.0;
		$segment = (int) $phase;
		$rising = (int) round(($phase - $segment) * 255.0);
		$falling = 255 - $rising;
		return match($segment){
			0 => new Color($rising, 0, 255),
			1 => new Color(255, 0, $falling),
			2 => new Color(255, $rising, 0),
			3 => new Color($falling, 255, 0),
			4 => new Color(0, 255, $rising),
			default => new Color(0, $falling, 255)
		};
	}
}
