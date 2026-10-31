<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\manager;

use ninjaknights\cosmetics\storage\CosmeticStorage;
use ninjaknights\cosmetics\storage\PlayerRecord;
use ninjaknights\cosmetics\storage\StorageException;
use pocketmine\player\Player;
use function spl_object_id;
use function strtolower;

/**
 * Loads cosmetic state when a player joins, keeps it cached while online and saves it on
 * autosave, quit and shutdown.
 */
final class SessionManager{

	/** @var array<int, PlayerSession> */
	private array $sessions = [];

	public function __construct(
		private CosmeticStorage $storage,
		private \Logger $logger
	){}

	public function getStorage() : CosmeticStorage{
		return $this->storage;
	}

	/**
	 * Stable identifier used as the storage key: the Xbox user id when the server authenticates
	 * players, otherwise the lowercase name.
	 */
	public static function getStorageKey(Player $player) : string{
		$xuid = $player->getXuid();
		return $xuid !== "" ? "xuid:$xuid" : "name:" . strtolower($player->getName());
	}

	public function open(Player $player) : PlayerSession{
		$key = self::getStorageKey($player);
		try{
			$record = $this->storage->load($key, $player->getName());
		}catch(StorageException $e){
			$this->logger->error("Could not load cosmetics of {$player->getName()}; they start empty this session: " . $e->getMessage());
			$record = new PlayerRecord($key, $player->getName());
		}
		$record->lastName = $player->getName();
		$session = new PlayerSession($player, $record);
		$this->sessions[spl_object_id($player)] = $session;
		return $session;
	}

	public function get(Player $player) : ?PlayerSession{
		return $this->sessions[spl_object_id($player)] ?? null;
	}

	/**
	 * @return array<int, PlayerSession>
	 */
	public function getAll() : array{
		return $this->sessions;
	}

	public function close(Player $player) : void{
		$session = $this->sessions[spl_object_id($player)] ?? null;
		if($session === null){
			return;
		}
		$this->save($session, true);
		unset($this->sessions[spl_object_id($player)]);
	}

	public function save(PlayerSession $session, bool $force = false) : void{
		if(!$force && !$session->isDirty()){
			return;
		}
		try{
			$this->storage->save($session->getRecord());
			$session->markClean();
		}catch(StorageException $e){
			$this->logger->error($e->getMessage());
		}
	}

	public function saveAll() : void{
		foreach($this->sessions as $session){
			$this->save($session);
		}
	}
}
