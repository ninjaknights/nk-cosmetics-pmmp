<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\geometry\CosmeticLayer;
use ninjaknights\cosmetics\geometry\GeometryException;
use ninjaknights\cosmetics\PluginSettings;
use ninjaknights\cosmetics\resourcepack\ResourcePackAssetRegistry;
use ninjaknights\cosmetics\skin\SkinGeometryManager;
use ninjaknights\cosmetics\skin\SkinLayerProvider;
use pocketmine\player\Player;
use function is_array;
use function is_bool;
use function is_string;
use function spl_object_id;

/**
 * Base for cosmetics drawn as part of the player's skin geometry (hats, back items, offhand).
 *
 * The cosmetic is grafted onto a slot bone the client already animates: persona skins have
 * helmet/bodyarmor/leftitem bones, which is also where the client puts the persona pieces of the
 * same kind, so equipping one of ours can replace the persona piece.
 */
abstract class SkinAttachedRenderer implements CosmeticRendererInterface, SkinLayerProvider{

	/** @var array<int, CosmeticDefinition> */
	private array $shown = [];
	/** @var array<string, CosmeticLayer|null> definition id => built layer (null = assets unusable) */
	private array $layers = [];
	private int $generation = 0;

	public function __construct(
		protected SkinGeometryManager $skins,
		protected ResourcePackAssetRegistry $assets,
		protected PluginSettings $settings,
		protected \Logger $logger
	){}

	abstract public function getCategory() : CosmeticCategory;

	/** Persona/vanilla bone the cosmetic belongs to. */
	abstract protected function getSlotBone() : string;

	/**
	 * Bones to attach to, in order, if the skin has no slot bone.
	 *
	 * @return list<string>
	 */
	abstract protected function getFallbackBones() : array;

	/**
	 * Whether a real item currently conflicts with the cosmetic, so it should not be drawn.
	 */
	abstract protected function isSuppressed(Player $player, CosmeticDefinition $definition) : bool;

	public function setAssets(ResourcePackAssetRegistry $assets) : void{
		$this->assets = $assets;
		$this->clearCache();
	}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
		$this->clearCache();
	}

	public function show(Player $player, CosmeticDefinition $definition) : void{
		$this->shown[spl_object_id($player)] = $definition;
		$this->skins->requestUpdate($player);
	}

	public function hide(Player $player) : void{
		if(isset($this->shown[spl_object_id($player)])){
			unset($this->shown[spl_object_id($player)]);
			$this->skins->requestUpdate($player);
		}
	}

	public function clearCache() : void{
		$this->layers = [];
		++$this->generation;
	}

	public function getLayer(Player $player) : ?array{
		$definition = $this->shown[spl_object_id($player)] ?? null;
		if($definition === null || $this->isSuppressed($player, $definition)){
			return null;
		}
		if(!array_key_exists($definition->id, $this->layers)){
			$this->layers[$definition->id] = $this->buildLayer($definition);
		}
		$layer = $this->layers[$definition->id];
		return $layer === null ? null : [$layer, $definition->id . "#" . $this->generation];
	}

	private function buildLayer(CosmeticDefinition $definition) : ?CosmeticLayer{
		$geometry = $definition->geometry !== null ? $this->assets->getGeometry($definition->geometry) : null;
		if($geometry === null || $definition->texture === null){
			$this->logger->warning("Cosmetic {$definition->id}: geometry {$definition->geometry} is not in the resource pack; it will not be shown");
			return null;
		}
		try{
			$texture = $this->assets->getTexture($definition->texture);
		}catch(GeometryException $e){
			$this->logger->warning("Cosmetic {$definition->id}: " . $e->getMessage());
			return null;
		}
		$replace = $definition->getSetting("replace-slot");
		$strip = [];
		foreach(is_array($definition->getSetting("strip-bones")) ? $definition->getSetting("strip-bones") : [] as $bone){
			if(is_string($bone)){
				$strip[] = $bone;
			}
		}
		return new CosmeticLayer(
			$this->getCategory()->value,
			$this->getSlotBone(),
			$this->getFallbackBones(),
			is_bool($replace) ? $replace : $this->settings->getCategoryBool($this->getCategory(), "replace-slot", true),
			$geometry,
			$texture,
			$strip,
			self::vec3($definition->getSetting("offset")),
			self::vec3($definition->getSetting("slim-offset"))
		);
	}

	/**
	 * Resolves a boolean option: the cosmetic's own value if set, else the category default.
	 */
	protected function option(CosmeticDefinition $definition, string $key, bool $default) : bool{
		$value = $definition->getSetting($key);
		return is_bool($value) ? $value : $this->settings->getCategoryBool($this->getCategory(), $key, $default);
	}

	/**
	 * @return array{float, float, float}
	 */
	private static function vec3(mixed $value) : array{
		return is_array($value) && isset($value[0], $value[1], $value[2]) ? [(float) $value[0], (float) $value[1], (float) $value[2]] : [0.0, 0.0, 0.0];
	}
}
