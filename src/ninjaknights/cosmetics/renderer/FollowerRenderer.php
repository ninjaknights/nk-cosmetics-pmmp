<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\entity\FollowerEntity;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\PluginSettings;
use pocketmine\player\Player;
use function spl_object_id;

/**
 * Shared logic for cosmetics shown as an entity following the player: one entity per player,
 * despawned on hide, and a periodic visibility pass that (de)spawns it for viewers as they move
 * in and out of range or change their preferences.
 */
abstract class FollowerRenderer implements CosmeticRendererInterface{

	/** @var array<int, FollowerEntity> */
	private array $entities = [];
	/** @var array<int, CosmeticDefinition> */
	private array $shown = [];

	public function __construct(
		protected CosmeticManager $cosmetics,
		protected PluginSettings $settings
	){}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
	}

	abstract public function getCategory() : CosmeticCategory;

	abstract protected function createEntity(Player $player, CosmeticDefinition $definition) : ?FollowerEntity;

	protected function getViewDistance() : int{
		return $this->settings->getCategoryInt($this->getCategory(), "view-distance", 48, 8, 128);
	}

	protected function getUpdateInterval() : int{
		return $this->settings->getCategoryInt($this->getCategory(), "update-interval-ticks", 2, 1, 20);
	}

	/**
	 * @return \Closure(Player) : bool
	 */
	protected function createViewerFilter(Player $owner) : \Closure{
		$maxDistanceSquared = $this->getViewDistance() ** 2;
		return function(Player $viewer) use ($owner, $maxDistanceSquared) : bool{
			if($viewer === $owner){
				return true;
			}
			return $viewer->getWorld() === $owner->getWorld()
				&& $viewer->canSee($owner)
				&& $viewer->getPosition()->distanceSquared($owner->getPosition()) <= $maxDistanceSquared
				&& $this->cosmetics->showsOthers($viewer);
		};
	}

	public function show(Player $player, CosmeticDefinition $definition) : void{
		$this->despawn($player);
		$this->shown[spl_object_id($player)] = $definition;
		if(!$player->isAlive() || !$player->isConnected()){
			return;
		}
		$entity = $this->createEntity($player, $definition);
		if($entity !== null){
			$this->entities[spl_object_id($player)] = $entity;
			$entity->spawnToAll();
		}
	}

	public function hide(Player $player) : void{
		unset($this->shown[spl_object_id($player)]);
		$this->despawn($player);
	}

	private function despawn(Player $player) : void{
		$entity = $this->entities[spl_object_id($player)] ?? null;
		if($entity !== null){
			unset($this->entities[spl_object_id($player)]);
			if(!$entity->isClosed()){
				$entity->flagForDespawn();
			}
		}
	}

	public function clearCache() : void{
	}

	public function getEntity(Player $player) : ?FollowerEntity{
		return $this->entities[spl_object_id($player)] ?? null;
	}

	/**
	 * Keeps entities in their owner's world and sent only to the right viewers. Runs every few ticks.
	 */
	public function updateVisibility() : void{
		foreach($this->entities as $id => $entity){
			$owner = $entity->getOwner();
			if(!$owner->isConnected()){
				unset($this->entities[$id], $this->shown[$id]);
				if(!$entity->isClosed()){
					$entity->flagForDespawn();
				}
				continue;
			}
			if($entity->isClosed() || $entity->getWorld() !== $owner->getWorld()){
				//removed by another plugin, or the owner changed worlds: recreate next to them
				$definition = $this->shown[$id] ?? null;
				if($definition !== null){
					$this->show($owner, $definition);
				}
				continue;
			}
			$viewers = $entity->getViewers();
			foreach($entity->getWorld()->getPlayers() as $player){
				$visible = isset($viewers[spl_object_id($player)]);
				$should = $entity->shouldBeSeenBy($player);
				if($should && !$visible){
					$entity->spawnTo($player);
				}elseif(!$should && $visible){
					$entity->despawnFrom($player);
				}
			}
		}
	}

	/**
	 * @param Player[] $players
	 */
	public function despawnAll(array $players) : void{
		foreach($players as $player){
			$this->hide($player);
		}
	}
}
