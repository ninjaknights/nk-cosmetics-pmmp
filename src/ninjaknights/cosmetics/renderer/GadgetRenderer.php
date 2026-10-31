<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\renderer;

use ninjaknights\cosmetics\builtin\gadgets\GadgetCooldowns;
use ninjaknights\cosmetics\builtin\gadgets\GadgetType;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\PluginSettings;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

final class GadgetRenderer implements CosmeticRendererInterface{
	private const TAG = "NKCosmeticsGadget";
	/** @var array<int, Item> */
	private array $tokens = [];
	private GadgetCooldowns $cooldowns;
	private int $emissionTick = -1;
	private int $emissions = 0;

	/** @param \Closure() : int $currentTick */
	public function __construct(private CosmeticManager $cosmetics, private PluginSettings $settings, private \Closure $currentTick){
		$this->cooldowns = new GadgetCooldowns();
	}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
	}

	public function getCategory() : CosmeticCategory{
		return CosmeticCategory::GADGET;
	}

	public static function isToken(Item $item) : bool{
		return $item->getNamedTag()->getTag(self::TAG) !== null;
	}

	public function show(Player $player, CosmeticDefinition $definition) : void{
		$this->hide($player);
		if(!$player->isAlive() || !$player->isConnected() || GadgetType::tryFrom($definition->id) === null){
			return;
		}
		$slot = $this->settings->getCategoryInt(CosmeticCategory::GADGET, "hotbar-slot", 8, 0, 8);
		if(!$player->getInventory()->getItem($slot)->isNull()){
			$player->sendMessage(TF::YELLOW . "Your gadget slot is occupied. Use /cosmetics use, or free slot " . ($slot + 1) . " and re-equip.");
			return;
		}
		$item = VanillaItems::BLAZE_ROD()->setCustomName(TF::GOLD . $definition->name)->setLore([TF::GRAY . "Use to activate. /cosmetics use also works."]);
		$item->getNamedTag()->setString(self::TAG, bin2hex(random_bytes(16)));
		$this->tokens[spl_object_id($player)] = clone $item;
		$player->getInventory()->setItem($slot, $item);
	}

	/** Remove tagged tools only. No normal item is stored, replaced, or restored. */
	public function hide(Player $player) : void{
		unset($this->tokens[spl_object_id($player)]);
		foreach([$player->getInventory(), $player->getOffHandInventory(), $player->getCursorInventory(), $player->getArmorInventory()] as $inventory){
			foreach($inventory->getContents() as $slot => $item){
				if(self::isToken($item)){
					$inventory->clear($slot);
				}
			}
		}
	}

	public function forget(Player $player) : void{
		$this->hide($player);
		$this->cooldowns->forget(spl_object_id($player));
	}

	public function clearCache() : void{}

	public function isActiveToken(Player $player, Item $item) : bool{
		$token = $this->tokens[spl_object_id($player)] ?? null;
		return $token !== null && $token->equalsExact($item) && $token->equalsExact($player->getInventory()->getItemInHand());
	}

	public function activate(Player $player) : bool{
		$tick = ($this->currentTick)();
		$definition = $this->cosmetics->getEquipped($player, CosmeticCategory::GADGET);
		if(!$player->isConnected() || !$player->isAlive() || $player->isSpectator() || $definition === null || !$this->cosmetics->canUse($player, $definition) || !$this->cosmetics->isVisible($player)){
			$player->sendMessage(TF::RED . "Equip an available gadget and enable your cosmetics in an allowed world first.");
			return false;
		}
		$type = GadgetType::tryFrom($definition->id);
		if($type === null){
			return false;
		}
		$id = spl_object_id($player);
		$remaining = $this->cooldowns->remaining($id, $tick);
		if($remaining > 0){
			$player->sendTip(TF::YELLOW . "Gadget ready in " . (int) ceil($remaining / 20.0) . "s");
			return false;
		}
		$samples = $type->samples($player->getDirectionVector());
		if($this->emissionTick !== $tick){
			$this->emissionTick = $tick;
			$this->emissions = 0;
		}
		if($this->emissions + count($samples) > 72){
			$player->sendTip(TF::YELLOW . "Gadgets are busy. Try again in a moment.");
			return false;
		}
		$viewers = [];
		$position = $player->getPosition();
		foreach($player->getWorld()->getPlayers() as $viewer){
			if($viewer->getPosition()->distanceSquared($position) <= 24 ** 2 && ($viewer === $player || ($viewer->canSee($player) && $this->cosmetics->showsOthers($viewer)))){
				$viewers[] = $viewer;
			}
		}
		$this->cooldowns->start($id, $tick, $this->settings->getGadgetCooldownSeconds($type->value, $type->cooldownSeconds()));
		$this->emissions += count($samples);
		foreach($samples as [$offset, $particle]){
			$player->getWorld()->addParticle($position->addVector($offset), $particle, $viewers);
		}
		return true;
	}
}
