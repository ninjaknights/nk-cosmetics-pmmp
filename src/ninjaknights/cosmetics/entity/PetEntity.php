<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\entity;

use ninjaknights\cosmetics\builtin\pets\PetMotion;
use ninjaknights\cosmetics\builtin\pets\PetType;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;

/** Decorative vanilla mob: no combat AI, drops, persistence or player collision. */
final class PetEntity extends FollowerEntity{
	private Vector3 $lastOwnerPosition;
	private int $idleTicks = 0;
	private int $blockedTicks = 0;
	private bool $sitting = false;
	private float $fallSpeed = 0.0;
	private bool $snapToOwner = false;

	/** @param \Closure(Player) : bool $viewerFilter */
	public function __construct(Location $location, Player $owner, private PetType $type, int $interval, \Closure $viewerFilter){
		$this->lastOwnerPosition = $owner->getPosition()->asVector3();
		parent::__construct($location, $owner, $type->getEntityId(), $interval, $viewerFilter);
		$this->setScale($type->getScale());
		$this->setStepHeight(1.0);
	}

	protected function computeNextPosition() : Vector3{
		$ownerPosition = $this->owner->getPosition();
		if($ownerPosition->distanceSquared($this->lastOwnerPosition) > 0.01){
			$this->idleTicks = 0;
			$this->lastOwnerPosition = $ownerPosition->asVector3();
		}else{
			$this->idleTicks += $this->updateInterval;
		}
		$target = BalloonEntity::restingPosition($this->owner->getLocation(), $this->type->flies() ? 1.2 : 0.0, 1.4, [0.8, 0.0, 0.0]);
		$idle = $this->idleTicks >= 60 && $this->location->distanceSquared($ownerPosition) < 9.0;
		$sitting = $idle && $this->type->canSit() && !$this->type->flies() && $this->onGround;
		if($sitting !== $this->sitting){
			$this->sitting = $sitting;
			$this->networkPropertiesDirty = true;
		}
		if($sitting){
			return $this->location->asVector3();
		}
		if($idle){
			//Small, slow loops for pets without a sitting pose.
			$phase = $this->ticksLived / 60.0;
			$target = $ownerPosition->add(cos($phase) * 1.6, $this->type->flies() ? 1.2 : 0.0, sin($phase) * 1.6);
		}
		if($this->location->distanceSquared($ownerPosition) > 100.0 || $this->blockedTicks >= 60){
			$this->blockedTicks = 0;
			$this->fallSpeed = 0.0;
			$this->snapToOwner = true;
			//The owner's feet are safer than a trailing point that might be inside a wall.
			return $ownerPosition->add(0.0, $this->type->flies() ? 1.2 : 0.0, 0.0);
		}
		return PetMotion::step($this->location->asVector3(), $target, $this->updateInterval, $this->type->flies());
	}

	protected function applyMovement(Vector3 $next, float $yaw) : void{
		$delta = $next->subtractVector($this->location);
		if($this->type->flies() || $this->snapToOwner){
			$this->snapToOwner = false;
			parent::applyMovement($next, $yaw);
			return;
		}
		$before = $this->location->asVector3();
		$this->fallSpeed = $this->onGround ? 0.08 : min(1.0, $this->fallSpeed + 0.08 * $this->updateInterval);
		$this->move($delta->x, -$this->fallSpeed * $this->updateInterval, $delta->z);
		$this->setRotation($yaw, 0.0);
		$wanted = $delta->x ** 2 + $delta->z ** 2;
		$moved = ($this->location->x - $before->x) ** 2 + ($this->location->z - $before->z) ** 2;
		$this->blockedTicks = $wanted > 0.01 && $moved < 0.001 ? $this->blockedTicks + $this->updateInterval : 0;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->type->isBaby());
		$properties->setGenericFlag(EntityMetadataFlags::TAMED, true);
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->sitting);
		$properties->setGenericFlag(EntityMetadataFlags::HAS_COLLISION, false);
	}
}
