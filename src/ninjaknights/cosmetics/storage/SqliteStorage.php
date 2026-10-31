<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\storage;

use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function json_encode;
use function strtolower;
use function time;
use const JSON_THROW_ON_ERROR;
use const SQLITE3_ASSOC;
use const SQLITE3_INTEGER;
use const SQLITE3_TEXT;

/**
 * SQLite backend. Saves run in a single transaction; the schema is versioned and migrated on open.
 */
final class SqliteStorage implements CosmeticStorage{

	/**
	 * Schema migrations, applied in order. Never edit a released step; append a new one.
	 *
	 * @var array<int, list<string>>
	 */
	private const MIGRATIONS = [
		1 => [
			"CREATE TABLE players (
				player_key TEXT PRIMARY KEY NOT NULL,
				last_name TEXT NOT NULL,
				last_name_lower TEXT NOT NULL,
				preferences TEXT NOT NULL DEFAULT '{}',
				settings TEXT NOT NULL DEFAULT '{}',
				updated_at INTEGER NOT NULL
			)",
			"CREATE INDEX idx_players_name ON players (last_name_lower)",
			"CREATE TABLE unlocks (
				player_key TEXT NOT NULL,
				cosmetic_id TEXT NOT NULL,
				granted_at INTEGER NOT NULL,
				PRIMARY KEY (player_key, cosmetic_id)
			)",
			"CREATE TABLE equipped (
				player_key TEXT NOT NULL,
				category TEXT NOT NULL,
				cosmetic_id TEXT NOT NULL,
				PRIMARY KEY (player_key, category)
			)"
		]
	];

	private \SQLite3 $db;

	/**
	 * @throws StorageException
	 */
	public function __construct(string $path){
		try{
			$this->db = new \SQLite3($path);
			$this->db->enableExceptions(true);
			$this->db->busyTimeout(5000);
			$this->db->exec("PRAGMA journal_mode = WAL");
			$this->db->exec("PRAGMA synchronous = NORMAL");
			$this->migrate();
		}catch(\Exception $e){
			throw new StorageException("Unable to open SQLite database $path: " . $e->getMessage(), 0, $e);
		}
	}

	public function getSchemaVersion() : int{
		return (int) $this->db->querySingle("PRAGMA user_version");
	}

	private function migrate() : void{
		$current = $this->getSchemaVersion();
		foreach(self::MIGRATIONS as $version => $statements){
			if($version <= $current){
				continue;
			}
			$this->db->exec("BEGIN IMMEDIATE");
			try{
				foreach($statements as $statement){
					$this->db->exec($statement);
				}
				$this->db->exec("PRAGMA user_version = $version");
				$this->db->exec("COMMIT");
			}catch(\Exception $e){
				$this->db->exec("ROLLBACK");
				throw $e;
			}
		}
	}

	private function prepare(string $sql) : \SQLite3Stmt{
		$statement = $this->db->prepare($sql);
		if($statement === false){
			throw new StorageException("Could not prepare query: " . $this->db->lastErrorMsg());
		}
		return $statement;
	}

	private function run(\SQLite3Stmt $statement) : \SQLite3Result{
		$result = $statement->execute();
		if($result === false){
			throw new StorageException("Query failed: " . $this->db->lastErrorMsg());
		}
		return $result;
	}

	public function load(string $key, string $name) : PlayerRecord{
		try{
			$record = new PlayerRecord($key, $name);

			$statement = $this->prepare("SELECT preferences, settings FROM players WHERE player_key = :key");
			$statement->bindValue(":key", $key, SQLITE3_TEXT);
			$row = $this->run($statement)->fetchArray(SQLITE3_ASSOC);
			if(is_array($row)){
				foreach(self::decodeMap($row["preferences"]) as $pref => $value){
					if(is_bool($value)){
						$record->preferences[(string) $pref] = $value;
					}
				}
				foreach(self::decodeMap($row["settings"]) as $setting => $value){
					$record->settings[(string) $setting] = $value;
				}
			}

			$statement = $this->prepare("SELECT cosmetic_id, granted_at FROM unlocks WHERE player_key = :key");
			$statement->bindValue(":key", $key, SQLITE3_TEXT);
			$result = $this->run($statement);
			while(is_array($row = $result->fetchArray(SQLITE3_ASSOC))){
				$record->unlocks[(string) $row["cosmetic_id"]] = (int) $row["granted_at"];
			}

			$statement = $this->prepare("SELECT category, cosmetic_id FROM equipped WHERE player_key = :key");
			$statement->bindValue(":key", $key, SQLITE3_TEXT);
			$result = $this->run($statement);
			while(is_array($row = $result->fetchArray(SQLITE3_ASSOC))){
				$record->equipped[(string) $row["category"]] = (string) $row["cosmetic_id"];
			}
			return $record;
		}catch(\Exception $e){
			throw new StorageException("Failed to load cosmetics of $name: " . $e->getMessage(), 0, $e);
		}
	}

	public function save(PlayerRecord $record) : void{
		try{
			$this->db->exec("BEGIN IMMEDIATE");
			$statement = $this->prepare("INSERT INTO players (player_key, last_name, last_name_lower, preferences, settings, updated_at)
				VALUES (:key, :name, :lower, :prefs, :settings, :now)
				ON CONFLICT(player_key) DO UPDATE SET last_name = excluded.last_name, last_name_lower = excluded.last_name_lower,
					preferences = excluded.preferences, settings = excluded.settings, updated_at = excluded.updated_at");
			$statement->bindValue(":key", $record->key, SQLITE3_TEXT);
			$statement->bindValue(":name", $record->lastName, SQLITE3_TEXT);
			$statement->bindValue(":lower", strtolower($record->lastName), SQLITE3_TEXT);
			$statement->bindValue(":prefs", json_encode((object) $record->preferences, JSON_THROW_ON_ERROR), SQLITE3_TEXT);
			$statement->bindValue(":settings", json_encode((object) $record->settings, JSON_THROW_ON_ERROR), SQLITE3_TEXT);
			$statement->bindValue(":now", time(), SQLITE3_INTEGER);
			$this->run($statement);

			foreach(["unlocks", "equipped"] as $table){
				$delete = $this->prepare("DELETE FROM $table WHERE player_key = :key");
				$delete->bindValue(":key", $record->key, SQLITE3_TEXT);
				$this->run($delete);
			}
			$insert = $this->prepare("INSERT INTO unlocks (player_key, cosmetic_id, granted_at) VALUES (:key, :id, :at)");
			foreach($record->unlocks as $id => $at){
				$insert->bindValue(":key", $record->key, SQLITE3_TEXT);
				$insert->bindValue(":id", $id, SQLITE3_TEXT);
				$insert->bindValue(":at", $at, SQLITE3_INTEGER);
				$this->run($insert);
				$insert->reset();
			}
			$insert = $this->prepare("INSERT INTO equipped (player_key, category, cosmetic_id) VALUES (:key, :category, :id)");
			foreach($record->equipped as $category => $id){
				$insert->bindValue(":key", $record->key, SQLITE3_TEXT);
				$insert->bindValue(":category", $category, SQLITE3_TEXT);
				$insert->bindValue(":id", $id, SQLITE3_TEXT);
				$this->run($insert);
				$insert->reset();
			}
			$this->db->exec("COMMIT");
		}catch(\Exception $e){
			$this->rollback();
			throw new StorageException("Failed to save cosmetics of {$record->lastName}: " . $e->getMessage(), 0, $e);
		}
	}

	public function findKeyByName(string $name) : ?string{
		try{
			$statement = $this->prepare("SELECT player_key FROM players WHERE last_name_lower = :name ORDER BY updated_at DESC LIMIT 1");
			$statement->bindValue(":name", strtolower($name), SQLITE3_TEXT);
			$row = $this->run($statement)->fetchArray(SQLITE3_ASSOC);
			return is_array($row) && is_string($row["player_key"]) ? $row["player_key"] : null;
		}catch(\Exception $e){
			throw new StorageException("Failed to look up $name: " . $e->getMessage(), 0, $e);
		}
	}

	public function purgeCosmetic(string $cosmeticId) : int{
		try{
			$this->db->exec("BEGIN IMMEDIATE");
			$total = 0;
			foreach(["unlocks", "equipped"] as $table){
				$statement = $this->prepare("DELETE FROM $table WHERE cosmetic_id = :id");
				$statement->bindValue(":id", $cosmeticId, SQLITE3_TEXT);
				$this->run($statement);
				$total += $this->db->changes();
			}
			$this->db->exec("COMMIT");
			return $total;
		}catch(\Exception $e){
			$this->rollback();
			throw new StorageException("Failed to purge $cosmeticId: " . $e->getMessage(), 0, $e);
		}
	}

	private function rollback() : void{
		try{
			$this->db->exec("ROLLBACK");
		}catch(\Exception){
			//no transaction was open
		}
	}

	public function close() : void{
		$this->db->close();
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private static function decodeMap(mixed $json) : array{
		if(!is_string($json)){
			return [];
		}
		$decoded = json_decode($json, true);
		return is_array($decoded) ? $decoded : [];
	}
}
