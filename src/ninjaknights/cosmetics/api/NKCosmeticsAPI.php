<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\api;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\event\ChangeCause;
use ninjaknights\cosmetics\Main;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\manager\EquipResult;
use ninjaknights\cosmetics\manager\PermissionRegistrar;
use ninjaknights\cosmetics\registry\YamlCosmeticLoader;
use ninjaknights\cosmetics\resourcepack\ResourcePackAssetRegistry;
use pocketmine\player\Player;
use function implode;

/**
 * Public API of NKCosmetics for other plugins (ranks, rewards, quests, shops).
 *
 *     $api = NKCosmeticsAPI::get();
 *     if($api !== null){
 *         $api->unlock("Steve", "royal_crown");
 *     }
 *
 * Pets, particles and gadgets are fixed built-in catalogs: they can be queried, granted and
 * equipped here, but new ones cannot be registered. Listen to the events in
 * ninjaknights\cosmetics\event to react to changes.
 */
final class NKCosmeticsAPI{

	/**
	 * @internal Created by the plugin.
	 */
	public function __construct(
		private CosmeticManager $cosmetics,
		private ResourcePackAssetRegistry $assets
	){}

	/**
	 * Returns the API, or null when NKCosmetics is not enabled.
	 */
	public static function get() : ?self{
		return Main::getInstance()?->getApi();
	}

	/**
	 * @internal
	 */
	public function setAssets(ResourcePackAssetRegistry $assets) : void{
		$this->assets = $assets;
	}

	/**
	 * Registers a hat, back, offhand or balloon cosmetic using the same keys as an entry of
	 * cosmetics/hats.yml (and friends), plus "type", validated the same way (including
	 * resource-pack references).
	 *
	 *     $api->registerCosmetic("event_cap", [
	 *         "type" => "hat",
	 *         "name" => "Event Cap",
	 *         "geometry" => "geometry.myplugin.event_cap",
	 *         "texture" => "textures/myplugin/event_cap",
	 *     ]);
	 *
	 * API cosmetics survive /nkcosmetics reload; their models must be in the cosmetics resource pack.
	 *
	 * @param array<string, mixed> $config
	 * @throws \InvalidArgumentException listing every validation error
	 */
	public function registerCosmetic(string $id, array $config) : CosmeticDefinition{
		$report = (new YamlCosmeticLoader($this->assets, CosmeticDefinition::SOURCE_API))->load(["cosmetics" => [$id => $config]]);
		if($report->errors !== [] || $report->definitions === []){
			throw new \InvalidArgumentException("Cannot register cosmetic $id: " . implode("; ", $report->errors));
		}
		$definition = $report->definitions[0];
		$this->cosmetics->getRegistry()->registerCustom($definition);
		PermissionRegistrar::register($definition);
		Main::announceEntity($definition);
		return $definition;
	}

	/**
	 * Removes a cosmetic registered through the API or a cosmetics/*.yml file. Players wearing it lose it
	 * with ChangeCause::ACCESS_LOST. Built-in cosmetics cannot be removed.
	 */
	public function unregisterCosmetic(string $id) : bool{
		if(!$this->cosmetics->getRegistry()->unregister($id)){
			return false;
		}
		foreach($this->cosmetics->getSessions()->getAll() as $session){
			$this->cosmetics->revalidate($session->getPlayer());
		}
		return true;
	}

	public function getCosmetic(string $id) : ?CosmeticDefinition{
		return $this->cosmetics->getRegistry()->get($id);
	}

	/**
	 * @return list<CosmeticDefinition>
	 */
	public function getCosmetics(?CosmeticCategory $category = null) : array{
		if($category !== null){
			return $this->cosmetics->getRegistry()->getByCategory($category);
		}
		$all = [];
		foreach($this->cosmetics->getRegistry()->getAll() as $definition){
			$all[] = $definition;
		}
		return $all;
	}

	/**
	 * Whether the player can use the cosmetic (default unlock, permission or grant).
	 */
	public function isUnlocked(Player $player, string $id) : bool{
		$definition = $this->getCosmetic($id);
		return $definition !== null && $this->cosmetics->isUnlocked($player, $definition);
	}

	/**
	 * Permanently grants a cosmetic. Works for offline players who have joined before.
	 * Fires CosmeticUnlockEvent.
	 *
	 * @return bool false if the id or player is unknown, it was already granted, or the event was cancelled
	 */
	public function unlock(string $playerName, string $id) : bool{
		$definition = $this->getCosmetic($id);
		return $definition !== null && $this->cosmetics->unlock($playerName, $definition, ChangeCause::API);
	}

	/**
	 * Takes away a granted cosmetic. Fires CosmeticRevokeEvent. Permission-based access is not affected.
	 */
	public function revoke(string $playerName, string $id) : bool{
		$definition = $this->getCosmetic($id);
		return $definition !== null && $this->cosmetics->revoke($playerName, $definition, ChangeCause::API);
	}

	/**
	 * Equips a cosmetic the player has access to. Fires CosmeticEquipEvent.
	 */
	public function equip(Player $player, string $id) : EquipResult{
		return $this->cosmetics->equip($player, $id, ChangeCause::API);
	}

	/**
	 * Fires CosmeticUnequipEvent.
	 */
	public function unequip(Player $player, CosmeticCategory $category) : bool{
		return $this->cosmetics->unequip($player, $category, ChangeCause::API);
	}

	public function getEquipped(Player $player, CosmeticCategory $category) : ?CosmeticDefinition{
		return $this->cosmetics->getEquipped($player, $category);
	}

	/**
	 * Snapshot of a player's cosmetic state.
	 */
	public function getState(Player $player) : PlayerCosmeticState{
		$session = $this->cosmetics->getSessions()->get($player);
		$granted = $session !== null ? $session->getRecord()->unlocks : [];
		$equipped = [];
		foreach($this->cosmetics->getAllEquipped($player) as $category => $definition){
			$equipped[$category] = $definition->id;
		}
		return new PlayerCosmeticState($player->getName(), $equipped, $granted, $this->cosmetics->isVisible($player), $this->cosmetics->showsOthers($player));
	}
}
