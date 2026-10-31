<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\entity;

use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use function cos;
use function deg2rad;
use function sin;

/**
 * A client-rendered balloon that floats above and behind its owner, optionally tethered to them
 * with the vanilla lead rendering.
 */
final class BalloonEntity extends FollowerEntity{

	/** Fraction of the remaining distance covered per movement update; lower is floatier. */
	private const SMOOTHING = 0.35;
	/** Beyond this distance (blocks) the balloon jumps straight to its target. */
	private const SNAP_DISTANCE = 10.0;

	/**
	 * @param array{float, float, float} $offset sideways, up, forward offset in blocks relative to the owner's facing
	 * @param \Closure(Player) : bool    $viewerFilter
	 */
	public function __construct(
		Location $location,
		Player $owner,
		string $networkTypeId,
		int $updateInterval,
		\Closure $viewerFilter,
		private float $height,
		private float $distance,
		private array $offset,
		private bool $tether
	){
		parent::__construct($location, $owner, $networkTypeId, $updateInterval, $viewerFilter);
	}

	/**
	 * Resting position for an owner at $location: behind them, raised, shifted to their right.
	 *
	 * @param array{float, float, float} $offset
	 */
	public static function restingPosition(Location $location, float $height, float $distance, array $offset) : Vector3{
		$yaw = deg2rad($location->yaw);
		//facing direction (Bedrock yaw 0 = +Z) and its right-hand side
		$forwardX = -sin($yaw);
		$forwardZ = cos($yaw);
		$rightX = -$forwardZ;
		$rightZ = $forwardX;
		return new Vector3(
			$location->x - $forwardX * $distance + $rightX * $offset[0] + $forwardX * $offset[2],
			$location->y + $height + $offset[1],
			$location->z - $forwardZ * $distance + $rightZ * $offset[0] + $forwardZ * $offset[2]
		);
	}

	protected function computeNextPosition() : Vector3{
		$target = self::restingPosition($this->owner->getLocation(), $this->height, $this->distance, $this->offset);
		$current = $this->location->asVector3();
		if($current->distanceSquared($target) > self::SNAP_DISTANCE ** 2){
			return $target;
		}
		return $current->addVector($target->subtractVector($current)->multiply(self::SMOOTHING));
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		if($this->tether){
			$properties->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, $this->owner->getId());
			$properties->setGenericFlag(EntityMetadataFlags::LEASHED, true);
		}
	}
}
