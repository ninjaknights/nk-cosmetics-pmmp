<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

use function abs;
use function array_chunk;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Works out which pixels of a skin texture a bone samples, so free texture space can be found
 * without overwriting anything the existing skin still uses.
 *
 * Rectangles are returned in texture pixels as [x0, y0, x1, y1] (end-exclusive, may be fractional).
 */
final class UvRegionCalculator{

	private const FACE_SIZES = [
		"north" => [0, 1], "south" => [0, 1],
		"east" => [2, 1], "west" => [2, 1],
		"up" => [0, 2], "down" => [0, 2]
	];

	/**
	 * @param float $pixelsPerUnitX texture pixels per geometry texture unit (image width / texture_width)
	 * @param mixed[] $bone
	 * @return list<array{float, float, float, float}>|null null when the bone uses a UV layout this class does not understand
	 */
	public static function forBone(array $bone, float $pixelsPerUnitX, float $pixelsPerUnitY, int $imageWidth, int $imageHeight) : ?array{
		$rects = [];
		foreach((is_array($bone["cubes"] ?? null) ? $bone["cubes"] : []) as $cube){
			if(!is_array($cube)){
				return null;
			}
			$cubeRects = self::forCube($cube, $bone);
			if($cubeRects === null){
				return null;
			}
			foreach($cubeRects as [$x0, $y0, $x1, $y1]){
				$rects[] = [$x0 * $pixelsPerUnitX, $y0 * $pixelsPerUnitY, $x1 * $pixelsPerUnitX, $y1 * $pixelsPerUnitY];
			}
		}
		if(isset($bone["poly_mesh"])){
			if(!is_array($bone["poly_mesh"])){
				return null;
			}
			$meshRects = self::forPolyMesh($bone["poly_mesh"], $pixelsPerUnitX, $pixelsPerUnitY, $imageWidth, $imageHeight);
			if($meshRects === null){
				return null;
			}
			foreach($meshRects as $rect){
				$rects[] = $rect;
			}
		}
		if(isset($bone["texture_meshes"])){
			return null;
		}
		return $rects;
	}

	/**
	 * @param mixed[] $cube
	 * @param mixed[] $bone
	 * @return list<array{float, float, float, float}>|null rectangles in texture units
	 */
	private static function forCube(array $cube, array $bone) : ?array{
		$size = self::vec3($cube["size"] ?? null);
		if($size === null){
			return null;
		}
		[$sx, $sy, $sz] = [abs($size[0]), abs($size[1]), abs($size[2])];
		$uv = $cube["uv"] ?? ($bone["uv"] ?? [0, 0]);
		if(!is_array($uv)){
			return null;
		}
		if(isset($uv[0], $uv[1]) && is_numeric($uv[0]) && is_numeric($uv[1])){
			//box UV: the standard cross-shaped layout, 2*(x+z) wide and (z+y) tall
			$u = (float) $uv[0];
			$v = (float) $uv[1];
			return [[$u, $v, $u + 2 * ($sx + $sz), $v + $sz + $sy]];
		}
		$rects = [];
		foreach($uv as $face => $mapping){
			if(!is_string($face) || !isset(self::FACE_SIZES[$face]) || !is_array($mapping)){
				return null;
			}
			$start = $mapping["uv"] ?? null;
			if(!is_array($start) || !is_numeric($start[0] ?? null) || !is_numeric($start[1] ?? null)){
				return null;
			}
			$faceSize = $mapping["uv_size"] ?? null;
			if(is_array($faceSize) && is_numeric($faceSize[0] ?? null) && is_numeric($faceSize[1] ?? null)){
				[$w, $h] = [(float) $faceSize[0], (float) $faceSize[1]];
			}else{
				[$a, $b] = self::FACE_SIZES[$face];
				[$w, $h] = [[$sx, $sy, $sz][$a], [$sx, $sy, $sz][$b]];
			}
			$u = (float) $start[0];
			$v = (float) $start[1];
			$rects[] = [min($u, $u + $w), min($v, $v + $h), max($u, $u + $w), max($v, $v + $h)];
		}
		return $rects;
	}

	/**
	 * @param mixed[] $mesh
	 * @return list<array{float, float, float, float}>|null rectangles in texture pixels
	 */
	private static function forPolyMesh(array $mesh, float $pixelsPerUnitX, float $pixelsPerUnitY, int $imageWidth, int $imageHeight) : ?array{
		$uvs = $mesh["uvs"] ?? null;
		if(!is_array($uvs)){
			return null;
		}
		$normalized = ($mesh["normalized_uvs"] ?? false) === true;
		$toPixel = static function(mixed $uv) use ($normalized, $pixelsPerUnitX, $pixelsPerUnitY, $imageWidth, $imageHeight) : ?array{
			if(!is_array($uv) || !is_numeric($uv[0] ?? null) || !is_numeric($uv[1] ?? null)){
				return null;
			}
			if($normalized){
				//normalized V is measured from the bottom edge of the texture
				return [(float) $uv[0] * $imageWidth, (1 - (float) $uv[1]) * $imageHeight];
			}
			return [(float) $uv[0] * $pixelsPerUnitX, (float) $uv[1] * $pixelsPerUnitY];
		};

		$polys = $mesh["polys"] ?? null;
		$groups = [];
		if(is_array($polys)){
			foreach($polys as $poly){
				if(!is_array($poly)){
					return null;
				}
				$group = [];
				foreach($poly as $vertex){
					if(!is_array($vertex) || !is_numeric($vertex[2] ?? null)){
						return null;
					}
					$group[] = (int) $vertex[2];
				}
				$groups[] = $group;
			}
		}elseif($polys === "tri_list" || $polys === "quad_list"){
			$indices = [];
			for($i = 0, $count = count($uvs); $i < $count; ++$i){
				$indices[] = $i;
			}
			$groups = array_chunk($indices, $polys === "tri_list" ? 3 : 4);
		}else{
			return null;
		}

		$rects = [];
		foreach($groups as $group){
			$rect = null;
			foreach($group as $index){
				$pixel = $toPixel($uvs[$index] ?? null);
				if($pixel === null){
					return null;
				}
				$rect = $rect === null ? [$pixel[0], $pixel[1], $pixel[0], $pixel[1]] : [min($rect[0], $pixel[0]), min($rect[1], $pixel[1]), max($rect[2], $pixel[0]), max($rect[3], $pixel[1])];
			}
			if($rect !== null){
				$rects[] = $rect;
			}
		}
		return $rects;
	}

	/**
	 * @return array{float, float, float}|null
	 */
	public static function vec3(mixed $value) : ?array{
		if(!is_array($value) || !is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null) || !is_numeric($value[2] ?? null)){
			return null;
		}
		return [(float) $value[0], (float) $value[1], (float) $value[2]];
	}
}
