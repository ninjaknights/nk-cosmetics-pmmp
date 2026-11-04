<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\wardrobe;

use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function array_is_list;
use function atan2;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_nan;
use function rad2deg;
use function round;

/** A position and facing inside a wardrobe zone's world. */
final class WardrobeSpot{

	public function __construct(
		public readonly float $x,
		public readonly float $y,
		public readonly float $z,
		public readonly float $yaw = 0.0,
		public readonly float $pitch = 0.0
	){}

	public static function fromLocation(Location $location) : self{
		return new self($location->x, $location->y, $location->z, $location->yaw, $location->pitch);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(mixed $value) : self{
		if(!is_array($value) || !array_is_list($value) || (count($value) !== 3 && count($value) !== 5)){
			throw new \InvalidArgumentException("Locations must be [x, y, z] or [x, y, z, yaw, pitch].");
		}
		foreach($value as $number){
			if((!is_int($number) && !is_float($number)) || (is_float($number) && is_nan($number)) || $number < -30000000 || $number > 30000000){
				throw new \InvalidArgumentException("Location values must be numbers between -30000000 and 30000000.");
			}
		}
		return new self((float) $value[0], (float) $value[1], (float) $value[2], (float) ($value[3] ?? 0.0), (float) ($value[4] ?? 0.0));
	}

	/**
	 * This position, level and turned towards $target.
	 */
	public function facing(Vector3 $target) : self{
		$yaw = rad2deg(atan2($target->z - $this->z, $target->x - $this->x)) - 90.0;
		return new self($this->x, $this->y, $this->z, $yaw < 0.0 ? $yaw + 360.0 : $yaw, 0.0);
	}

	/** @return array{float, float, float, float, float} */
	public function toArray() : array{
		return [round($this->x, 2), round($this->y, 2), round($this->z, 2), round($this->yaw, 1), round($this->pitch, 1)];
	}

	public function toVector() : Vector3{
		return new Vector3($this->x, $this->y, $this->z);
	}

	public function toLocation(World $world) : Location{
		return new Location($this->x, $this->y, $this->z, $world, $this->yaw, $this->pitch);
	}
}
