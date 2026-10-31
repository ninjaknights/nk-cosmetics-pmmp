<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\particles;

use pocketmine\math\Vector3;

/** Original geometric patterns in blocks, relative to the player's feet. */
final class ParticleShapes{
	private const VORTEX_CYCLE_TICKS = 144;
	private const TWINS_ORBIT_TICKS = 60;
	private const TWINS_HEIGHT_TICKS = 120;

	/** @return list<Vector3> */
	public static function offsets(ParticleType $type, int $tick) : array{
		$count = match($type){
			ParticleType::VORTEX => 4,
			ParticleType::TWINS => 2,
			ParticleType::SPIRAL, ParticleType::HALO, ParticleType::PULSE => 16,
			ParticleType::SPIN, ParticleType::POPPER, ParticleType::ORBIT, ParticleType::FEET, ParticleType::BEAM => 12,
			ParticleType::THICK, ParticleType::CELEBRATION => 18,
			ParticleType::SPHERE, ParticleType::RINGS, ParticleType::QUADHELIX => 24,
			ParticleType::NORMAL => 8,
			ParticleType::MOVE, ParticleType::COMPANION => 6,
			ParticleType::CUBE => 36
		};
		$phase = $tick * 0.08;
		$points = [];
		for($i = 0; $i < $count; ++$i){
			$f = $i / max(1, $count - 1);
			$angle = $phase + 2.0 * M_PI * $i / $count;
			$points[] = match($type){
				ParticleType::VORTEX => self::vortexPoint($tick, $i),
				ParticleType::SPIRAL => self::ring($phase + $f * 4.0 * M_PI, 0.65, 0.1 + $f * 2.2),
				ParticleType::TWINS => self::twinsPoint($tick, $i),
				ParticleType::THICK => self::ring($phase + $i * 2.4, 0.2 + ($i % 4) * 0.14, 0.15 + $f * 1.7),
				ParticleType::SPIN => self::ring($phase + intdiv($i, 4) * 2.0 * M_PI / 3.0, 0.25 + ($i % 4) * 0.23, 1.0),
				ParticleType::SPHERE => self::spherePoint($i, $count, $phase, 1.0),
				ParticleType::RINGS => self::ring($phase + ($i % 8) * M_PI / 4.0, 0.75, 0.2 + intdiv($i, 8) * 0.8),
				ParticleType::QUADHELIX => self::ring($phase + intdiv($i, 6) * M_PI / 2.0 + ($i % 6) * M_PI / 3.0, 0.65, 0.1 + ($i % 6) * 0.4),
				ParticleType::PULSE => self::ring($angle, 0.2 + ($tick % 40) / 40.0, 0.15),
				ParticleType::POPPER => self::spherePoint($i, $count, $phase, 0.15 + ($tick % 24) / 24.0),
				ParticleType::ORBIT => self::ring($angle, 0.9, 1.0 + sin($angle * 2.0) * 0.3),
				ParticleType::NORMAL => self::ring($i * 2.4 + $phase * 0.3, 0.35, 0.2 + $f * 1.5),
				ParticleType::MOVE => self::ring($angle, 0.22, 0.08 + ($i % 2) * 0.08),
				ParticleType::HALO => self::ring($angle, 0.55, 2.1),
				ParticleType::FEET => self::ring($angle, 0.5, 0.08),
				ParticleType::CUBE => self::cubePoint($i),
				ParticleType::COMPANION => self::ring($phase * 0.5, 1.1, 1.3 + sin($phase) * 0.15)->addVector(self::ring($i * M_PI / 3.0 + $phase, 0.12, sin($i * 2.0) * 0.1)),
				ParticleType::CELEBRATION => self::ring($i * 2.4 + $phase, 0.15 + $f, 1.1 + sin($f * M_PI) * 1.3),
				ParticleType::BEAM => self::ring($phase + $i * M_PI, 0.12, 0.1 + $f * 2.5)
			};
		}
		return $points;
	}

	/** Four equally spaced emitters make one slow turn while rising over 7.2 seconds. */
	private static function vortexPoint(int $tick, int $index) : Vector3{
		$progress = ($tick % self::VORTEX_CYCLE_TICKS) / self::VORTEX_CYCLE_TICKS;
		return self::ring($progress * 2.0 * M_PI + $index * M_PI / 2.0, 1.5 * (1.0 - $progress), 0.1 + $progress * 3.3);
	}

	/** Two opposite particles orbit continuously while rising and falling together. */
	private static function twinsPoint(int $tick, int $index) : Vector3{
		$angle = ($tick % self::TWINS_ORBIT_TICKS) / self::TWINS_ORBIT_TICKS * 2.0 * M_PI;
		$heightProgress = ($tick % self::TWINS_HEIGHT_TICKS) / self::TWINS_HEIGHT_TICKS;
		$height = 0.1 + 2.0 * (1.0 - abs(2.0 * $heightProgress - 1.0));
		return self::ring($angle + $index * M_PI, 1.0, $height);
	}

	private static function ring(float $angle, float $radius, float $height) : Vector3{
		return new Vector3(cos($angle) * $radius, $height, sin($angle) * $radius);
	}

	private static function spherePoint(int $i, int $count, float $phase, float $radius) : Vector3{
		$y = 1.0 - 2.0 * ($i + 0.5) / $count;
		$horizontal = sqrt(max(0.0, 1.0 - $y * $y)) * $radius;
		return self::ring($i * M_PI * (3.0 - sqrt(5.0)) + $phase, $horizontal, 1.1 + $y * $radius);
	}

	private static function cubePoint(int $i) : Vector3{
		$edge = intdiv($i, 3);
		$axis = intdiv($edge, 4);
		$a = ($edge % 2) === 0 ? -0.8 : 0.8;
		$b = (intdiv($edge, 2) % 2) === 0 ? -0.8 : 0.8;
		$along = -0.8 + ($i % 3) * 0.8;
		return match($axis){
			0 => new Vector3($along, 1.0 + $a, $b),
			1 => new Vector3($a, 1.0 + $along, $b),
			default => new Vector3($a, 1.0 + $b, $along)
		};
	}
}
