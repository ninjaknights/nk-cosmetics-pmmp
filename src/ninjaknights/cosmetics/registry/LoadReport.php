<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\registry;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;

/**
 * Outcome of reading a cosmetics/*.yml file: the definitions that passed validation plus every problem
 * found. Errors mean a cosmetic was skipped; warnings mean it loaded with a default applied.
 */
final class LoadReport{

	/** @var list<CosmeticDefinition> */
	public array $definitions = [];
	/** @var list<string> */
	public array $errors = [];
	/** @var list<string> */
	public array $warnings = [];

	public function error(string $id, string $message) : void{
		$this->errors[] = "[$id] $message";
	}

	public function warning(string $id, string $message) : void{
		$this->warnings[] = "[$id] $message";
	}
}
