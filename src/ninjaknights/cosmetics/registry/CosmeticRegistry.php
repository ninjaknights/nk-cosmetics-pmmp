<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\registry;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use function array_filter;
use function array_values;
use function preg_match;

/**
 * Every known cosmetic, keyed by id. Ids are unique across all categories and sources.
 */
final class CosmeticRegistry{

	/** @var array<string, CosmeticDefinition> */
	private array $definitions = [];

	/** @internal Replaced from the fixed catalogs when configuration is reloaded. */
	public function clearBuiltins() : void{
		foreach($this->definitions as $id => $definition){
			if($definition->isBuiltin()){
				unset($this->definitions[$id]);
			}
		}
	}

	/**
	 * Registers a hat, back, offhand or balloon definition from the API.
	 *
	 * @throws \InvalidArgumentException for built-in categories, duplicate ids or invalid ids
	 */
	public function registerCustom(CosmeticDefinition $definition) : void{
		if(!$definition->category->isConfigurable()){
			throw new \InvalidArgumentException("{$definition->category->value} cosmetics are built in and cannot be registered");
		}
		if($definition->source === CosmeticDefinition::SOURCE_BUILTIN){
			throw new \InvalidArgumentException("Custom cosmetics cannot use the builtin source");
		}
		$this->add($definition);
	}

	/**
	 * Registers an entry of a built-in catalog. Only called by the plugin itself.
	 *
	 * @internal
	 */
	public function registerBuiltin(CosmeticDefinition $definition) : void{
		if($definition->category->isConfigurable() || $definition->source !== CosmeticDefinition::SOURCE_BUILTIN){
			throw new \InvalidArgumentException("Built-in definitions must be pets, particles or gadgets with the builtin source");
		}
		$this->add($definition);
	}

	private function add(CosmeticDefinition $definition) : void{
		if(preg_match(YamlCosmeticLoader::ID_PATTERN, $definition->id) !== 1){
			throw new \InvalidArgumentException("Invalid cosmetic id '{$definition->id}'");
		}
		if(isset($this->definitions[$definition->id])){
			$existing = $this->definitions[$definition->id];
			throw new \InvalidArgumentException("Cosmetic id '{$definition->id}' is already used by a {$existing->category->value} from {$existing->source}");
		}
		$this->definitions[$definition->id] = $definition;
	}

	/**
	 * Replaces every YAML definition with a freshly loaded set. Definitions whose id clashes with
	 * an API or built-in cosmetic are rejected and reported.
	 *
	 * @param list<CosmeticDefinition> $definitions
	 * @return list<string> problems
	 */
	public function replaceYamlDefinitions(array $definitions) : array{
		foreach($this->definitions as $id => $definition){
			if($definition->source === CosmeticDefinition::SOURCE_YAML){
				unset($this->definitions[$id]);
			}
		}
		$problems = [];
		foreach($definitions as $definition){
			try{
				$this->add($definition);
			}catch(\InvalidArgumentException $e){
				$problems[] = "[{$definition->id}] " . $e->getMessage();
			}
		}
		return $problems;
	}

	public function unregister(string $id) : bool{
		if(!isset($this->definitions[$id]) || $this->definitions[$id]->isBuiltin()){
			return false;
		}
		unset($this->definitions[$id]);
		return true;
	}

	public function get(string $id) : ?CosmeticDefinition{
		return $this->definitions[$id] ?? null;
	}

	/**
	 * @return array<string, CosmeticDefinition>
	 */
	public function getAll() : array{
		return $this->definitions;
	}

	/**
	 * @return list<CosmeticDefinition>
	 */
	public function getByCategory(CosmeticCategory $category, bool $enabledOnly = false) : array{
		return array_values(array_filter($this->definitions, static fn(CosmeticDefinition $d) : bool => $d->category === $category && (!$enabledOnly || $d->enabled)));
	}
}
