<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin\pets;

use pocketmine\math\Vector3;

/** Distance-limited steps keep follow speed independent of the configured update interval. */
final class PetMotion{
	public static function step(Vector3 $current, Vector3 $target, int $interval, bool $flying) : Vector3{
		$delta = $target->subtractVector($current);
		if(!$flying){
			$delta->y = 0.0;
		}
		$distance = $delta->length();
		if($distance < 0.15){
			return clone $current;
		}
		return $current->addVector($delta->multiply(min($distance, 0.22 * $interval) / $distance));
	}
}
