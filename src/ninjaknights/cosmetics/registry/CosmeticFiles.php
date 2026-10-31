<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\registry;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use function file_exists;
use function file_get_contents;
use function is_array;
use function yaml_parse;

/**
 * One configuration file per cosmetic type, in the "cosmetics" folder of the plugin data folder.
 *
 * Every file has a "settings" section with the type's options. Custom types (hats, back,
 * offhand, balloons) list their cosmetics under "cosmetics"; built-in types (pets, particles,
 * gadgets) list the fixed catalog entries under "pets", "particles" or "gadgets", where they can
 * be renamed, disabled, re-permissioned or unlocked for everyone, but not added to.
 */
final class CosmeticFiles{

	public const DIRECTORY = "cosmetics";

	/**
	 * Section holding the entries of a file: custom definitions or built-in overrides.
	 */
	public static function entriesKey(CosmeticCategory $category) : string{
		return match($category){
			CosmeticCategory::PET => "pets",
			CosmeticCategory::PARTICLE => "particles",
			CosmeticCategory::GADGET => "gadgets",
			default => "cosmetics"
		};
	}

	public static function fileName(CosmeticCategory $category) : string{
		return match($category){
			CosmeticCategory::HAT => "hats.yml",
			CosmeticCategory::BACK => "back.yml",
			CosmeticCategory::OFFHAND => "offhand.yml",
			CosmeticCategory::BALLOON => "balloons.yml",
			CosmeticCategory::PET => "pets.yml",
			CosmeticCategory::PARTICLE => "particles.yml",
			CosmeticCategory::GADGET => "gadgets.yml"
		};
	}

	/**
	 * Path relative to the data folder, as shown in messages (e.g. "cosmetics/hats.yml").
	 */
	public static function label(CosmeticCategory $category) : string{
		return self::DIRECTORY . "/" . self::fileName($category);
	}

	/**
	 * Reads every type file. Missing files read as empty; unparseable files are reported.
	 *
	 * @return array<string, array{raw: string, parsed: array<mixed>, error: ?string}> category value => file
	 */
	public static function readAll(string $dataFolder) : array{
		$files = [];
		foreach(CosmeticCategory::ordered() as $category){
			$path = $dataFolder . self::DIRECTORY . "/" . self::fileName($category);
			$raw = file_exists($path) ? (string) file_get_contents($path) : "";
			$error = null;
			$parsed = [];
			if($raw !== ""){
				$decoded = @yaml_parse($raw);
				if(is_array($decoded)){
					$parsed = $decoded;
				}elseif($decoded === false){
					$error = "could not be parsed as YAML";
				}elseif($decoded !== null){
					//null means the file only holds comments
					$error = "must be a YAML map with 'settings' and '" . self::entriesKey($category) . "' sections";
				}
			}
			$files[$category->value] = ["raw" => $raw, "parsed" => $parsed, "error" => $error];
		}
		return $files;
	}
}
