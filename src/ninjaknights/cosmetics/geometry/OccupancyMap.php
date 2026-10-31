<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

use function ceil;
use function floor;
use function max;
use function min;
use function str_repeat;
use function substr_replace;

/**
 * Per-pixel record of which parts of a texture are in use, with a free-rectangle search.
 */
final class OccupancyMap{

	private string $cells;

	public function __construct(
		private int $width,
		private int $height
	){
		$this->cells = str_repeat("\x00", $width * $height);
	}

	public function getWidth() : int{
		return $this->width;
	}

	public function getHeight() : int{
		return $this->height;
	}

	/**
	 * Marks the pixels covered by a (possibly fractional) rectangle, rounding outwards.
	 */
	public function mark(float $x0, float $y0, float $x1, float $y1) : void{
		$startX = max(0, (int) floor(min($x0, $x1)));
		$startY = max(0, (int) floor(min($y0, $y1)));
		$endX = min($this->width, (int) ceil(max($x0, $x1)));
		$endY = min($this->height, (int) ceil(max($y0, $y1)));
		if($endX <= $startX || $endY <= $startY){
			return;
		}
		$row = str_repeat("\x01", $endX - $startX);
		for($y = $startY; $y < $endY; ++$y){
			$this->cells = substr_replace($this->cells, $row, $y * $this->width + $startX, $endX - $startX);
		}
	}

	/**
	 * Marks every non-transparent pixel, except those set in $exclude.
	 */
	public function markOpaquePixels(RgbaImage $image, ?OccupancyMap $exclude = null) : void{
		$maxX = min($image->getWidth(), $this->width);
		$maxY = min($image->getHeight(), $this->height);
		for($y = 0; $y < $maxY; ++$y){
			for($x = 0; $x < $maxX; ++$x){
				if($image->getAlpha($x, $y) !== 0 && ($exclude === null || !$exclude->isUsed($x, $y))){
					$this->cells[$y * $this->width + $x] = "\x01";
				}
			}
		}
	}

	public function isUsed(int $x, int $y) : bool{
		return $this->cells[$y * $this->width + $x] === "\x01";
	}

	/**
	 * Returns a copy of this map on a larger canvas; the added area is free.
	 */
	public function grown(int $width, int $height) : self{
		$result = new self($width, $height);
		for($y = 0; $y < $this->height; ++$y){
			for($x = 0; $x < $this->width; ++$x){
				if($this->cells[$y * $this->width + $x] === "\x01"){
					$result->cells[$y * $width + $x] = "\x01";
				}
			}
		}
		return $result;
	}

	/**
	 * Finds the top-most, then left-most free position for a w x h rectangle whose corner lies on
	 * multiples of $align. Uses a summed-area table so each candidate is checked in O(1).
	 *
	 * @return array{int, int}|null
	 */
	public function findFree(int $w, int $h, int $align = 1) : ?array{
		if($w <= 0 || $h <= 0 || $w > $this->width || $h > $this->height){
			return null;
		}
		$stride = $this->width + 1;
		$sums = [];
		for($x = 0; $x <= $this->width; ++$x){
			$sums[$x] = 0;
		}
		for($y = 1; $y <= $this->height; ++$y){
			$rowSum = 0;
			$sums[$y * $stride] = 0;
			$base = ($y - 1) * $this->width;
			for($x = 1; $x <= $this->width; ++$x){
				$rowSum += $this->cells[$base + $x - 1] === "\x01" ? 1 : 0;
				$sums[$y * $stride + $x] = $sums[($y - 1) * $stride + $x] + $rowSum;
			}
		}
		$align = max(1, $align);
		for($y = 0; $y + $h <= $this->height; $y += $align){
			for($x = 0; $x + $w <= $this->width; $x += $align){
				$used = $sums[($y + $h) * $stride + $x + $w] - $sums[$y * $stride + $x + $w] - $sums[($y + $h) * $stride + $x] + $sums[$y * $stride + $x];
				if($used === 0){
					return [$x, $y];
				}
			}
		}
		return null;
	}
}
