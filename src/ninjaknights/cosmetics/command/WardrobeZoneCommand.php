<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\command;

use ninjaknights\cosmetics\wardrobe\WardrobeSpot;
use ninjaknights\cosmetics\wardrobe\WardrobeZone;
use ninjaknights\cosmetics\wardrobe\WardrobeZoneManager;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use function count;
use function implode;
use function in_array;
use function strtolower;

/** Handles the permission-checked /nkcosmetics wardrobe subcommands. */
final class WardrobeZoneCommand{

	public const PERMISSION = "nkcosmetics.admin.wardrobe";

	/** Setup steps done by standing somewhere, mapped to the zone field they set. */
	private const SPOTS = ["setstand" => "stand", "setcamera" => "camera", "setexit" => "exit"];
	private const CORNERS = ["pos1", "pos2"];

	/** @var \Closure() : void */
	private \Closure $onChange;

	/**
	 * @param \Closure() : void $onChange called after a zone changes, so visits to it can be ended
	 */
	public function __construct(
		private WardrobeZoneManager $zones,
		\Closure $onChange
	){
		$this->onChange = $onChange;
	}

	/** @param list<string> $args */
	public function execute(CommandSender $sender, array $args) : void{
		if(!$sender->hasPermission(self::PERMISSION)){
			$sender->sendMessage(TF::RED . "You do not have permission to manage wardrobe zones.");
			return;
		}
		$action = strtolower($args[0] ?? "");
		try{
			if($action === "list" && count($args) === 1){
				$this->sendList($sender);
			}elseif(count($args) === 2 && $this->isZoneAction($action)){
				$id = strtolower($args[1]);
				if(!WardrobeZone::isValidId($id)){
					throw new \InvalidArgumentException("Use a zone name starting with a letter, followed by letters, numbers, _ or - (maximum 48 characters).");
				}
				$this->runZoneAction($sender, $action, $id);
			}else{
				$sender->sendMessage(TF::YELLOW . "/nkcosmetics wardrobe add <name>\n" .
					"/nkcosmetics wardrobe setstand|setcamera|setexit|pos1|pos2 <name>\n" .
					"/nkcosmetics wardrobe enable|disable|info|remove <name>\n" .
					"/nkcosmetics wardrobe list");
			}
		}catch(\InvalidArgumentException | \RuntimeException $e){
			$sender->sendMessage(TF::RED . $e->getMessage());
		}
	}

	private function isZoneAction(string $action) : bool{
		return isset(self::SPOTS[$action]) || in_array($action, [...self::CORNERS, "add", "enable", "disable", "info", "remove"], true);
	}

	private function runZoneAction(CommandSender $sender, string $action, string $id) : void{
		switch($action){
			case "add":
				$zone = $this->zones->create($id, $this->requirePlayer($sender)->getWorld()->getFolderName());
				$sender->sendMessage(TF::GREEN . "Wardrobe zone '$id' created in this world. " . $this->nextStep($zone));
				return;
			case "info":
				$this->sendInfo($sender, $this->requireZone($id));
				return;
			case "remove":
				$this->requireZone($id);
				($this->onChange)();
				$this->zones->remove($id);
				$sender->sendMessage(TF::GREEN . "Wardrobe zone '$id' removed.");
				return;
			case "enable":
			case "disable":
				$this->requireZone($id);
				($this->onChange)();
				$zone = $this->zones->update($id, ["enabled" => $action === "enable"]);
				$sender->sendMessage(TF::GREEN . "Wardrobe zone '$id' " . $action . "d.");
				if($zone->enabled && $zone->exitIsInside()){
					$sender->sendMessage(TF::YELLOW . "The exit is inside the entrance area, so players must walk out and back in to use it again. Consider moving it with setexit.");
				}
				return;
		}

		$player = $this->requirePlayer($sender);
		$zone = $this->requireZone($id);
		$world = $player->getWorld()->getFolderName();
		if($zone->world !== $world){
			throw new \InvalidArgumentException("Zone '$id' is in the world '{$zone->world}'. Set its locations from there.");
		}
		($this->onChange)();
		if(isset(self::SPOTS[$action])){
			$field = self::SPOTS[$action];
			$zone = $this->zones->update($id, [$field => WardrobeSpot::fromLocation($player->getLocation())->toArray()]);
			$sender->sendMessage(TF::GREEN . "Zone '$id': $field location set here. " . $this->nextStep($zone));
		}else{
			$position = $player->getPosition();
			$point = [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];
			$zone = $this->zones->update($id, [$action => $point]);
			$sender->sendMessage(TF::GREEN . "Zone '$id': $action set at " . implode(", ", $point) . ". " . $this->nextStep($zone));
		}
	}

	private function requirePlayer(CommandSender $sender) : Player{
		if(!$sender instanceof Player){
			throw new \InvalidArgumentException("Set zone locations in game. Console can list, inspect, enable, disable and remove zones.");
		}
		return $sender;
	}

	private function requireZone(string $id) : WardrobeZone{
		return $this->zones->getZone($id) ?? throw new \InvalidArgumentException("Unknown wardrobe zone '$id'. See /nkcosmetics wardrobe list");
	}

	private function nextStep(WardrobeZone $zone) : string{
		if($zone->enabled){
			return "It is enabled.";
		}
		$missing = $zone->getMissing();
		return $missing === []
			? "Ready: run /nkcosmetics wardrobe enable {$zone->id}"
			: "Still needed: " . implode(", ", $missing) . ".";
	}

	private function sendList(CommandSender $sender) : void{
		$lines = [TF::GOLD . "Wardrobe zones:"];
		foreach($this->zones->getZones() as $zone){
			$state = $zone->isActive() ? TF::GREEN . "enabled" : ($zone->getMissing() === [] ? TF::GRAY . "disabled" : TF::YELLOW . "unfinished");
			$lines[] = TF::WHITE . $zone->id . TF::GRAY . " in " . $zone->world . " - " . $state;
		}
		if(count($lines) === 1){
			$lines[] = TF::GRAY . "None. Start with /nkcosmetics wardrobe add <name>";
		}
		$sender->sendMessage(implode("\n", $lines));
	}

	private function sendInfo(CommandSender $sender, WardrobeZone $zone) : void{
		$format = static fn(?array $point) : string => $point === null ? TF::YELLOW . "not set" . TF::WHITE : implode(", ", $point);
		$spot = static fn(?WardrobeSpot $value) : ?array => $value?->toArray();
		$sender->sendMessage(implode("\n", [
			TF::GOLD . "Wardrobe zone " . $zone->id . TF::GRAY . " in " . $zone->world . " - " . ($zone->isActive() ? "enabled" : "not active"),
			TF::WHITE . "pos1: " . $format($zone->pos1),
			TF::WHITE . "pos2: " . $format($zone->pos2),
			TF::WHITE . "stand: " . $format($spot($zone->stand)),
			TF::WHITE . "camera: " . $format($spot($zone->camera)),
			TF::WHITE . "exit: " . $format($spot($zone->exit))
		]));
	}
}
