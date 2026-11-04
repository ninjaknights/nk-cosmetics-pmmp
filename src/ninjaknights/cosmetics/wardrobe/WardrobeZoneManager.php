<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\wardrobe;

use pocketmine\player\Player;
use pocketmine\utils\Filesystem;
use function array_key_exists;
use function count;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_array;
use function is_bool;
use function is_string;
use function ksort;
use function yaml_emit;
use function yaml_parse;

/**
 * Loads and saves wardrobes.yml and detects players walking into active zones.
 */
final class WardrobeZoneManager{

	private const HEADER = <<<YAML
# Wardrobe zones: walking into one freezes the player on the zone's stand, with a fixed camera
# aimed at them. Punching opens the cosmetics menu; sneaking leaves.
#
# Set zones up in game (see the README); commands save straight away and keep these comments:
#   /nkcosmetics wardrobe add <name>
#   /nkcosmetics wardrobe setstand|setcamera|setexit <name>   stand on the spot
#   /nkcosmetics wardrobe pos1|pos2 <name>                    opposite corners of the entrance area
#   /nkcosmetics wardrobe enable <name>
#
# Manual edits need /nkcosmetics reload. Locations are [x, y, z, yaw, pitch].

YAML;

	/** @var array<string, WardrobeZone> */
	private array $zones = [];
	/** @var array<string, list<WardrobeZone>> active zones by world */
	private array $byWorld = [];
	/** @var \WeakMap<Player, array{inside: bool, world: string, next: int}> */
	private \WeakMap $visits;

	/**
	 * @param \Closure(Player, WardrobeZone) : void $onEnter
	 * @param \Closure(Player) : bool               $canEnter
	 */
	public function __construct(
		private string $file,
		private \Logger $logger,
		private \Closure $onEnter,
		private \Closure $canEnter
	){
		$this->visits = new \WeakMap();
	}

	/**
	 * Re-reads the file. A malformed file disables every zone and is left untouched.
	 *
	 * @return list<string> messages for the owner
	 */
	public function reload() : array{
		try{
			return $this->apply($this->read());
		}catch(\RuntimeException $e){
			$this->zones = [];
			$this->byWorld = [];
			$this->visits = new \WeakMap();
			$message = "Wardrobe zones disabled: " . $e->getMessage();
			$this->logger->error($message);
			return [$message];
		}
	}

	/** @return array<string, WardrobeZone> */
	public function getZones() : array{
		return $this->zones;
	}

	public function getZone(string $id) : ?WardrobeZone{
		return $this->zones[$id] ?? null;
	}

	public function findAt(string $world, float $x, float $y, float $z) : ?WardrobeZone{
		foreach($this->byWorld[$world] ?? [] as $zone){
			if($zone->contains($world, $x, $y, $z)){
				return $zone;
			}
		}
		return null;
	}

	/** Polls actual positions, so cancelled movement and failed teleports cannot trigger a zone. */
	public function tickPlayer(Player $player, int $tick) : void{
		if(!$player->isConnected()){
			unset($this->visits[$player]);
			return;
		}
		if($this->byWorld === []){
			return;
		}
		$position = $player->getPosition();
		$world = $player->getWorld()->getFolderName();
		$zone = $this->findAt($world, $position->x, $position->y, $position->z);
		$inside = $zone !== null;
		$before = $this->visits[$player] ?? null;
		$this->visits[$player] = ["inside" => $inside, "world" => $world, "next" => $before["next"] ?? 0];
		//establish a baseline on join and reload, so only walking in counts as entering
		if($zone === null || $before === null || ($before["inside"] && $before["world"] === $world) || $tick < $before["next"] || !($this->canEnter)($player)){
			return;
		}
		$this->visits[$player] = ["inside" => true, "world" => $world, "next" => $tick + 40];
		($this->onEnter)($player, $zone);
	}

	/**
	 * Creates an unfinished, disabled zone.
	 *
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public function create(string $id, string $world) : WardrobeZone{
		[$settings, $entries] = $this->read();
		if(array_key_exists($id, $entries)){
			throw new \InvalidArgumentException("A wardrobe zone named '$id' already exists.");
		}
		$zone = WardrobeZone::fromArray($id, ["world" => $world, "enabled" => false]);
		$entries[$id] = $zone->toArray();
		$this->write($settings, $entries);
		return $zone;
	}

	/**
	 * Saves changed fields of a zone, keeping the others.
	 *
	 * @param array<string, mixed> $changes
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public function update(string $id, array $changes) : WardrobeZone{
		[$settings, $entries] = $this->read();
		if(!array_key_exists($id, $entries)){
			throw new \InvalidArgumentException("Unknown wardrobe zone '$id'.");
		}
		$zone = WardrobeZone::fromArray($id, [...(is_array($entries[$id]) ? $entries[$id] : []), ...$changes]);
		if($zone->enabled && ($missing = $zone->getMissing()) !== []){
			throw new \InvalidArgumentException("Zone '$id' is not finished yet. Still needed: " . implode(", ", $missing) . ".");
		}
		$entries[$id] = $zone->toArray();
		$this->write($settings, $entries);
		return $zone;
	}

	/**
	 * @throws \InvalidArgumentException|\RuntimeException
	 */
	public function remove(string $id) : void{
		[$settings, $entries] = $this->read();
		if(!array_key_exists($id, $entries)){
			throw new \InvalidArgumentException("Unknown wardrobe zone '$id'.");
		}
		unset($entries[$id]);
		$this->write($settings, $entries);
	}

	/**
	 * @return array{array<array-key, mixed>, array<array-key, mixed>} other top-level settings, zone entries
	 * @throws \RuntimeException
	 */
	private function read() : array{
		$raw = file_exists($this->file) ? file_get_contents($this->file) : "";
		if($raw === false){
			throw new \RuntimeException("wardrobes.yml could not be read.");
		}
		$data = [];
		if($raw !== ""){
			$data = @yaml_parse($raw);
			if($data === false){
				throw new \RuntimeException("wardrobes.yml is not valid YAML.");
			}
			$data ??= [];
			if(!is_array($data)){
				throw new \RuntimeException("wardrobes.yml must be a map.");
			}
		}
		if(array_key_exists("enabled", $data) && !is_bool($data["enabled"])){
			throw new \RuntimeException("wardrobes.yml: enabled must be true or false.");
		}
		$entries = $data["zones"] ?? [];
		if(!is_array($entries)){
			throw new \RuntimeException("wardrobes.yml: zones must be a map of named zones.");
		}
		unset($data["zones"]);
		return [$data, $entries];
	}

	/**
	 * @param array<array-key, mixed> $settings
	 * @param array<array-key, mixed> $entries
	 */
	private function write(array $settings, array $entries) : void{
		$settings["zones"] = $entries;
		Filesystem::safeFilePutContents($this->file, self::HEADER . yaml_emit($settings));
		$this->apply([$settings, $entries]);
	}

	/**
	 * @param array{array<array-key, mixed>, array<array-key, mixed>} $parsed
	 * @return list<string>
	 */
	private function apply(array $parsed) : array{
		[$settings, $entries] = $parsed;
		$zones = [];
		$messages = [];
		foreach($entries as $id => $data){
			try{
				if(!is_string($id)){
					throw new \InvalidArgumentException("Zone names must start with a lowercase letter.");
				}
				$zones[$id] = WardrobeZone::fromArray($id, $data);
				if($zones[$id]->enabled && ($missing = $zones[$id]->getMissing()) !== []){
					$messages[] = "wardrobes.yml: zone '$id' is enabled but inactive. Still needed: " . implode(", ", $missing) . ".";
				}
			}catch(\InvalidArgumentException $e){
				$messages[] = "wardrobes.yml: skipped '$id': " . $e->getMessage();
			}
		}
		foreach($messages as $message){
			$this->logger->warning($message);
		}
		ksort($zones);
		$this->zones = $zones;
		$this->byWorld = [];
		$enabled = ($settings["enabled"] ?? true) === true;
		if($enabled){
			foreach($zones as $zone){
				if($zone->isActive()){
					$this->byWorld[$zone->world][] = $zone;
				}
			}
		}
		$this->visits = new \WeakMap();
		$summary = "Loaded " . count($zones) . " wardrobe zone(s)" . ($enabled ? "." : " (all disabled by wardrobes.yml).");
		$this->logger->info($summary);
		return [$summary, ...$messages];
	}
}
