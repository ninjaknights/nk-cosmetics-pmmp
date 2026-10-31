<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\manager;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\storage\PlayerRecord;
use pocketmine\player\Player;

/**
 * In-memory cosmetic state of an online player. Changes are flagged dirty and written by the
 * session manager outside of any render loop.
 */
final class PlayerSession{

	private bool $dirty = false;

	public function __construct(
		private Player $player,
		private PlayerRecord $record
	){}

	public function getPlayer() : Player{
		return $this->player;
	}

	public function getRecord() : PlayerRecord{
		return $this->record;
	}

	public function getEquippedId(CosmeticCategory $category) : ?string{
		return $this->record->equipped[$category->value] ?? null;
	}

	public function setEquipped(CosmeticCategory $category, ?string $id) : void{
		if($id === null){
			unset($this->record->equipped[$category->value]);
		}else{
			$this->record->equipped[$category->value] = $id;
		}
		$this->dirty = true;
	}

	public function hasUnlock(string $id) : bool{
		return isset($this->record->unlocks[$id]);
	}

	public function addUnlock(string $id, int $time) : void{
		$this->record->unlocks[$id] = $time;
		$this->dirty = true;
	}

	public function removeUnlock(string $id) : void{
		unset($this->record->unlocks[$id]);
		$this->dirty = true;
	}

	public function getPreference(string $name, bool $default = true) : bool{
		return $this->record->getPreference($name, $default);
	}

	public function setPreference(string $name, bool $value) : void{
		$this->record->preferences[$name] = $value;
		$this->dirty = true;
	}

	/**
	 * Flags the record as changed after it was modified directly through getRecord().
	 */
	public function markDirty() : void{
		$this->dirty = true;
	}

	public function isDirty() : bool{
		return $this->dirty;
	}

	public function markClean() : void{
		$this->dirty = false;
	}
}
