<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\wardrobe;

use function array_is_list;
use function array_key_exists;
use function count;
use function floor;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function trim;

/**
 * A wardrobe: walking into its cuboid freezes the player on its stand, with a fixed camera on them.
 * Zones can be saved half set up while an owner works through the setup commands, but only a
 * complete, enabled zone reacts to players.
 */
final class WardrobeZone{

	/** @var array{int, int, int}|null */
	public readonly ?array $min;
	/** @var array{int, int, int}|null */
	public readonly ?array $max;

	/**
	 * @param array{int, int, int}|null $pos1
	 * @param array{int, int, int}|null $pos2
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $world,
		public readonly ?array $pos1,
		public readonly ?array $pos2,
		public readonly ?WardrobeSpot $stand,
		public readonly ?WardrobeSpot $camera,
		public readonly ?WardrobeSpot $exit,
		public readonly bool $enabled
	){
		if($pos1 !== null && $pos2 !== null){
			$this->min = [min($pos1[0], $pos2[0]), min($pos1[1], $pos2[1]), min($pos1[2], $pos2[2])];
			$this->max = [max($pos1[0], $pos2[0]), max($pos1[1], $pos2[1]), max($pos1[2], $pos2[2])];
		}else{
			$this->min = null;
			$this->max = null;
		}
	}

	public static function isValidId(string $id) : bool{
		return preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $id) === 1;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(string $id, mixed $data) : self{
		if(!self::isValidId($id)){
			throw new \InvalidArgumentException("Zone names must start with a lowercase letter and contain 1-48 letters, numbers, underscores or hyphens.");
		}
		if(!is_array($data) || !is_string($data["world"] ?? null) || trim($data["world"]) === ""){
			throw new \InvalidArgumentException("Zone '$id' needs a world folder name.");
		}
		$enabled = array_key_exists("enabled", $data) ? $data["enabled"] : false;
		if(!is_bool($enabled)){
			throw new \InvalidArgumentException("Zone '$id': enabled must be true or false.");
		}
		try{
			return new self($id, $data["world"],
				self::corner($data["pos1"] ?? null), self::corner($data["pos2"] ?? null),
				self::spot($data["stand"] ?? null), self::spot($data["camera"] ?? null),
				self::spot($data["exit"] ?? null),
				$enabled
			);
		}catch(\InvalidArgumentException $e){
			throw new \InvalidArgumentException("Zone '$id': " . $e->getMessage(), 0, $e);
		}
	}

	/** @return array{int, int, int}|null */
	private static function corner(mixed $value) : ?array{
		if($value === null){
			return null;
		}
		if(!is_array($value) || !array_is_list($value) || count($value) !== 3){
			throw new \InvalidArgumentException("Corners must be [x, y, z] block coordinates.");
		}
		foreach($value as $coordinate){
			if(!is_int($coordinate) || $coordinate < -30000000 || $coordinate > 30000000){
				throw new \InvalidArgumentException("Corner coordinates must be integers between -30000000 and 30000000.");
			}
		}
		return [$value[0], $value[1], $value[2]];
	}

	private static function spot(mixed $value) : ?WardrobeSpot{
		return $value === null ? null : WardrobeSpot::fromArray($value);
	}

	/**
	 * Setup steps still needed before this zone can be enabled.
	 *
	 * @return list<string>
	 */
	public function getMissing() : array{
		$missing = [];
		foreach(["pos1" => $this->pos1, "pos2" => $this->pos2, "stand" => $this->stand, "camera" => $this->camera, "exit" => $this->exit] as $name => $value){
			if($value === null){
				$missing[] = $name;
			}
		}
		return $missing;
	}

	public function isActive() : bool{
		return $this->enabled && $this->getMissing() === [];
	}

	/**
	 * Whether a position lies in the cuboid, which includes the whole blocks at both corners.
	 */
	public function contains(string $world, float $x, float $y, float $z) : bool{
		if($world !== $this->world || $this->min === null || $this->max === null){
			return false;
		}
		return $x >= $this->min[0] && $x < $this->max[0] + 1 &&
			$y >= $this->min[1] && $y < $this->max[1] + 1 &&
			$z >= $this->min[2] && $z < $this->max[2] + 1;
	}

	/**
	 * Leaving to a spot inside the cuboid still works, but players must walk out and back in to
	 * use the wardrobe again.
	 */
	public function exitIsInside() : bool{
		return $this->exit !== null && $this->contains($this->world, $this->exit->x, floor($this->exit->y), $this->exit->z);
	}

	/** @return array<string, mixed> */
	public function toArray() : array{
		$data = ["world" => $this->world, "enabled" => $this->enabled];
		foreach(["pos1" => $this->pos1, "pos2" => $this->pos2] as $name => $corner){
			if($corner !== null){
				$data[$name] = $corner;
			}
		}
		foreach(["stand" => $this->stand, "camera" => $this->camera, "exit" => $this->exit] as $name => $spot){
			if($spot !== null){
				$data[$name] = $spot->toArray();
			}
		}
		return $data;
	}
}
