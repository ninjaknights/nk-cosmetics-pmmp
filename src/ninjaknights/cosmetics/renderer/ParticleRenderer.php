<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\builtin\particles\ParticleType;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\PluginSettings;
use pocketmine\player\Player;
use pocketmine\world\Position;

/** Small bounded emissions with explicit recipients; no world-wide particle broadcast. */
final class ParticleRenderer implements CosmeticRendererInterface{
	/** @var array<int, array{owner: Player, type: ParticleType, previous: Position, due: int, idleSince: ?int, movingUntil: int}> */
	private array $shown = [];
	private int $cursor = 0;

	public function __construct(private CosmeticManager $cosmetics, private PluginSettings $settings){}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
	}

	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::PARTICLE;
	}

	public function show(Player $player, CosmeticDefinition $definition) : void{
		$this->hide($player);
		$type = ParticleType::tryFrom($definition->id);
		if($type !== null){
			$this->shown[spl_object_id($player)] = ["owner" => $player, "type" => $type, "previous" => $player->getPosition(), "due" => 0, "idleSince" => null, "movingUntil" => 0];
		}
	}

	public function hide(Player $player) : void{
		unset($this->shown[spl_object_id($player)]);
	}

	public function clearCache() : void{}

	public function clear() : void{
		$this->shown = [];
	}

	public function tick(int $tick) : void{
		$ids = array_keys($this->shown);
		$count = count($ids);
		if($count === 0){
			return;
		}
		$budget = $this->settings->getCategoryInt(CosmeticCategory::PARTICLE, "max-effects-per-tick", 40, 1, 1000);
		$rangeSquared = $this->settings->getCategoryInt(CosmeticCategory::PARTICLE, "view-distance", 24, 1, 128) ** 2;
		$frequency = $this->settings->getCategoryFloat(CosmeticCategory::PARTICLE, "frequency", 1.0, 0.1, 2.0);
		//Resume after the last serviced owner so a low budget cannot starve later players.
		$start = $this->cursor % $count;
		for($i = 0; $i < $count && $budget > 0; ++$i){
			$index = ($start + $i) % $count;
			$id = $ids[$index];
			$state = $this->shown[$id];
			$interval = $state["type"]->getUpdateInterval($frequency);
			$owner = $state["owner"];
			if(!$owner->isConnected()){
				unset($this->shown[$id]);
				continue;
			}
			if($tick < $state["due"]){
				continue;
			}
			$position = $owner->getPosition();
			$world = $owner->getWorld();
			$sameWorld = $state["previous"]->world === $world;
			$distance = $sameWorld ? $position->distanceSquared($state["previous"]) : 0.0;
			$moving = $distance > 0.0025 && $distance < 100.0;
			$animationTick = $tick;
			if(!$state["type"]->isTrail()){
				if(!$sameWorld || $distance >= 100.0){
					$state["movingUntil"] = 0;
					$state["idleSince"] = $tick;
				}elseif($distance > 0.0001){
					//Recognize slow walking, and bridge short gaps between movement packets.
					$state["movingUntil"] = $tick + max(4, $interval + 1);
					$state["idleSince"] = null;
				}
				$moving = $tick < $state["movingUntil"];
				if(!$moving){
					$state["idleSince"] ??= $tick;
					$animationTick = $tick - $state["idleSince"];
				}
			}
			$state["previous"] = $position;
			$state["due"] = $tick + $interval;
			$this->shown[$id] = $state;
			$this->cursor = $index + 1;
			if(!$owner->isAlive() || !$this->cosmetics->isVisible($owner)){
				continue;
			}
			$viewers = [];
			foreach($world->getPlayers() as $viewer){
				if($viewer->getPosition()->distanceSquared($position) <= $rangeSquared && ($viewer === $owner || ($viewer->canSee($owner) && $this->cosmetics->showsOthers($viewer)))){
					$viewers[] = $viewer;
				}
			}
			if($viewers === []){
				continue;
			}
			$samples = $state["type"]->samples($animationTick, $moving);
			$sampleCount = count($samples);
			for($sample = 0; $sample < $sampleCount; ++$sample){
				//A restricted budget draws different parts of the shape on successive frames.
				[$offset, $particle] = $samples[($sample + intdiv($tick, $interval)) % $sampleCount];
				$world->addParticle($position->addVector($offset), $particle, $viewers);
				if(--$budget === 0){
					break;
				}
			}
		}
	}
}
