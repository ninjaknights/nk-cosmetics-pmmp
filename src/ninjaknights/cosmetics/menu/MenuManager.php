<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\menu;

use dktapps\pmforms\CustomForm;
use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\Label;
use dktapps\pmforms\element\Toggle;
use dktapps\pmforms\MenuForm;
use dktapps\pmforms\MenuOption;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\event\ChangeCause;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\manager\EquipResult;
use ninjaknights\cosmetics\PluginSettings;
use ninjaknights\cosmetics\storage\PlayerRecord;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use function array_slice;
use function ceil;
use function count;
use function max;
use function min;
use function usort;

/**
 * The /cosmetics forms: main menu, paginated category lists, cosmetic details and settings.
 */
final class MenuManager{

	public function __construct(
		private CosmeticManager $cosmetics,
		private PluginSettings $settings
	){}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
	}

	public function openMain(Player $player) : void{
		$categories = [];
		$options = [];
		foreach(CosmeticCategory::ordered() as $category){
			if(!$this->cosmetics->isCategoryAvailable($category)){
				continue;
			}
			$all = $this->listCategory($player, $category);
			$unlocked = 0;
			foreach($all as $definition){
				if($this->cosmetics->isUnlocked($player, $definition)){
					++$unlocked;
				}
			}
			$equipped = $this->cosmetics->getEquipped($player, $category);
			$status = $equipped !== null ? TF::DARK_GREEN . $equipped->name : TF::DARK_GRAY . "$unlocked/" . count($all) . " unlocked";
			$categories[] = $category;
			$options[] = new MenuOption(TF::BOLD . $category->getDisplayName() . TF::RESET . "\n" . $status);
		}
		$options[] = new MenuOption(TF::BOLD . "Settings" . TF::RESET . "\n" . TF::DARK_GRAY . "Visibility");
		$options[] = new MenuOption(TF::BOLD . TF::DARK_RED . "Unequip all");

		$player->sendForm(new MenuForm(
			"Cosmetics",
			$this->cosmetics->isWorldAllowed($player) ? "Pick a category." : TF::RED . "Cosmetics are disabled in this world.",
			$options,
			function(Player $player, int $selected) use ($categories) : void{
				if(isset($categories[$selected])){
					$this->openCategory($player, $categories[$selected], 0);
				}elseif($selected === count($categories)){
					$this->openSettings($player);
				}else{
					$count = $this->cosmetics->clear($player, ChangeCause::PLAYER);
					$player->sendMessage(TF::GREEN . "Unequipped $count cosmetic" . ($count === 1 ? "" : "s") . ".");
				}
			}
		));
	}

	/**
	 * @return list<CosmeticDefinition>
	 */
	private function listCategory(Player $player, CosmeticCategory $category) : array{
		$list = $this->cosmetics->getRegistry()->getByCategory($category, true);
		//unlocked first, then by rarity, then by name
		usort($list, function(CosmeticDefinition $a, CosmeticDefinition $b) use ($player) : int{
			return [!$this->cosmetics->isUnlocked($player, $a), $a->rarity->ordinal(), $a->name] <=> [!$this->cosmetics->isUnlocked($player, $b), $b->rarity->ordinal(), $b->name];
		});
		return $list;
	}

	public function openCategory(Player $player, CosmeticCategory $category, int $page) : void{
		$all = $this->listCategory($player, $category);
		$pageSize = $this->settings->getMenuPageSize();
		$pages = max(1, (int) ceil(count($all) / $pageSize));
		$page = max(0, min($page, $pages - 1));
		$items = array_slice($all, $page * $pageSize, $pageSize);
		$equipped = $this->cosmetics->getEquipped($player, $category);

		$actions = [];
		$options = [];
		foreach($items as $definition){
			$options[] = new MenuOption($this->label($player, $definition, $equipped));
			$actions[] = fn() => $this->openDetails($player, $definition, $page);
		}
		if($page > 0){
			$options[] = new MenuOption("« Previous page");
			$actions[] = fn() => $this->openCategory($player, $category, $page - 1);
		}
		if($page < $pages - 1){
			$options[] = new MenuOption("Next page »");
			$actions[] = fn() => $this->openCategory($player, $category, $page + 1);
		}
		if($equipped !== null){
			$options[] = new MenuOption(TF::DARK_RED . "Unequip " . $equipped->name);
			$actions[] = function() use ($player, $category) : void{
				$this->cosmetics->unequip($player, $category, ChangeCause::PLAYER);
				$this->openCategory($player, $category, 0);
			};
		}
		$options[] = new MenuOption("Back");
		$actions[] = fn() => $this->openMain($player);

		$text = count($all) === 0 ? "There are no " . $category->getDisplayName() . " yet." : "Page " . ($page + 1) . " of $pages";
		$player->sendForm(new MenuForm($category->getDisplayName(), $text, $options, function(Player $player, int $selected) use ($actions) : void{
			if(isset($actions[$selected])){
				($actions[$selected])();
			}
		}));
	}

	private function label(Player $player, CosmeticDefinition $definition, ?CosmeticDefinition $equipped) : string{
		if($equipped !== null && $equipped->id === $definition->id){
			$status = TF::DARK_GREEN . "Equipped";
		}elseif($this->cosmetics->isUnlocked($player, $definition)){
			$status = TF::DARK_GRAY . "Unlocked";
		}else{
			$status = TF::RED . "Locked";
		}
		return $definition->rarity->getColor() . $definition->name . TF::RESET . "\n" . $definition->rarity->getDisplayName() . TF::DARK_GRAY . " | " . $status;
	}

	public function openDetails(Player $player, CosmeticDefinition $definition, int $returnPage) : void{
		$unlocked = $this->cosmetics->isUnlocked($player, $definition);
		$isEquipped = $this->cosmetics->getEquipped($player, $definition->category)?->id === $definition->id;

		$lines = [
			$definition->rarity->getColor() . $definition->rarity->getDisplayName() . " " . $definition->category->getSingularName(),
		];
		if($definition->description !== ""){
			$lines[] = TF::RESET . $definition->description;
		}
		$lines[] = "";
		if($isEquipped){
			$lines[] = TF::GREEN . "Equipped";
		}elseif($unlocked){
			$lines[] = TF::GRAY . "Unlocked";
		}else{
			$lines[] = TF::RED . "Locked";
			if($definition->permissionUnlocks){
				$lines[] = TF::GRAY . "Unlocked by the permission " . TF::WHITE . $definition->permission;
			}
		}

		$options = [];
		$actions = [];
		if($isEquipped){
			$options[] = new MenuOption(TF::DARK_RED . "Unequip");
			$actions[] = function() use ($player, $definition, $returnPage) : void{
				$this->cosmetics->unequip($player, $definition->category, ChangeCause::PLAYER);
				$this->openCategory($player, $definition->category, $returnPage);
			};
		}elseif($unlocked){
			$options[] = new MenuOption(TF::DARK_GREEN . "Equip");
			$actions[] = function() use ($player, $definition, $returnPage) : void{
				$result = $this->cosmetics->equip($player, $definition->id, ChangeCause::PLAYER);
				$player->sendMessage(($result === EquipResult::SUCCESS ? TF::GREEN . "Equipped " . $definition->name . "." : TF::RED . $result->getMessage()));
				$this->openCategory($player, $definition->category, $returnPage);
			};
		}
		$options[] = new MenuOption("Back");
		$actions[] = fn() => $this->openCategory($player, $definition->category, $returnPage);

		$player->sendForm(new MenuForm($definition->name, implode("\n", $lines), $options, function(Player $player, int $selected) use ($actions) : void{
			if(isset($actions[$selected])){
				($actions[$selected])();
			}
		}));
	}

	public function openSettings(Player $player) : void{
		$session = $this->cosmetics->getSessions()->get($player);
		if($session === null){
			return;
		}
		$player->sendForm(new CustomForm("Cosmetic settings", [
			new Label("info", "Hidden cosmetics stay equipped."),
			new Toggle("own", "Show my cosmetics", $session->getPreference(PlayerRecord::PREF_SHOW_OWN)),
			new Toggle("others", "Show other players' cosmetics", $session->getPreference(PlayerRecord::PREF_SHOW_OTHERS))
		], function(Player $player, CustomFormResponse $response) : void{
			$this->cosmetics->setShowOwn($player, $response->getBool("own"));
			$this->cosmetics->setShowOthers($player, $response->getBool("others"));
			$player->sendMessage(TF::GREEN . "Cosmetic settings saved.");
		}));
	}
}
