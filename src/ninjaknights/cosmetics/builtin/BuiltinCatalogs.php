<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\builtin;

use ninjaknights\cosmetics\builtin\pets\PetType;
use ninjaknights\cosmetics\builtin\particles\ParticleType;
use ninjaknights\cosmetics\builtin\gadgets\GadgetType;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\PluginSettings;
use ninjaknights\cosmetics\registry\CosmeticRegistry;

/**
 * Registers the fixed pet, particle and gadget catalogs. Server owners can rename, disable,
 * re-permission or default-unlock entries in config.yml, but cannot add new ones: their
 * behaviour lives in PHP.
 */
final class BuiltinCatalogs{

	/**
	 * @return array<string, array{CosmeticCategory, list<BuiltinEntry>}> config section => [category, entries]
	 */
	public static function all() : array{
		return [
			"pets" => [CosmeticCategory::PET, PetType::entries()],
			"particles" => [CosmeticCategory::PARTICLE, ParticleType::entries()],
			"gadgets" => [CosmeticCategory::GADGET, GadgetType::entries()]
		];
	}

	public static function register(CosmeticRegistry $registry, PluginSettings $settings) : void{
		$registry->clearBuiltins();
		foreach(self::all() as $section => [$category, $entries]){
			foreach($entries as $entry){
				$override = $settings->getBuiltinOverride($section, $entry->id);
				$registry->registerBuiltin(new CosmeticDefinition(
					$entry->id,
					$category,
					$override["name"] ?? $entry->name,
					$entry->description,
					null,
					null,
					null,
					$override["permission"] ?? "nkcosmetics.{$category->value}.{$entry->id}",
					$entry->rarity,
					$override["enabled"],
					$override["default-unlocked"],
					true,
					[],
					CosmeticDefinition::SOURCE_BUILTIN
				));
			}
		}
	}
}
