<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\entity;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use function max;

/**
 * Announces custom entity identifiers to clients.
 *
 * Clients only render an entity type they were told about in AvailableActorIdentifiersPacket at
 * login; the look comes from the client_entity definition in the resource pack. No behaviour pack
 * is needed because the server drives the entity. Identifiers added after a player joined only
 * apply to players who join later.
 */
final class CustomEntityIdentifiers{

	/** @var array<string, true> */
	private static array $registered = [];

	public static function register(string $identifier) : bool{
		if(isset(self::$registered[$identifier])){
			return false;
		}
		$packet = StaticPacketCache::getInstance()->getAvailableActorIdentifiers();
		$root = $packet->identifiers->getRoot();
		$list = $root->getListTag("idlist");
		if(!$list instanceof ListTag){
			throw new \RuntimeException("Unexpected AvailableActorIdentifiers layout: no idlist");
		}
		$maxRuntimeId = 0;
		foreach($list as $entry){
			if($entry instanceof CompoundTag){
				if($entry->getString("id", "") === $identifier){
					self::$registered[$identifier] = true;
					return false;
				}
				$maxRuntimeId = max($maxRuntimeId, $entry->getInt("rid", 0));
			}
		}
		$list->push(CompoundTag::create()
			->setString("id", $identifier)
			->setString("bid", "")
			->setInt("rid", $maxRuntimeId + 1)
			->setByte("hasspawnegg", 0)
			->setByte("summonable", 0)
		);
		//re-wrap so the cached encoded NBT is rebuilt
		$packet->identifiers = new CacheableNbt($root);
		self::$registered[$identifier] = true;
		return true;
	}
}
