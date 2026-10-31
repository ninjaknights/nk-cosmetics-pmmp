<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\registry;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\cosmetic\Rarity;
use ninjaknights\cosmetics\resourcepack\ResourcePackAssetRegistry;
use function array_diff;
use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Turns the "cosmetics" section of a type file (cosmetics/hats.yml, back.yml, offhand.yml,
 * balloons.yml) or an API registration into validated definitions.
 *
 * Only hats, back items, offhand items and balloons can be defined. Entries for pets,
 * particles or gadgets are rejected: those are built-in catalogs.
 */
final class YamlCosmeticLoader{

	public const ID_PATTERN = '/^[a-z0-9_]{1,48}$/';
	public const PERMISSION_PATTERN = '/^[a-zA-Z0-9_.\-]+$/';

	private const COMMON_KEYS = ["type", "name", "description", "geometry", "texture", "animation", "permission", "rarity", "enabled", "unlock"];
	private const SKIN_KEYS = ["offset", "slim-offset", "replace-slot", "strip-bones"];
	private const CATEGORY_KEYS = [
		"hat" => ["hide-with-helmet"],
		"back" => ["hide-with-elytra"],
		"offhand" => ["hide-with-offhand-item"],
		"balloon" => ["entity", "height", "distance", "offset", "tether", "scale"]
	];

	/** Skin textures beyond this size would rarely fit next to a player's own skin texture. */
	public const MAX_SKIN_TEXTURE_SIZE = 128;

	/**
	 * @param string $source CosmeticDefinition::SOURCE_YAML or SOURCE_API
	 */
	public function __construct(
		private ?ResourcePackAssetRegistry $assets,
		private string $source = CosmeticDefinition::SOURCE_YAML
	){}

	/**
	 * Reads the "cosmetics" section of one type file (cosmetics/hats.yml, back.yml, offhand.yml or
	 * balloons.yml). The type comes from the file; a "type" key, if present, must match it.
	 *
	 * @param mixed  $parsed  result of yaml_parse() on the file
	 * @param string $rawYaml original text, used to detect duplicate ids that the YAML parser would silently merge
	 */
	public function loadCategoryFile(mixed $parsed, string $rawYaml, CosmeticCategory $category) : LoadReport{
		return $this->load($parsed, $rawYaml, $category);
	}

	/**
	 * Reads a "cosmetics" map whose entries name their own type (used by the API).
	 *
	 * @param mixed  $parsed           decoded YAML
	 * @param string $rawYaml          original text, used to detect duplicate ids
	 * @param ?CosmeticCategory $fileCategory type implied by the file the entries come from
	 */
	public function load(mixed $parsed, string $rawYaml = "", ?CosmeticCategory $fileCategory = null) : LoadReport{
		$report = new LoadReport();
		$label = $fileCategory !== null ? CosmeticFiles::label($fileCategory) : "cosmetics";
		if($parsed === null || $parsed === []){
			return $report;
		}
		if(!is_array($parsed)){
			$report->error($label, "File is not a valid YAML map");
			return $report;
		}
		$entries = $parsed["cosmetics"] ?? [];
		if(!is_array($entries)){
			$report->error($label, "'cosmetics' must be a map of id: definition");
			return $report;
		}

		foreach(self::findDuplicateIds($rawYaml) as $duplicate){
			$report->error($duplicate, "Cosmetic id is defined more than once in $label; only the last definition was read, fix the duplicate");
		}

		foreach($entries as $id => $entry){
			$id = (string) $id;
			if(preg_match(self::ID_PATTERN, $id) !== 1){
				$report->error($id, "Invalid id: use 1-48 lowercase letters, digits or underscores");
				continue;
			}
			if(!is_array($entry)){
				$report->error($id, "Definition must be a map");
				continue;
			}
			$definition = $this->parseEntry($id, $entry, $report, $fileCategory);
			if($definition !== null){
				$report->definitions[] = $definition;
			}
		}
		return $report;
	}

	/**
	 * @param mixed[] $entry
	 */
	private function parseEntry(string $id, array $entry, LoadReport $report, ?CosmeticCategory $fileCategory) : ?CosmeticDefinition{
		$type = $entry["type"] ?? $fileCategory?->value;
		if(!is_string($type)){
			$report->error($id, "Missing 'type' (hat, back, offhand or balloon)");
			return null;
		}
		$category = CosmeticCategory::tryFrom($type);
		if($category === null){
			$report->error($id, "Unknown type '$type' (expected hat, back, offhand or balloon)");
			return null;
		}
		if(!$category->isConfigurable()){
			$report->error($id, "'{$category->value}' cosmetics are built into NKCosmetics and cannot be added; " . CosmeticFiles::label($category) . " only renames, disables or unlocks the built-in ones");
			return null;
		}
		if($fileCategory !== null && $category !== $fileCategory){
			$report->error($id, "Is a {$category->value} but is listed in " . CosmeticFiles::label($fileCategory) . "; move it to " . CosmeticFiles::label($category));
			return null;
		}

		$allowed = [...self::COMMON_KEYS, ...(self::CATEGORY_KEYS[$category->value] ?? []), ...($category->isSkinAttached() ? self::SKIN_KEYS : [])];
		$unknown = array_diff(array_keys($entry), $allowed);
		if(count($unknown) > 0){
			$report->warning($id, "Ignoring unknown keys: " . implode(", ", $unknown));
		}

		$name = $entry["name"] ?? null;
		if(!is_string($name) || trim($name) === ""){
			$report->error($id, "Missing 'name'");
			return null;
		}
		$description = $entry["description"] ?? "";
		if(!is_string($description)){
			$report->warning($id, "'description' must be text; ignored");
			$description = "";
		}

		$geometry = $entry["geometry"] ?? null;
		if(!is_string($geometry) || !str_starts_with($geometry, "geometry.")){
			$report->error($id, "'geometry' must be a geometry identifier such as geometry.nk.$id");
			return null;
		}
		$texture = $entry["texture"] ?? null;
		if(!is_string($texture) || $texture === "" || preg_match('/\.(png|tga)$/i', $texture) === 1){
			$report->error($id, "'texture' must be a resource pack path without extension, e.g. textures/nkcosmetics/{$category->value}/$id");
			return null;
		}
		$animation = $entry["animation"] ?? null;
		if($animation !== null && !is_string($animation)){
			$report->error($id, "'animation' must be an animation identifier");
			return null;
		}
		if($animation !== null && $category->isSkinAttached()){
			$report->warning($id, "Animations are not supported for skin-attached {$category->value} cosmetics (player skins cannot carry custom animations); ignored");
			$animation = null;
		}

		$permission = $entry["permission"] ?? "nkcosmetics.{$category->value}.$id";
		if(!is_string($permission) || preg_match(self::PERMISSION_PATTERN, $permission) !== 1){
			$report->error($id, "'permission' contains invalid characters");
			return null;
		}

		$rarity = Rarity::COMMON;
		if(isset($entry["rarity"])){
			$parsedRarity = is_string($entry["rarity"]) ? Rarity::tryFrom($entry["rarity"]) : null;
			if($parsedRarity === null){
				$report->warning($id, "Unknown rarity; using common");
			}else{
				$rarity = $parsedRarity;
			}
		}

		$enabled = $entry["enabled"] ?? true;
		if(!is_bool($enabled)){
			$report->warning($id, "'enabled' must be true or false; treating as enabled");
			$enabled = true;
		}

		$defaultUnlocked = false;
		$permissionUnlocks = true;
		if(isset($entry["unlock"])){
			if(!is_array($entry["unlock"])){
				$report->warning($id, "'unlock' must be a map with 'default' and/or 'permission'; ignored");
			}else{
				$defaultUnlocked = self::bool($entry["unlock"]["default"] ?? false, $id, "unlock.default", $report, false);
				$permissionUnlocks = self::bool($entry["unlock"]["permission"] ?? true, $id, "unlock.permission", $report, true);
			}
		}

		$settings = $category === CosmeticCategory::BALLOON
			? $this->parseBalloonSettings($id, $entry, $report)
			: $this->parseSkinSettings($id, $category, $entry, $report);
		if($settings === null){
			return null;
		}

		if(!$this->validateAssets($id, $category, $geometry, $texture, $animation, $settings, $report)){
			return null;
		}

		return new CosmeticDefinition(
			$id,
			$category,
			$name,
			$description,
			$geometry,
			$texture,
			$animation,
			$permission,
			$rarity,
			$enabled,
			$defaultUnlocked,
			$permissionUnlocks,
			$settings,
			$this->source
		);
	}

	/**
	 * @param mixed[] $entry
	 * @return array<string, mixed>
	 */
	private function parseSkinSettings(string $id, CosmeticCategory $category, array $entry, LoadReport $report) : array{
		$settings = [
			"offset" => self::vec3($entry["offset"] ?? [0, 0, 0], $id, "offset", $report),
			"slim-offset" => self::vec3($entry["slim-offset"] ?? ($category === CosmeticCategory::OFFHAND ? [-0.5, 0, 0] : [0, 0, 0]), $id, "slim-offset", $report),
			//null = use the category default from config.yml
			"replace-slot" => isset($entry["replace-slot"]) ? self::bool($entry["replace-slot"], $id, "replace-slot", $report, true) : null,
			"strip-bones" => []
		];
		if(isset($entry["strip-bones"])){
			if(!is_array($entry["strip-bones"])){
				$report->warning($id, "'strip-bones' must be a list of bone names; ignored");
			}else{
				foreach($entry["strip-bones"] as $bone){
					if(is_string($bone) && $bone !== ""){
						$settings["strip-bones"][] = $bone;
					}
				}
			}
		}
		foreach(self::CATEGORY_KEYS[$category->value] ?? [] as $key){
			$settings[$key] = isset($entry[$key]) ? self::bool($entry[$key], $id, $key, $report, true) : null;
		}
		return $settings;
	}

	/**
	 * @param mixed[] $entry
	 * @return array<string, mixed>|null
	 */
	private function parseBalloonSettings(string $id, array $entry, LoadReport $report) : ?array{
		$entity = $entry["entity"] ?? "nkcosmetics:$id";
		if(!is_string($entity) || preg_match('/^[a-z0-9_.\-]+:[a-z0-9_.\-\/]+$/', $entity) !== 1 || str_starts_with($entity, "minecraft:")){
			$report->error($id, "'entity' must be a custom client entity identifier like nkcosmetics:$id");
			return null;
		}
		return [
			"entity" => $entity,
			"height" => self::number($entry["height"] ?? 2.4, $id, "height", $report, 2.4, 0.5, 6.0),
			"distance" => self::number($entry["distance"] ?? 1.2, $id, "distance", $report, 1.2, 0.3, 4.0),
			"scale" => self::number($entry["scale"] ?? 1.0, $id, "scale", $report, 1.0, 0.1, 4.0),
			"offset" => self::vec3($entry["offset"] ?? [0.6, 0, 0], $id, "offset", $report),
			"tether" => self::bool($entry["tether"] ?? true, $id, "tether", $report, true)
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function validateAssets(string $id, CosmeticCategory $category, string $geometry, string $texture, ?string $animation, array $settings, LoadReport $report) : bool{
		if($this->assets === null){
			$report->warning($id, "Resource pack not available; geometry and texture references were not checked");
			return true;
		}
		$model = $this->assets->getGeometry($geometry);
		if($model === null){
			$report->error($id, "Geometry $geometry was not found in the resource pack (" . $this->assets->getOrigin() . ")");
			return false;
		}
		if(!$this->assets->hasTexture($texture)){
			$report->error($id, "Texture $texture.png was not found in the resource pack");
			return false;
		}
		if($category->isSkinAttached()){
			if(!$this->assets->isPngTexture($texture)){
				$report->error($id, "Skin-attached cosmetics need a PNG texture (TGA cannot be read by the server)");
				return false;
			}
			try{
				$image = $this->assets->getTexture($texture);
			}catch(\RuntimeException $e){
				$report->error($id, "Texture $texture could not be read: " . $e->getMessage());
				return false;
			}
			if($image->getWidth() > self::MAX_SKIN_TEXTURE_SIZE || $image->getHeight() > self::MAX_SKIN_TEXTURE_SIZE){
				$report->error($id, "Texture is {$image->getWidth()}x{$image->getHeight()}; skin-attached cosmetics must be at most " . self::MAX_SKIN_TEXTURE_SIZE . "x" . self::MAX_SKIN_TEXTURE_SIZE);
				return false;
			}
			$densityX = $image->getWidth() / $model->getTextureWidth();
			$densityY = $image->getHeight() / $model->getTextureHeight();
			if(abs($densityX - $densityY) > 0.001){
				$report->error($id, "Texture {$image->getWidth()}x{$image->getHeight()} does not match the geometry's texture_width/texture_height ({$model->getTextureWidth()}x{$model->getTextureHeight()})");
				return false;
			}
			if($model->bones === []){
				$report->error($id, "Geometry $geometry has no bones");
				return false;
			}
		}else{
			$entity = (string) $settings["entity"];
			if(!$this->assets->hasClientEntity($entity)){
				$report->error($id, "Client entity $entity was not found in the resource pack's entity/ folder; the client would have nothing to render");
				return false;
			}
			if($animation !== null && !$this->assets->hasAnimation($animation)){
				$report->warning($id, "Animation $animation was not found in the resource pack");
			}
		}
		return true;
	}

	/**
	 * Finds ids that appear more than once directly under the "cosmetics:" key. YAML parsers keep
	 * only the last occurrence, which would otherwise hide the mistake.
	 *
	 * @return list<string>
	 */
	public static function findDuplicateIds(string $rawYaml) : array{
		$seen = [];
		$duplicates = [];
		$inSection = false;
		$childIndent = null;
		$lines = preg_split('/\R/', $rawYaml);
		foreach($lines !== false ? $lines : [] as $line){
			if(trim($line) === "" || str_starts_with(trim($line), "#")){
				continue;
			}
			$indent = strlen($line) - strlen(ltrim($line, " "));
			if($indent === 0){
				$inSection = preg_match('/^cosmetics\s*:\s*$/', $line) === 1;
				$childIndent = null;
				continue;
			}
			if(!$inSection){
				continue;
			}
			$childIndent ??= $indent;
			if($indent === $childIndent && preg_match('/^\s*["\']?([^"\':\s]+)["\']?\s*:/', $line, $matches) === 1){
				$key = $matches[1];
				if(isset($seen[$key]) && !isset($duplicates[$key])){
					$duplicates[$key] = true;
				}
				$seen[$key] = true;
			}
		}
		return array_keys($duplicates);
	}

	private static function bool(mixed $value, string $id, string $key, LoadReport $report, bool $default) : bool{
		if(is_bool($value)){
			return $value;
		}
		$report->warning($id, "'$key' must be true or false; using " . ($default ? "true" : "false"));
		return $default;
	}

	private static function number(mixed $value, string $id, string $key, LoadReport $report, float $default, float $min, float $max) : float{
		if(!is_int($value) && !is_float($value)){
			$report->warning($id, "'$key' must be a number; using $default");
			return $default;
		}
		if($value < $min || $value > $max){
			$report->warning($id, "'$key' must be between $min and $max; using $default");
			return $default;
		}
		return (float) $value;
	}

	/**
	 * @return array{float, float, float}
	 */
	private static function vec3(mixed $value, string $id, string $key, LoadReport $report) : array{
		if(is_array($value) && count($value) === 3 && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null) && is_numeric($value[2] ?? null)){
			return [(float) $value[0], (float) $value[1], (float) $value[2]];
		}
		$report->warning($id, "'$key' must be a list of three numbers [x, y, z]; using [0, 0, 0]");
		return [0.0, 0.0, 0.0];
	}
}
