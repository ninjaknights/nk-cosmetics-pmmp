<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\listener;

use ninjaknights\cosmetics\renderer\GadgetRenderer;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\player\Player;

final class GadgetListener implements Listener{
	public function __construct(private GadgetRenderer $gadgets){}

	/** @priority HIGH */
	public function onUse(PlayerItemUseEvent $event) : void{
		if(GadgetRenderer::isToken($event->getItem())){
			$event->cancel();
			if($this->gadgets->isActiveToken($event->getPlayer(), $event->getItem())){
				$this->gadgets->activate($event->getPlayer());
			}
		}
	}

	/** @priority HIGH */
	public function onInteract(PlayerInteractEvent $event) : void{
		if(GadgetRenderer::isToken($event->getItem())){
			$event->cancel();
			if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK && $this->gadgets->isActiveToken($event->getPlayer(), $event->getItem())){
				$this->gadgets->activate($event->getPlayer());
			}
		}
	}

	/** @priority HIGHEST */
	public function onTransaction(InventoryTransactionEvent $event) : void{
		foreach($event->getTransaction()->getActions() as $action){
			if(GadgetRenderer::isToken($action->getSourceItem()) || GadgetRenderer::isToken($action->getTargetItem())){
				$event->cancel();
				return;
			}
		}
	}

	public function onDrop(PlayerDropItemEvent $event) : void{
		if(GadgetRenderer::isToken($event->getItem())){
			$event->cancel();
		}
	}

	public function onDamage(EntityDamageByEntityEvent $event) : void{
		$damager = $event->getDamager();
		if($damager instanceof Player && GadgetRenderer::isToken($damager->getInventory()->getItemInHand())){
			$event->cancel();
		}
	}

	public function onBreak(BlockBreakEvent $event) : void{
		if(GadgetRenderer::isToken($event->getItem())){
			$event->cancel();
		}
	}

	/** @priority HIGHEST */
	public function onDeath(PlayerDeathEvent $event) : void{
		$event->setDrops(array_values(array_filter($event->getDrops(), static fn($item) : bool => !GadgetRenderer::isToken($item))));
	}

	/** @priority LOWEST */
	public function onJoin(PlayerJoinEvent $event) : void{
		$this->gadgets->hide($event->getPlayer());
	}

	/** @priority MONITOR */
	public function onQuit(PlayerQuitEvent $event) : void{
		$this->gadgets->forget($event->getPlayer());
	}
}
