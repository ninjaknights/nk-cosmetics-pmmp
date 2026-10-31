<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

use function abs;
use function array_key_exists;
use function ceil;
use function count;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function round;
use function sha1;
use function strtolower;
use function substr;
use function usort;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Grafts cosmetic models onto a player's skin geometry and packs their textures into unused
 * space of the skin texture.
 *
 * Works on both persona skins (poly_mesh bones, normalized UVs, persona slot bones such as
 * "helmet"/"bodyarmor"/"leftitem") and classic skins (cube bones with texel UVs). A layer whose
 * slot bone exists can replace whatever the client baked into it, which is how a persona back
 * item gets swapped for one of ours.
 */
final class SkinComposer{

	private const DEFAULT_GEOMETRY = "geometry.humanoid.custom";

	public function __construct(
		private GeometryModel $fallbackWide,
		private GeometryModel $fallbackSlim,
		private int $maxTextureSize = 512
	){}

	/**
	 * @param CosmeticLayer[] $layers
	 * @throws GeometryException if the base skin itself cannot be used
	 */
	public function compose(ComposableSkin $skin, array $layers) : CompositionResult{
		$patch = self::decodeObject($skin->resourcePatchJson);
		$defaultId = is_string($patch["geometry"]["default"] ?? null) ? $patch["geometry"]["default"] : self::DEFAULT_GEOMETRY;

		[$file, $model] = $this->resolveBaseGeometry($skin, $defaultId);

		$width = $skin->image->getWidth();
		$height = $skin->image->getHeight();
		$unitsToPixelsX = $width / $model->getTextureWidth();
		$unitsToPixelsY = $height / $model->getTextureHeight();
		if($unitsToPixelsX <= 0 || $unitsToPixelsY <= 0){
			throw new GeometryException("Skin geometry declares an invalid texture size");
		}

		$skipped = [];
		//texture area of pieces we remove; reusable even though its pixels are still opaque
		$freed = new OccupancyMap($width, $height);

		//1. resolve attach bones and strip replaced slots before measuring free space, so a
		//   replaced persona piece's texture area can be reused by our cosmetic
		$attach = [];
		foreach($layers as $layer){
			$slot = $model->findBoneName($layer->slotBone);
			if($slot !== null){
				$attach[$layer->key] = [$slot, false];
				if($layer->replaceSlot){
					self::markRegions($freed, $model, $model->collectSubtree($layer->slotBone), $unitsToPixelsX, $unitsToPixelsY, $width, $height);
					foreach($file->models as $candidate){
						self::stripBones($candidate, $candidate->collectSubtree($layer->slotBone));
					}
				}
			}else{
				foreach($layer->fallbackBones as $fallback){
					$name = $model->findBoneName($fallback);
					if($name !== null){
						$attach[$layer->key] = [$name, true];
						break;
					}
				}
				if(!isset($attach[$layer->key])){
					$skipped[$layer->key] = "the skin has no '{$layer->slotBone}' bone or any fallback bone";
					continue;
				}
			}
			if($layer->stripBones !== []){
				$names = [];
				foreach($layer->stripBones as $bone){
					$names[strtolower($bone)] = true;
				}
				self::markRegions($freed, $model, $names, $unitsToPixelsX, $unitsToPixelsY, $width, $height);
				foreach($file->models as $candidate){
					self::stripBones($candidate, $names);
				}
			}
		}

		//2. measure which texture pixels are in use: everything the remaining geometry samples,
		//   plus any other painted pixel (the client sometimes bakes texture areas that no bone of
		//   the default geometry references), except the area of pieces stripped above
		$occupancy = new OccupancyMap($width, $height);
		foreach($model->bones as $bone){
			foreach(UvRegionCalculator::forBone($bone, $unitsToPixelsX, $unitsToPixelsY, $width, $height) ?? [] as [$x0, $y0, $x1, $y1]){
				$occupancy->mark($x0, $y0, $x1, $y1);
			}
		}
		$occupancy->markOpaquePixels($skin->image, $freed);

		//3. plan texture placement, growing the canvas (doubling both sides) when nothing fits
		$align = max(1, (int) round($unitsToPixelsX));
		$placeable = [];
		foreach($layers as $layer){
			if(!isset($attach[$layer->key])){
				continue;
			}
			$targetW = $layer->model->getTextureWidth() * $unitsToPixelsX;
			$targetH = $layer->model->getTextureHeight() * $unitsToPixelsY;
			if(abs($targetW - round($targetW)) > 0.01 || abs($targetH - round($targetH)) > 0.01 || $targetW < 1 || $targetH < 1){
				$skipped[$layer->key] = "its texture size does not match the skin's pixel density";
				continue;
			}
			$placeable[] = [$layer, (int) round($targetW), (int) round($targetH)];
		}
		usort($placeable, static fn(array $a, array $b) : int => ($b[1] * $b[2]) <=> ($a[1] * $a[2]));

		$canvasW = $width;
		$canvasH = $height;
		$placements = [];
		foreach($placeable as [$layer, $w, $h]){
			//grow a trial copy so a layer that cannot fit even at the limit does not enlarge the skin
			$trial = $occupancy;
			$trialW = $canvasW;
			$trialH = $canvasH;
			$position = $trial->findFree($w, $h, $align);
			while($position === null && $trialW * 2 <= $this->maxTextureSize && $trialH * 2 <= $this->maxTextureSize){
				$trialW *= 2;
				$trialH *= 2;
				$trial = $trial->grown($trialW, $trialH);
				$position = $trial->findFree($w, $h, $align);
			}
			if($position === null){
				$skipped[$layer->key] = "there is no free space left in the skin texture (limit {$this->maxTextureSize}px)";
				continue;
			}
			$occupancy = $trial;
			$canvasW = $trialW;
			$canvasH = $trialH;
			$occupancy->mark($position[0], $position[1], $position[0] + $w, $position[1] + $h);
			$placements[] = [$layer, $w, $h, $position[0], $position[1]];
		}

		//4. rescale the base geometry if the canvas grew, then build the texture
		$scale = $canvasW / $width;
		if($scale > 1){
			self::growBaseModel($model, $scale);
		}
		$image = $skin->image->withCanvasSize($canvasW, $canvasH);

		//5. graft the cosmetic bones
		foreach($placements as [$layer, $w, $h, $x, $y]){
			$image->paste($layer->texture->resized($w, $h), $x, $y);
			[$attachBone, $isFallback] = $attach[$layer->key];
			$offset = $layer->offset;
			if($isFallback && $skin->slim){
				$offset = [$offset[0] + $layer->slimOffset[0], $offset[1] + $layer->slimOffset[1], $offset[2] + $layer->slimOffset[2]];
			}
			$this->graft($model, $layer, $attachBone, $offset, $x / $unitsToPixelsX, $y / $unitsToPixelsY, $x, $y, $w, $h, $canvasW, $canvasH);
		}

		if($placements !== []){
			self::expandVisibleBounds($model);
		}

		$identifier = "geometry.nkc_" . substr(sha1(json_encode($model->toArray(), JSON_THROW_ON_ERROR)), 0, 16);
		$model->identifier = $identifier;
		$patch["geometry"] = is_array($patch["geometry"] ?? null) ? $patch["geometry"] : [];
		$patch["geometry"]["default"] = $identifier;

		return new CompositionResult(
			$image,
			$file->toJson(),
			json_encode($patch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			$identifier,
			$skipped
		);
	}

	/**
	 * @return array{GeometryFile, GeometryModel}
	 */
	private function resolveBaseGeometry(ComposableSkin $skin, string $defaultId) : array{
		$file = null;
		$json = $skin->geometryJson;
		if($json !== "" && $json !== "{}" && $json !== "null"){
			try{
				$file = GeometryFile::parse($json);
			}catch(GeometryException){
				$file = null;
			}
		}
		$model = $file?->find($defaultId);
		if($file === null || $model === null || $model->bones === []){
			//classic skins may rely on the client's built-in humanoid geometry and send none
			$fallback = $skin->slim ? $this->fallbackSlim : $this->fallbackWide;
			$model = new GeometryModel($fallback->identifier, $fallback->description, $fallback->bones);
			$file ??= new GeometryFile(GeometryFile::MIN_MODERN_FORMAT, []);
			$file->models[] = $model;
		}
		return [$file, $model];
	}

	/**
	 * Removes renderable geometry (not the bones themselves) from the named bones.
	 *
	 * @param array<string, true> $names lowercase bone names
	 */
	private static function stripBones(GeometryModel $model, array $names) : void{
		foreach($model->bones as $index => $bone){
			if(isset($names[strtolower((string) $bone["name"])])){
				unset($bone["cubes"], $bone["poly_mesh"], $bone["texture_meshes"]);
				$model->bones[$index] = $bone;
			}
		}
	}

	/**
	 * @param array<string, true> $names lowercase bone names
	 */
	private static function markRegions(OccupancyMap $map, GeometryModel $model, array $names, float $unitsToPixelsX, float $unitsToPixelsY, int $width, int $height) : void{
		foreach($model->bones as $bone){
			if(!isset($names[strtolower((string) $bone["name"])])){
				continue;
			}
			foreach(UvRegionCalculator::forBone($bone, $unitsToPixelsX, $unitsToPixelsY, $width, $height) ?? [] as [$x0, $y0, $x1, $y1]){
				$map->mark($x0, $y0, $x1, $y1);
			}
		}
	}

	private static function growBaseModel(GeometryModel $model, float $scale) : void{
		$model->description["texture_width"] = $model->getTextureWidth() * $scale;
		$model->description["texture_height"] = $model->getTextureHeight() * $scale;
		foreach($model->bones as $index => $bone){
			if(!is_array($bone["poly_mesh"] ?? null) || ($bone["poly_mesh"]["normalized_uvs"] ?? false) !== true || !is_array($bone["poly_mesh"]["uvs"] ?? null)){
				continue;
			}
			//texel UVs keep their meaning because texture_width grew with the image;
			//normalized UVs must shrink towards the top-left where the original image stays
			foreach($bone["poly_mesh"]["uvs"] as $i => $uv){
				if(is_array($uv) && is_numeric($uv[0] ?? null) && is_numeric($uv[1] ?? null)){
					$bone["poly_mesh"]["uvs"][$i] = [(float) $uv[0] / $scale, 1 - (1 - (float) $uv[1]) / $scale];
				}
			}
			$model->bones[$index] = $bone;
		}
	}

	/**
	 * @param array{float, float, float} $offset
	 */
	private function graft(GeometryModel $model, CosmeticLayer $layer, string $attachBone, array $offset, float $uvOffsetU, float $uvOffsetV, int $pixelX, int $pixelY, int $pixelW, int $pixelH, int $canvasW, int $canvasH) : void{
		$prefix = "nk_" . $layer->key . "_";

		//bones without geometry that share a name with a skin bone are treated as the player
		//skeleton from the modelling template, and collapse onto the attach bone
		$collapsed = [];
		$copied = [];
		foreach($layer->model->bones as $bone){
			$name = (string) $bone["name"];
			if(!GeometryModel::boneHasGeometry($bone) && $model->findBoneIndex($name) !== null){
				$collapsed[strtolower($name)] = true;
			}else{
				$copied[strtolower($name)] = $name;
			}
		}

		foreach($layer->model->bones as $bone){
			$lower = strtolower((string) $bone["name"]);
			if(isset($collapsed[$lower])){
				continue;
			}
			$bone["name"] = $prefix . $bone["name"];
			$parent = is_string($bone["parent"] ?? null) ? strtolower($bone["parent"]) : null;
			$bone["parent"] = $parent !== null && isset($copied[$parent]) ? $prefix . $copied[$parent] : $attachBone;

			if(($pivot = UvRegionCalculator::vec3($bone["pivot"] ?? null)) !== null){
				$bone["pivot"] = self::add($pivot, $offset);
			}
			if(is_array($bone["cubes"] ?? null)){
				foreach($bone["cubes"] as $i => $cube){
					if(!is_array($cube)){
						continue;
					}
					if(($origin = UvRegionCalculator::vec3($cube["origin"] ?? null)) !== null){
						$cube["origin"] = self::add($origin, $offset);
					}
					if(($cubePivot = UvRegionCalculator::vec3($cube["pivot"] ?? null)) !== null){
						$cube["pivot"] = self::add($cubePivot, $offset);
					}
					$cube["uv"] = self::offsetCubeUv($cube["uv"] ?? [0, 0], $uvOffsetU, $uvOffsetV);
					$bone["cubes"][$i] = $cube;
				}
			}
			if(is_array($bone["poly_mesh"] ?? null)){
				$bone["poly_mesh"] = self::transformPolyMesh($bone["poly_mesh"], $offset, $uvOffsetU, $uvOffsetV, $pixelX, $pixelY, $pixelW, $pixelH, $canvasW, $canvasH);
			}
			if(is_array($bone["locators"] ?? null)){
				foreach($bone["locators"] as $locatorName => $locator){
					if(($position = UvRegionCalculator::vec3($locator)) !== null){
						$bone["locators"][$locatorName] = self::add($position, $offset);
					}elseif(is_array($locator) && ($position = UvRegionCalculator::vec3($locator["offset"] ?? null)) !== null){
						$bone["locators"][$locatorName]["offset"] = self::add($position, $offset);
					}
				}
			}
			$model->bones[] = $bone;
		}
	}

	private static function offsetCubeUv(mixed $uv, float $du, float $dv) : mixed{
		if(!is_array($uv)){
			return $uv;
		}
		if(isset($uv[0], $uv[1]) && is_numeric($uv[0]) && is_numeric($uv[1])){
			return [(float) $uv[0] + $du, (float) $uv[1] + $dv];
		}
		foreach($uv as $face => $mapping){
			if(is_array($mapping) && is_array($mapping["uv"] ?? null) && is_numeric($mapping["uv"][0] ?? null) && is_numeric($mapping["uv"][1] ?? null)){
				$uv[$face]["uv"] = [(float) $mapping["uv"][0] + $du, (float) $mapping["uv"][1] + $dv];
			}
		}
		return $uv;
	}

	/**
	 * @param mixed[]                    $mesh
	 * @param array{float, float, float} $offset
	 * @return mixed[]
	 */
	private static function transformPolyMesh(array $mesh, array $offset, float $du, float $dv, int $pixelX, int $pixelY, int $pixelW, int $pixelH, int $canvasW, int $canvasH) : array{
		if(is_array($mesh["positions"] ?? null)){
			foreach($mesh["positions"] as $i => $position){
				if(($vec = UvRegionCalculator::vec3($position)) !== null){
					$mesh["positions"][$i] = self::add($vec, $offset);
				}
			}
		}
		$normalized = ($mesh["normalized_uvs"] ?? false) === true;
		if(is_array($mesh["uvs"] ?? null)){
			foreach($mesh["uvs"] as $i => $uv){
				if(!is_array($uv) || !is_numeric($uv[0] ?? null) || !is_numeric($uv[1] ?? null)){
					continue;
				}
				[$u, $v] = [(float) $uv[0], (float) $uv[1]];
				$mesh["uvs"][$i] = $normalized
					? [($pixelX + $u * $pixelW) / $canvasW, 1 - ($pixelY + (1 - $v) * $pixelH) / $canvasH]
					: [$u + $du, $v + $dv];
			}
		}
		return $mesh;
	}

	/**
	 * Grows visible bounds so that cosmetics sticking out of the player box are not culled.
	 */
	private static function expandVisibleBounds(GeometryModel $model) : void{
		$maxHorizontal = 8.0;
		$maxY = 32.0;
		foreach($model->bones as $bone){
			foreach((is_array($bone["cubes"] ?? null) ? $bone["cubes"] : []) as $cube){
				$origin = is_array($cube) ? UvRegionCalculator::vec3($cube["origin"] ?? null) : null;
				$size = is_array($cube) ? UvRegionCalculator::vec3($cube["size"] ?? null) : null;
				if($origin === null || $size === null){
					continue;
				}
				$maxHorizontal = max($maxHorizontal, abs($origin[0]), abs($origin[0] + $size[0]), abs($origin[2]), abs($origin[2] + $size[2]));
				$maxY = max($maxY, $origin[1] + $size[1]);
			}
			foreach((is_array($bone["poly_mesh"]["positions"] ?? null) ? $bone["poly_mesh"]["positions"] : []) as $position){
				if(($vec = UvRegionCalculator::vec3($position)) !== null){
					$maxHorizontal = max($maxHorizontal, abs($vec[0]), abs($vec[2]));
					$maxY = max($maxY, $vec[1]);
				}
			}
		}
		$width = ceil(($maxHorizontal * 2 / 16 + 0.5) * 2) / 2;
		$height = ceil(($maxY / 16 + 0.5) * 2) / 2;
		$existingWidth = $model->description["visible_bounds_width"] ?? 0;
		$existingHeight = $model->description["visible_bounds_height"] ?? 0;
		$model->description["visible_bounds_width"] = max(is_numeric($existingWidth) ? (float) $existingWidth : 0.0, $width);
		$model->description["visible_bounds_height"] = max(is_numeric($existingHeight) ? (float) $existingHeight : 0.0, $height);
		if(!array_key_exists("visible_bounds_offset", $model->description)){
			$model->description["visible_bounds_offset"] = [0.0, $model->description["visible_bounds_height"] / 2, 0.0];
		}
	}

	/**
	 * @param array{float, float, float} $a
	 * @param array{float, float, float} $b
	 * @return array{float, float, float}
	 */
	private static function add(array $a, array $b) : array{
		return [$a[0] + $b[0], $a[1] + $b[1], $a[2] + $b[2]];
	}

	/**
	 * @return mixed[]
	 */
	private static function decodeObject(string $json) : array{
		$decoded = json_decode($json, true);
		return is_array($decoded) ? $decoded : [];
	}
}
