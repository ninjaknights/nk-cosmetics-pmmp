<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\resourcepack;

use ninjaknights\cosmetics\geometry\GeometryException;
use ninjaknights\cosmetics\geometry\GeometryFile;
use ninjaknights\cosmetics\geometry\GeometryModel;
use ninjaknights\cosmetics\geometry\RgbaImage;
use function array_keys;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_replace;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Index of the assets in the cosmetics resource pack (a directory or a .zip/.mcpack).
 *
 * The server reads hat, back and offhand geometry and textures from here to graft them onto
 * skins; balloon assets are only validated here because the client renders them itself.
 */
final class ResourcePackAssetRegistry{

	/** @var array<string, array{string, GeometryModel}> geometry identifier => [file, model] */
	private array $geometries = [];
	/** @var array<string, string> texture path without extension => file */
	private array $textures = [];
	/** @var array<string, string> client entity identifier => file */
	private array $clientEntities = [];
	/** @var array<string, string> animation identifier => file */
	private array $animations = [];
	/** @var array<string, RgbaImage> */
	private array $decodedTextures = [];
	/** @var list<string> */
	private array $problems = [];

	private ?string $packUuid = null;

	/**
	 * @param array<string, string> $files pack-relative path (forward slashes) => contents
	 */
	private function __construct(
		private string $origin,
		private array $files
	){
		$this->index();
	}

	/**
	 * @throws \RuntimeException if the path is neither a directory nor a readable zip
	 */
	public static function load(string $path) : self{
		if(is_dir($path)){
			return new self($path, self::readDirectory($path));
		}
		if(is_file($path)){
			return new self($path, self::readZip($path));
		}
		throw new \RuntimeException("Resource pack not found at $path");
	}

	/**
	 * @param array<string, string> $files
	 */
	public static function fromFiles(string $origin, array $files) : self{
		return new self($origin, $files);
	}

	/**
	 * @return array<string, string>
	 */
	private static function readDirectory(string $root) : array{
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			/** @var \SplFileInfo $file */
			if(!$file->isFile()){
				continue;
			}
			$relative = ltrim(str_replace("\\", "/", substr($file->getPathname(), strlen($root))), "/");
			$contents = file_get_contents($file->getPathname());
			if($contents !== false){
				$files[$relative] = $contents;
			}
		}
		return $files;
	}

	/**
	 * @return array<string, string>
	 */
	private static function readZip(string $path) : array{
		$zip = new \ZipArchive();
		if($zip->open($path) !== true){
			throw new \RuntimeException("Unable to open resource pack archive $path");
		}
		$files = [];
		$prefix = null;
		for($i = 0; $i < $zip->numFiles; ++$i){
			$name = $zip->getNameIndex($i);
			if($name === false || str_ends_with($name, "/")){
				continue;
			}
			$contents = $zip->getFromIndex($i);
			if($contents !== false){
				$files[str_replace("\\", "/", $name)] = $contents;
			}
			if(str_ends_with($name, "manifest.json") && ($prefix === null || strlen($name) < strlen($prefix . "manifest.json"))){
				$prefix = substr($name, 0, -strlen("manifest.json"));
			}
		}
		$zip->close();
		//packs zipped with their root folder inside: strip the folder so paths are pack-relative
		if($prefix !== null && $prefix !== ""){
			$stripped = [];
			foreach($files as $name => $contents){
				if(str_starts_with($name, $prefix)){
					$stripped[substr($name, strlen($prefix))] = $contents;
				}
			}
			$files = $stripped;
		}
		return $files;
	}

	private function index() : void{
		foreach($this->files as $path => $contents){
			$lower = strtolower($path);
			if($lower === "manifest.json"){
				$manifest = json_decode($contents, true);
				$uuid = is_array($manifest) ? ($manifest["header"]["uuid"] ?? null) : null;
				$this->packUuid = is_string($uuid) ? $uuid : null;
				if($this->packUuid === null){
					$this->problems[] = "manifest.json has no header.uuid";
				}
			}elseif(str_starts_with($lower, "models/") && str_ends_with($lower, ".json")){
				try{
					foreach(GeometryFile::parse(self::stripComments($contents))->models as $model){
						if(isset($this->geometries[$model->identifier])){
							$this->problems[] = "Geometry {$model->identifier} is defined in both {$this->geometries[$model->identifier][0]} and $path";
						}
						$this->geometries[$model->identifier] = [$path, $model];
					}
				}catch(GeometryException $e){
					$this->problems[] = "$path: " . $e->getMessage();
				}
			}elseif(str_starts_with($lower, "textures/") && (str_ends_with($lower, ".png") || str_ends_with($lower, ".tga"))){
				$this->textures[substr($path, 0, -4)] = $path;
			}elseif(str_starts_with($lower, "entity/") && str_ends_with($lower, ".json")){
				$decoded = json_decode(self::stripComments($contents), true);
				$identifier = is_array($decoded) ? ($decoded["minecraft:client_entity"]["description"]["identifier"] ?? null) : null;
				if(is_string($identifier)){
					$this->clientEntities[$identifier] = $path;
				}else{
					$this->problems[] = "$path is not a client entity definition";
				}
			}elseif(str_starts_with($lower, "animations/") && str_ends_with($lower, ".json")){
				$decoded = json_decode(self::stripComments($contents), true);
				foreach(is_array($decoded["animations"] ?? null) ? array_keys($decoded["animations"]) : [] as $id){
					$this->animations[(string) $id] = $path;
				}
			}
		}
	}

	/**
	 * Bedrock JSON files may contain // comments.
	 */
	private static function stripComments(string $json) : string{
		return (string) preg_replace('~^\s*//.*$~m', "", $json);
	}

	public function getOrigin() : string{
		return $this->origin;
	}

	public function getPackUuid() : ?string{
		return $this->packUuid;
	}

	/**
	 * @return list<string>
	 */
	public function getProblems() : array{
		return $this->problems;
	}

	public function getGeometry(string $identifier) : ?GeometryModel{
		return $this->geometries[$identifier][1] ?? null;
	}

	public function hasTexture(string $path) : bool{
		return isset($this->textures[$path]);
	}

	public function isPngTexture(string $path) : bool{
		return isset($this->textures[$path]) && str_ends_with(strtolower($this->textures[$path]), ".png");
	}

	/**
	 * @throws GeometryException
	 */
	public function getTexture(string $path) : RgbaImage{
		if(isset($this->decodedTextures[$path])){
			return $this->decodedTextures[$path];
		}
		$file = $this->textures[$path] ?? null;
		if($file === null){
			throw new GeometryException("Texture $path is not in the resource pack");
		}
		if(!str_ends_with(strtolower($file), ".png")){
			throw new GeometryException("Texture $file must be a PNG to be used on skins");
		}
		return $this->decodedTextures[$path] = RgbaImage::fromPng($this->files[$file]);
	}

	public function hasClientEntity(string $identifier) : bool{
		return isset($this->clientEntities[$identifier]);
	}

	public function hasAnimation(string $identifier) : bool{
		return isset($this->animations[$identifier]);
	}

	/**
	 * @return list<string>
	 */
	public function getClientEntityIdentifiers() : array{
		return array_keys($this->clientEntities);
	}
}
