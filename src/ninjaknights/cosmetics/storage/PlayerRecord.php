<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\storage;

/**
 * Persisted cosmetic state of one player.
 */
final class PlayerRecord{

	public const PREF_SHOW_OWN = "show-own";
	public const PREF_SHOW_OTHERS = "show-others";

	/**
	 * @param array<string, int>    $unlocks     cosmetic id => unix time granted
	 * @param array<string, string> $equipped    category value => cosmetic id
	 * @param array<string, bool>   $preferences
	 * @param array<string, mixed>  $settings    selected cosmetic settings (free-form, JSON-serialisable)
	 */
	public function __construct(
		public readonly string $key,
		public string $lastName,
		public array $unlocks = [],
		public array $equipped = [],
		public array $preferences = [],
		public array $settings = []
	){}

	public function getPreference(string $name, bool $default = true) : bool{
		return $this->preferences[$name] ?? $default;
	}
}
