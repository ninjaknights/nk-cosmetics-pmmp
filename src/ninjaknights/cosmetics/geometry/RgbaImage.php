<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

use function chr;
use function function_exists;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecolorat;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imageistruecolor;
use function imagepalettetotruecolor;
use function imagepng;
use function imagesavealpha;
use function imagesetpixel;
use function imagesx;
use function imagesy;
use function intdiv;
use function ob_get_clean;
use function ob_start;
use function ord;
use function str_repeat;
use function strlen;
use function substr;
use function substr_replace;

/**
 * Mutable RGBA8 image in the same byte layout as Bedrock skin data (row-major, 4 bytes per pixel).
 */
final class RgbaImage{

	/** @var positive-int */
	private int $width;
	/** @var positive-int */
	private int $height;
	private string $data;

	public function __construct(int $width, int $height, string $data){
		if($width <= 0 || $height <= 0){
			throw new GeometryException("Image dimensions must be positive, got {$width}x{$height}");
		}
		if(strlen($data) !== $width * $height * 4){
			throw new GeometryException("Image data must be " . ($width * $height * 4) . " bytes for {$width}x{$height}, got " . strlen($data));
		}
		$this->width = $width;
		$this->height = $height;
		$this->data = $data;
	}

	public static function blank(int $width, int $height) : self{
		return new self($width, $height, str_repeat("\x00", $width * $height * 4));
	}

	/**
	 * @throws GeometryException
	 */
	public static function fromPng(string $png) : self{
		if(!function_exists("imagecreatefromstring")){
			throw new GeometryException("The GD extension is required to read PNG textures");
		}
		$image = @imagecreatefromstring($png);
		if($image === false){
			throw new GeometryException("Texture is not a readable PNG image");
		}
		if(!imageistruecolor($image)){
			imagepalettetotruecolor($image);
		}
		$width = imagesx($image);
		$height = imagesy($image);
		$data = "";
		for($y = 0; $y < $height; ++$y){
			for($x = 0; $x < $width; ++$x){
				$color = imagecolorat($image, $x, $y);
				$gdAlpha = ($color >> 24) & 0x7f; //0 = opaque, 127 = transparent
				$alpha = $gdAlpha === 0 ? 255 : ($gdAlpha === 127 ? 0 : 255 - intdiv($gdAlpha * 255, 127));
				$data .= chr(($color >> 16) & 0xff) . chr(($color >> 8) & 0xff) . chr($color & 0xff) . chr($alpha);
			}
		}
		return new self($width, $height, $data);
	}

	public function getWidth() : int{
		return $this->width;
	}

	public function getHeight() : int{
		return $this->height;
	}

	public function getData() : string{
		return $this->data;
	}

	public function getAlpha(int $x, int $y) : int{
		return ord($this->data[(($y * $this->width) + $x) * 4 + 3]);
	}

	/**
	 * Returns a copy of this image placed at the top-left of a larger transparent canvas.
	 */
	public function withCanvasSize(int $width, int $height) : self{
		if($width < $this->width || $height < $this->height){
			throw new GeometryException("Canvas {$width}x{$height} is smaller than the image {$this->width}x{$this->height}");
		}
		$result = self::blank($width, $height);
		$result->paste($this, 0, 0);
		return $result;
	}

	/**
	 * Copies $source onto this image with its top-left corner at ($x, $y), replacing pixels.
	 */
	public function paste(RgbaImage $source, int $x, int $y) : void{
		if($x < 0 || $y < 0 || $x + $source->width > $this->width || $y + $source->height > $this->height){
			throw new GeometryException("Cannot paste {$source->width}x{$source->height} at ($x,$y) into {$this->width}x{$this->height}");
		}
		$rowBytes = $source->width * 4;
		for($row = 0; $row < $source->height; ++$row){
			$this->data = substr_replace($this->data, substr($source->data, $row * $rowBytes, $rowBytes), (($y + $row) * $this->width + $x) * 4, $rowBytes);
		}
	}

	/**
	 * Nearest-neighbour resample to the given size. Used to match a cosmetic texture's pixel density to the skin's.
	 */
	public function resized(int $width, int $height) : self{
		if($width === $this->width && $height === $this->height){
			return clone $this;
		}
		$data = "";
		for($y = 0; $y < $height; ++$y){
			$sourceY = intdiv($y * $this->height, $height);
			for($x = 0; $x < $width; ++$x){
				$sourceX = intdiv($x * $this->width, $width);
				$data .= substr($this->data, ($sourceY * $this->width + $sourceX) * 4, 4);
			}
		}
		return new self($width, $height, $data);
	}

	public function toPng() : string{
		$image = imagecreatetruecolor($this->width, $this->height);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		$i = 0;
		for($y = 0; $y < $this->height; ++$y){
			for($x = 0; $x < $this->width; ++$x){
				$color = imagecolorallocatealpha($image, ord($this->data[$i]), ord($this->data[$i + 1]), ord($this->data[$i + 2]), 127 - (ord($this->data[$i + 3]) >> 1));
				imagesetpixel($image, $x, $y, $color === false ? 0 : $color);
				$i += 4;
			}
		}
		ob_start();
		imagepng($image);
		return (string) ob_get_clean();
	}
}
