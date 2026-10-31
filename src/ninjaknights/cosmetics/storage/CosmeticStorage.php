<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\storage;

/**
 * Persistence backend for player cosmetic state. Implementations must be safe to call from the
 * main thread and keep each save atomic.
 */
interface CosmeticStorage{

	/**
	 * Returns the stored record, or an empty one if the player has never been saved.
	 *
	 * @throws StorageException
	 */
	public function load(string $key, string $name) : PlayerRecord;

	/**
	 * @throws StorageException
	 */
	public function save(PlayerRecord $record) : void;

	/**
	 * Finds the storage key of a player who has joined before, by case-insensitive name.
	 *
	 * @throws StorageException
	 */
	public function findKeyByName(string $name) : ?string;

	/**
	 * Removes a cosmetic id from every player (unlocks and equipped slots).
	 *
	 * @return int number of affected rows
	 * @throws StorageException
	 */
	public function purgeCosmetic(string $cosmeticId) : int;

	public function close() : void;
}
