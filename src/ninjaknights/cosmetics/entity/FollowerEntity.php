<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\entity;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use function atan2;
use function rad2deg;

/**
 * Base for cosmetic entities that follow a player (balloons, pets).
 *
 * They are never saved, cannot be hit, pushed or collided with, use controlled movement, and
 * are only sent to viewers accepted by the visibility filter. Movement is computed every few
 * ticks and broadcast by PocketMine as absolute moves, which clients interpolate smoothly.
 */
abstract class FollowerEntity extends Entity implements NeverSavedWithChunkEntity{

	/** Network type used for the instance currently being spawned; see sendSpawnPacket(). */
	private static string $spawningTypeId = "minecraft:armor_stand";

	/** @var \Closure(Player) : bool */
	private \Closure $viewerFilter;
	private int $ticksUntilMove = 0;

	/**
	 * @param \Closure(Player) : bool $viewerFilter whether a player should see this entity
	 */
	public function __construct(
		Location $location,
		protected Player $owner,
		private string $networkTypeId,
		protected int $updateInterval,
		\Closure $viewerFilter
	){
		$this->viewerFilter = $viewerFilter;
		parent::__construct($location, new CompoundTag());
		$this->setCanSaveWithChunk(false);
		$this->setNameTagVisible(false);
		$this->setNameTagAlwaysVisible(false);
		$this->setSilent(true);
		$this->setHasGravity(false);
	}

	/**
	 * The entity type is per instance (one client entity per balloon), while PocketMine reads it
	 * from a static method. The spawn packet is built synchronously on the main thread, so the
	 * static value is swapped for exactly the duration of the parent call.
	 */
	public static function getNetworkTypeId() : string{
		return self::$spawningTypeId;
	}

	protected function sendSpawnPacket(Player $player) : void{
		$previous = self::$spawningTypeId;
		self::$spawningTypeId = $this->networkTypeId;
		try{
			parent::sendSpawnPacket($player);
		}finally{
			self::$spawningTypeId = $previous;
		}
	}

	public function getOwner() : Player{
		return $this->owner;
	}

	public function shouldBeSeenBy(Player $player) : bool{
		return ($this->viewerFilter)($player);
	}

	public function spawnTo(Player $player) : void{
		if($this->shouldBeSeenBy($player)){
			parent::spawnTo($player);
		}
	}

	/**
	 * Where the entity should be now, given the owner's position.
	 */
	abstract protected function computeNextPosition() : Vector3;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.6, 0.6);
	}

	protected function getInitialDragMultiplier() : float{
		return 0.0;
	}

	protected function getInitialGravity() : float{
		return 0.0;
	}

	public function hasMovementUpdate() : bool{
		return false;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function canCollideWith(Entity $entity) : bool{
		return false;
	}

	public function canBeMovedByCurrents() : bool{
		return false;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		$source->cancel();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		parent::entityBaseTick($tickDiff);
		if(!$this->owner->isConnected() || $this->owner->getWorld() !== $this->getWorld()){
			return true;
		}
		$this->ticksUntilMove -= $tickDiff;
		if($this->ticksUntilMove <= 0){
			$this->ticksUntilMove = $this->updateInterval;
			$next = $this->computeNextPosition();
			$delta = $next->subtractVector($this->location);
			$yaw = ($delta->x * $delta->x + $delta->z * $delta->z) > 0.0004 ? rad2deg(atan2(-$delta->x, $delta->z)) : $this->location->yaw;
			$this->applyMovement($next, $yaw);
		}
		return true;
	}

	protected function applyMovement(Vector3 $next, float $yaw) : void{
		$this->setPositionAndRotation($next, $yaw, 0.0);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::NO_AI, true);
	}
}
