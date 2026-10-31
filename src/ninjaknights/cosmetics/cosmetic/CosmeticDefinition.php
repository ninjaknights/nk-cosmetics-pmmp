<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\cosmetic;

/**
 * Immutable description of one cosmetic. Definitions for configurable categories come from
 * cosmetics/*.yml or the API; definitions for pets, particles and gadgets are created by the plugin
 * from its built-in catalogs.
 */
final class CosmeticDefinition{

	public const SOURCE_YAML = "yaml";
	public const SOURCE_API = "api";
	public const SOURCE_BUILTIN = "builtin";

	/**
	 * @param array<string, mixed> $settings renderer-specific settings, already validated
	 */
	public function __construct(
		public readonly string $id,
		public readonly CosmeticCategory $category,
		public readonly string $name,
		public readonly string $description,
		public readonly ?string $geometry,
		public readonly ?string $texture,
		public readonly ?string $animation,
		public readonly string $permission,
		public readonly Rarity $rarity,
		public readonly bool $enabled,
		public readonly bool $defaultUnlocked,
		public readonly bool $permissionUnlocks,
		public readonly array $settings,
		public readonly string $source
	){}

	public function getSetting(string $key, mixed $default = null) : mixed{
		return $this->settings[$key] ?? $default;
	}

	public function isBuiltin() : bool{
		return $this->source === self::SOURCE_BUILTIN;
	}
}
