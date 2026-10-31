<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\resourcepack;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function ltrim;
use function rename;
use function str_replace;
use function strlen;
use function substr;
use function unlink;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Packages the editable resource pack folder into an .mcpack.
 *
 * Bedrock clients cache packs by UUID and version, so the patch version is bumped every time the
 * folder content changes; otherwise players would keep the old models after an update.
 */
final class ResourcePackBuilder{

	/**
	 * @return array{uuid: string, version: string, rebuilt: bool}
	 * @throws \RuntimeException
	 */
	public static function build(string $sourceDir, string $outputFile, string $stateFile) : array{
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			/** @var \SplFileInfo $file */
			if($file->isFile()){
				$relative = ltrim(str_replace("\\", "/", substr($file->getPathname(), strlen($sourceDir))), "/");
				$files[$relative] = $file->getPathname();
			}
		}
		ksort($files);
		if(!isset($files["manifest.json"])){
			throw new \RuntimeException("$sourceDir has no manifest.json");
		}
		$manifest = json_decode((string) file_get_contents($files["manifest.json"]), true);
		if(!is_array($manifest) || !is_string($manifest["header"]["uuid"] ?? null)){
			throw new \RuntimeException("$sourceDir/manifest.json is not a valid pack manifest");
		}

		$hash = hash_init("sha256");
		foreach($files as $relative => $path){
			hash_update($hash, $relative . "\x00" . file_get_contents($path) . "\x00");
		}
		$digest = hash_final($hash);

		$state = file_exists($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
		$counter = is_array($state) && is_int($state["counter"] ?? null) ? $state["counter"] : 0;
		$rebuild = !is_array($state) || ($state["hash"] ?? null) !== $digest || !file_exists($outputFile);
		if(is_array($state) && ($state["hash"] ?? null) !== $digest){
			++$counter;
		}

		$baseVersion = is_array($manifest["header"]["version"] ?? null) ? $manifest["header"]["version"] : [1, 0, 0];
		$version = [(int) ($baseVersion[0] ?? 1), (int) ($baseVersion[1] ?? 0), (int) ($baseVersion[2] ?? 0) + $counter];
		$manifest["header"]["version"] = $version;
		foreach(is_array($manifest["modules"] ?? null) ? $manifest["modules"] : [] as $index => $module){
			if(is_array($module)){
				$manifest["modules"][$index]["version"] = $version;
			}
		}

		if($rebuild){
			$temp = $outputFile . ".tmp";
			$zip = new \ZipArchive();
			if($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
				throw new \RuntimeException("Unable to write $temp");
			}
			foreach($files as $relative => $path){
				if($relative === "manifest.json"){
					$zip->addFromString($relative, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
				}else{
					$zip->addFile($path, $relative);
				}
			}
			$zip->close();
			if(file_exists($outputFile)){
				unlink($outputFile);
			}
			rename($temp, $outputFile);
			file_put_contents($stateFile, json_encode(["hash" => $digest, "counter" => $counter], JSON_THROW_ON_ERROR));
		}

		return ["uuid" => (string) $manifest["header"]["uuid"], "version" => implode(".", $version), "rebuilt" => $rebuild];
	}
}
