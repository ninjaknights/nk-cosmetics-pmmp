<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\listener;

use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\skin\SkinGeometryManager;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\player\Player;
use pocketmine\Server;

/**
 * Sends the original skin instead of the composed one to players who chose not to see other
 * players' cosmetics. Skins are broadcast in shared packets, so a batch going to a mix of
 * viewers is split and re-sent per viewer.
 */
final class SkinVisibilityListener implements Listener{

	public function __construct(
		private CosmeticManager $cosmetics,
		private SkinGeometryManager $skins
	){}

	/**
	 * @priority HIGHEST
	 */
	public function onPacketSend(DataPacketSendEvent $event) : void{
		$packets = $event->getPackets();
		if(!self::carriesSkins($packets)){
			return;
		}
		$targets = $event->getTargets();
		$hiders = [];
		foreach($targets as $index => $session){
			$viewer = $session->getPlayer();
			if($viewer !== null && !$this->cosmetics->showsOthers($viewer)){
				$hiders[$index] = $viewer;
			}
		}
		if($hiders === []){
			return;
		}
		if(count($targets) === 1){
			$event->setPackets($this->rewrite($packets, $hiders[array_key_first($hiders)]));
			return;
		}
		//mixed audience: deliver separately so each viewer gets the right skins
		$event->cancel();
		foreach($targets as $index => $session){
			$send = isset($hiders[$index]) ? $this->rewrite($packets, $hiders[$index]) : $packets;
			foreach($send as $packet){
				$session->sendDataPacket($packet);
			}
		}
	}

	/**
	 * @param ClientboundPacket[] $packets
	 */
	private static function carriesSkins(array $packets) : bool{
		foreach($packets as $packet){
			if($packet instanceof PlayerSkinPacket || $packet instanceof PlayerListPacket){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param ClientboundPacket[] $packets
	 * @return ClientboundPacket[]
	 */
	private function rewrite(array $packets, Player $viewer) : array{
		$server = Server::getInstance();
		$result = [];
		foreach($packets as $packet){
			if($packet instanceof PlayerSkinPacket){
				$owner = $server->getPlayerByUUID($packet->uuid);
				$original = $owner !== null && $owner !== $viewer ? $this->skins->getOriginalDataFor($owner) : null;
				$result[] = $original !== null ? PlayerSkinPacket::create($packet->uuid, $packet->oldSkinName, $packet->newSkinName, $original) : $packet;
			}elseif($packet instanceof PlayerListPacket){
				$entries = [];
				$changed = false;
				foreach($packet->getEntries() as $entry){
					if($entry->type === PlayerListPacket::TYPE_ADD){
						$owner = $server->getPlayerByUUID($entry->uuid);
						$original = $owner !== null && $owner !== $viewer ? $this->skins->getOriginalDataFor($owner) : null;
						if($original !== null){
							$copy = clone $entry;
							$copy->skinData = $original;
							$entries[] = $copy;
							$changed = true;
							continue;
						}
					}
					$entries[] = $entry;
				}
				$result[] = $changed ? PlayerListPacket::create($entries) : $packet;
			}else{
				$result[] = $packet;
			}
		}
		return $result;
	}

	/**
	 * Re-sends every composed skin to a viewer after they toggled seeing others' cosmetics.
	 */
	public function resendTo(Player $viewer) : void{
		foreach(Server::getInstance()->getOnlinePlayers() as $owner){
			if($owner !== $viewer && $this->skins->hasComposedSkin($owner)){
				$owner->sendSkin([$viewer]);
			}
		}
	}
}
