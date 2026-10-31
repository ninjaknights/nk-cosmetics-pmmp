<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function strtolower;

/**
 * One geometry definition (an entry of "minecraft:geometry") held as decoded JSON arrays so that
 * fields this plugin does not understand are preserved untouched.
 */
final class GeometryModel{

	/**
	 * @param mixed[]   $description
	 * @param mixed[][] $bones
	 */
	public function __construct(
		public string $identifier,
		public array $description,
		public array $bones
	){}

	/**
	 * @param mixed[] $data
	 * @throws GeometryException
	 */
	public static function fromModernArray(array $data) : self{
		$description = is_array($data["description"] ?? null) ? $data["description"] : [];
		$identifier = $description["identifier"] ?? null;
		if(!is_string($identifier) || $identifier === ""){
			throw new GeometryException("Geometry is missing description.identifier");
		}
		return new self($identifier, $description, self::readBones($data["bones"] ?? []));
	}

	/**
	 * Converts a pre-1.12 ("geometry.name": {texturewidth, bones}) entry to the modern layout.
	 *
	 * @param mixed[] $data
	 * @throws GeometryException
	 */
	public static function fromLegacyArray(string $key, array $data) : self{
		$identifier = explode(":", $key, 2)[0];
		$description = ["identifier" => $identifier];
		foreach(["texturewidth" => "texture_width", "textureheight" => "texture_height", "visible_bounds_width" => "visible_bounds_width", "visible_bounds_height" => "visible_bounds_height", "visible_bounds_offset" => "visible_bounds_offset"] as $old => $new){
			if(isset($data[$old])){
				$description[$new] = $data[$old];
			}
		}
		return new self($identifier, $description, self::readBones($data["bones"] ?? []));
	}

	/**
	 * @return mixed[][]
	 */
	private static function readBones(mixed $bones) : array{
		if(!is_array($bones)){
			throw new GeometryException("Geometry bones must be a list");
		}
		$result = [];
		foreach($bones as $bone){
			if(!is_array($bone) || !is_string($bone["name"] ?? null)){
				throw new GeometryException("Every bone needs a name");
			}
			$result[] = $bone;
		}
		return $result;
	}

	public function getTextureWidth() : float{
		$value = $this->description["texture_width"] ?? 64;
		return is_numeric($value) ? (float) $value : 64.0;
	}

	public function getTextureHeight() : float{
		$value = $this->description["texture_height"] ?? 64;
		return is_numeric($value) ? (float) $value : 64.0;
	}

	public function findBoneIndex(string $name) : ?int{
		$lower = strtolower($name);
		foreach($this->bones as $index => $bone){
			if(is_string($bone["name"] ?? null) && strtolower($bone["name"]) === $lower){
				return $index;
			}
		}
		return null;
	}

	public function findBoneName(string $name) : ?string{
		$index = $this->findBoneIndex($name);
		return $index === null ? null : (string) $this->bones[$index]["name"];
	}

	/**
	 * Whether a bone carries renderable geometry (cubes or a mesh) rather than being a pure pivot.
	 *
	 * @param mixed[] $bone
	 */
	public static function boneHasGeometry(array $bone) : bool{
		return (is_array($bone["cubes"] ?? null) && $bone["cubes"] !== []) || isset($bone["poly_mesh"]) || isset($bone["texture_meshes"]);
	}

	/**
	 * Names (lowercased) of $root and every bone below it in the hierarchy.
	 *
	 * @return array<string, true>
	 */
	public function collectSubtree(string $root) : array{
		$subtree = [strtolower($root) => true];
		do{
			$added = false;
			foreach($this->bones as $bone){
				$name = strtolower((string) $bone["name"]);
				$parent = is_string($bone["parent"] ?? null) ? strtolower($bone["parent"]) : null;
				if($parent !== null && isset($subtree[$parent]) && !isset($subtree[$name])){
					$subtree[$name] = true;
					$added = true;
				}
			}
		}while($added);
		return $subtree;
	}

	/**
	 * @return mixed[]
	 */
	public function toArray() : array{
		$bones = [];
		foreach($this->bones as $bone){
			//json_decode(assoc) turns {} into [], which the client rejects for object-typed fields
			foreach(["locators", "uv"] as $objectKey){
				if(isset($bone[$objectKey]) && $bone[$objectKey] === []){
					unset($bone[$objectKey]);
				}
			}
			$bones[] = $bone;
		}
		$description = $this->description;
		$description["identifier"] = $this->identifier;
		return ["description" => $description, "bones" => $bones];
	}
}
