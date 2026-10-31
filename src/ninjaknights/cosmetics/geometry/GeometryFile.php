<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function version_compare;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * A Bedrock geometry JSON document. Accepts both the modern ("minecraft:geometry") and legacy
 * ("geometry.name": {...}) layouts and always writes the modern layout.
 */
final class GeometryFile{

	public const MIN_MODERN_FORMAT = "1.12.0";

	/**
	 * @param GeometryModel[] $models
	 * @phpstan-param list<GeometryModel> $models
	 */
	public function __construct(
		public string $formatVersion,
		public array $models
	){}

	/**
	 * @throws GeometryException
	 */
	public static function parse(string $json) : self{
		try{
			$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new GeometryException("Invalid geometry JSON: " . $e->getMessage(), 0, $e);
		}
		if(!is_array($decoded)){
			throw new GeometryException("Geometry JSON must be an object");
		}
		$format = is_string($decoded["format_version"] ?? null) ? $decoded["format_version"] : "1.8.0";

		$models = [];
		if(isset($decoded["minecraft:geometry"])){
			if(!is_array($decoded["minecraft:geometry"])){
				throw new GeometryException("minecraft:geometry must be a list");
			}
			foreach($decoded["minecraft:geometry"] as $entry){
				if(!is_array($entry)){
					throw new GeometryException("minecraft:geometry entries must be objects");
				}
				$models[] = GeometryModel::fromModernArray($entry);
			}
		}else{
			foreach($decoded as $key => $entry){
				if(is_string($key) && str_starts_with($key, "geometry.") && is_array($entry)){
					$models[] = GeometryModel::fromLegacyArray($key, $entry);
				}
			}
		}
		return new self($format, $models);
	}

	/**
	 * Finds a model by identifier. Legacy inheritance suffixes ("geometry.a:geometry.b") are ignored.
	 */
	public function find(string $identifier) : ?GeometryModel{
		$identifier = explode(":", $identifier, 2)[0];
		foreach($this->models as $model){
			if($model->identifier === $identifier){
				return $model;
			}
		}
		return null;
	}

	public function replace(GeometryModel $old, GeometryModel $new) : void{
		foreach($this->models as $index => $model){
			if($model === $old){
				$this->models[$index] = $new;
				return;
			}
		}
		$this->models[] = $new;
	}

	public function toJson() : string{
		$format = version_compare($this->formatVersion, self::MIN_MODERN_FORMAT, "<") ? self::MIN_MODERN_FORMAT : $this->formatVersion;
		$models = [];
		foreach($this->models as $model){
			$models[] = $model->toArray();
		}
		return json_encode(["format_version" => $format, "minecraft:geometry" => $models], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}
}
