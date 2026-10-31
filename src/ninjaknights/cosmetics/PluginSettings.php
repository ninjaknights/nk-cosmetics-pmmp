<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics;

use ninjaknights\cosmetics\builtin\BuiltinCatalogs;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\registry\CosmeticFiles;
use function array_key_exists;
use function array_keys;
use function explode;
use function implode;
use function in_array;
use function strtolower;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Typed, validated view of config.yml and the per-type files in cosmetics/. Invalid values fall
 * back to defaults and are reported with the file they came from.
 *
 * Internally each type file's "settings" section is read as categories.<type> and its built-in
 * entries as builtin.<pets|particles|gadgets>.
 */
final class PluginSettings{

	/** @var list<string> */
	private array $warnings = [];
	/** @var mixed[] */
	private array $data;

	/**
	 * @param mixed[]                $config       config.yml
	 * @param array<string, mixed[]> $categoryFiles category value => parsed cosmetics/<type>.yml
	 */
	public function __construct(array $config, array $categoryFiles = []){
		foreach(["categories", "builtin"] as $legacy){
			if(array_key_exists($legacy, $config)){
				$this->warnings[] = "config.yml: '$legacy' is no longer read here; type options now live in the cosmetics/ folder (one file per type)";
				unset($config[$legacy]);
			}
		}
		$config["categories"] = [];
		$config["builtin"] = [];
		foreach(CosmeticCategory::ordered() as $category){
			$file = $categoryFiles[$category->value] ?? [];
			$settings = $file["settings"] ?? [];
			$config["categories"][$category->value] = is_array($settings) ? $settings : [];
			if(!is_array($settings)){
				$this->warnings[] = CosmeticFiles::label($category) . ": 'settings' must be a map; using defaults";
			}
			if(!$category->isConfigurable()){
				$key = CosmeticFiles::entriesKey($category);
				$entries = $file[$key] ?? [];
				$config["builtin"][$key] = is_array($entries) ? $entries : [];
				if(!is_array($entries)){
					$this->warnings[] = CosmeticFiles::label($category) . ": '$key' must be a map of id: options; ignored";
				}
			}
		}
		$this->data = $config;
	}

	/**
	 * @return list<string>
	 */
	public function getWarnings() : array{
		return $this->warnings;
	}

	/**
	 * Where a setting path is written, for messages: "cosmetics/hats.yml: settings.enabled".
	 */
	private static function describe(string $path) : string{
		$parts = explode(".", $path, 3);
		if($parts[0] === "categories" && isset($parts[1]) && ($category = CosmeticCategory::tryFrom($parts[1])) !== null){
			return CosmeticFiles::label($category) . ": settings" . (isset($parts[2]) ? "." . $parts[2] : "");
		}
		if($parts[0] === "builtin" && isset($parts[1])){
			foreach(CosmeticCategory::ordered() as $category){
				if(!$category->isConfigurable() && CosmeticFiles::entriesKey($category) === $parts[1]){
					return CosmeticFiles::label($category) . ": " . $parts[1] . (isset($parts[2]) ? "." . $parts[2] : "");
				}
			}
		}
		return "config.yml: $path";
	}

	private function get(string $path) : mixed{
		$value = $this->data;
		foreach(explode(".", $path) as $key){
			if(!is_array($value) || !array_key_exists($key, $value)){
				return null;
			}
			$value = $value[$key];
		}
		return $value;
	}

	private function bool(string $path, bool $default) : bool{
		$value = $this->get($path);
		if($value === null){
			return $default;
		}
		if(!is_bool($value)){
			$this->warnings[] = self::describe($path) . " must be true or false; using " . ($default ? "true" : "false");
			return $default;
		}
		return $value;
	}

	private function int(string $path, int $default, int $min, int $max) : int{
		$value = $this->get($path);
		if($value === null){
			return $default;
		}
		if(!is_int($value) || $value < $min || $value > $max){
			$this->warnings[] = self::describe($path) . " must be a whole number between $min and $max; using $default";
			return $default;
		}
		return $value;
	}

	private function float(string $path, float $default, float $min, float $max) : float{
		$value = $this->get($path);
		if($value === null){
			return $default;
		}
		if((!is_int($value) && !is_float($value)) || $value < $min || $value > $max){
			$this->warnings[] = self::describe($path) . " must be a number between $min and $max; using $default";
			return $default;
		}
		return (float) $value;
	}

	private function string(string $path, string $default) : string{
		$value = $this->get($path);
		if($value === null){
			return $default;
		}
		if(!is_string($value) || $value === ""){
			$this->warnings[] = self::describe($path) . " must be text; using $default";
			return $default;
		}
		return $value;
	}

	public function getSqliteFile() : string{
		return $this->string("storage.sqlite-file", "cosmetics.db");
	}

	public function getAutosaveSeconds() : int{
		return $this->int("storage.autosave-seconds", 60, 5, 3600);
	}

	public function getResourcePackSource() : string{
		return $this->string("resource-pack.source", "resource-pack");
	}

	public function isResourcePackAutoRegister() : bool{
		return $this->bool("resource-pack.auto-register", true);
	}

	public function isResourcePackForced() : bool{
		return $this->bool("resource-pack.force", false);
	}

	public function isPreservePersona() : bool{
		return $this->bool("skins.preserve-persona", true);
	}

	public function isAcceptLargeClassicSkins() : bool{
		return $this->bool("skins.accept-large-classic-skins", true);
	}

	public function isComposedSkinPersona() : bool{
		$value = $this->string("skins.composed-skin-type", "classic");
		if(!in_array($value, ["persona", "classic"], true)){
			$this->warnings[] = "skins.composed-skin-type must be persona or classic; using classic";
			return false;
		}
		return $value === "persona";
	}

	public function getMaxTextureSize() : int{
		$value = $this->int("skins.max-texture-size", 512, 64, 1024);
		//keep it a power of two so doubling the canvas lands on it
		$size = 64;
		while($size * 2 <= $value){
			$size *= 2;
		}
		return $size;
	}

	public function getSkinUpdateDelay() : int{
		return $this->int("skins.update-delay-ticks", 2, 0, 40);
	}

	public function isCategoryEnabled(CosmeticCategory $category) : bool{
		return $this->bool("categories.{$category->value}.enabled", true);
	}

	public function getCategoryBool(CosmeticCategory $category, string $key, bool $default) : bool{
		return $this->bool("categories.{$category->value}.$key", $default);
	}

	public function getCategoryInt(CosmeticCategory $category, string $key, int $default, int $min, int $max) : int{
		return $this->int("categories.{$category->value}.$key", $default, $min, $max);
	}

	public function getCategoryFloat(CosmeticCategory $category, string $key, float $default, float $min, float $max) : float{
		return $this->float("categories.{$category->value}.$key", $default, $min, $max);
	}

	/**
	 * @return list<string>
	 */
	public function getDisabledWorlds() : array{
		$value = $this->get("disabled-worlds");
		$worlds = [];
		foreach(is_array($value) ? $value : [] as $world){
			if(is_string($world)){
				$worlds[] = $world;
			}
		}
		return $worlds;
	}

	public function getMenuPageSize() : int{
		return max(1, min(20, $this->int("menu.page-size", 8, 1, 20)));
	}

	public function getGadgetCooldownSeconds(string $id, int $default) : int{
		return $this->int("builtin.gadgets.$id.cooldown-seconds", $default, 1, 300);
	}

	/**
	 * Entries in pets.yml, particles.yml or gadgets.yml that match no built-in id (typos, or
	 * attempts to add new ones, which is not possible).
	 *
	 * @return list<string> messages
	 */
	public function getUnknownBuiltinIds() : array{
		$messages = [];
		foreach(BuiltinCatalogs::all() as $section => [$category, $entries]){
			$known = [];
			foreach($entries as $entry){
				$known[$entry->id] = true;
			}
			$configured = $this->get("builtin.$section");
			foreach(is_array($configured) ? $configured : [] as $id => $_){
				if(!isset($known[(string) $id])){
					$messages[] = CosmeticFiles::label($category) . ": '$id' is not a built-in " . strtolower($category->getSingularName()) . " and was ignored (new ones cannot be added; known: " . implode(", ", array_keys($known)) . ")";
				}
			}
		}
		return $messages;
	}

	/**
	 * Overrides for one entry of a built-in catalog.
	 *
	 * @return array{enabled: bool, name: ?string, permission: ?string, default-unlocked: bool}
	 */
	public function getBuiltinOverride(string $catalog, string $id) : array{
		$base = "builtin.$catalog.$id";
		$name = $this->get("$base.name");
		$permission = $this->get("$base.permission");
		return [
			"enabled" => $this->bool("$base.enabled", true),
			"name" => is_string($name) && $name !== "" ? $name : null,
			"permission" => is_string($permission) && $permission !== "" ? $permission : null,
			"default-unlocked" => $this->bool("$base.default-unlocked", false)
		];
	}
}
