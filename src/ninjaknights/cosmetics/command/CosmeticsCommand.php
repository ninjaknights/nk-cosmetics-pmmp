<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\command;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\event\ChangeCause;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\manager\EquipResult;
use ninjaknights\cosmetics\menu\MenuManager;
use ninjaknights\cosmetics\renderer\GadgetRenderer;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\utils\TextFormat as TF;
use function count;
use function strtolower;

/**
 * /cosmetics [equipped|clear|help|use|equip <id>|unequip <category>]
 */
final class CosmeticsCommand extends Command implements PluginOwned{
	use PluginOwnedTrait;

	public const PERMISSION = "nkcosmetics.command.cosmetics";

	public function __construct(
		Plugin $plugin,
		private CosmeticManager $cosmetics,
		private MenuManager $menus,
		private GadgetRenderer $gadgets
	){
		parent::__construct("cosmetics", "Open the cosmetics menu", "/cosmetics [equipped|clear|help|use|equip <id>|unequip <category>]", ["cosmetic"]);
		$this->owningPlugin = $plugin;
		$this->setPermission(self::PERMISSION);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage(TF::RED . "This command can only be used in game. Use /nkcosmetics to manage cosmetics.");
			return true;
		}
		$sub = strtolower($args[0] ?? "");
		switch($sub){
			case "use":
				$this->gadgets->activate($sender);
				return true;
			case "":
			case "menu":
				$this->menus->openMain($sender);
				return true;
			case "help":
				$sender->sendMessage(TF::GOLD . "Cosmetics commands:\n" .
					TF::YELLOW . "/cosmetics" . TF::WHITE . " - open the menu\n" .
					TF::YELLOW . "/cosmetics equipped" . TF::WHITE . " - list what you are wearing\n" .
					TF::YELLOW . "/cosmetics use" . TF::WHITE . " - activate your equipped gadget\n" .
					TF::YELLOW . "/cosmetics equip <id>" . TF::WHITE . " - equip a cosmetic\n" .
					TF::YELLOW . "/cosmetics unequip <category>" . TF::WHITE . " - remove one category\n" .
					TF::YELLOW . "/cosmetics clear" . TF::WHITE . " - remove all cosmetics");
				return true;
			case "equipped":
				$equipped = $this->cosmetics->getAllEquipped($sender);
				if($equipped === []){
					$sender->sendMessage(TF::GRAY . "You have no cosmetics equipped.");
					return true;
				}
				$lines = [TF::GOLD . "Equipped cosmetics:"];
				foreach($equipped as $definition){
					$lines[] = TF::YELLOW . $definition->category->getSingularName() . ": " . $definition->rarity->getColor() . $definition->name . TF::DARK_GRAY . " (" . $definition->id . ")";
				}
				if(!$this->cosmetics->isVisible($sender)){
					$lines[] = TF::GRAY . "They are currently hidden (settings or world rules).";
				}
				$sender->sendMessage(implode("\n", $lines));
				return true;
			case "clear":
				$count = $this->cosmetics->clear($sender, ChangeCause::PLAYER);
				$sender->sendMessage(TF::GREEN . "Unequipped $count cosmetic" . ($count === 1 ? "" : "s") . ".");
				return true;
			case "equip":
				if(count($args) !== 2){
					$sender->sendMessage(TF::RED . "Usage: /cosmetics equip <id>");
					return true;
				}
				$id = strtolower($args[1]);
				$result = $this->cosmetics->equip($sender, $id, ChangeCause::PLAYER);
				$definition = $this->cosmetics->getRegistry()->get($id);
				$sender->sendMessage($result === EquipResult::SUCCESS && $definition !== null ? TF::GREEN . "Equipped " . $definition->name . "." : TF::RED . $result->getMessage());
				return true;
			case "unequip":
				$category = CosmeticCategory::tryFrom(strtolower($args[1] ?? ""));
				if($category === null){
					$sender->sendMessage(TF::RED . "Usage: /cosmetics unequip <hat|back|offhand|balloon|pet|particle|gadget>");
					return true;
				}
				$sender->sendMessage($this->cosmetics->unequip($sender, $category, ChangeCause::PLAYER) ? TF::GREEN . "Unequipped your " . strtolower($category->getSingularName()) . "." : TF::GRAY . "Nothing to unequip.");
				return true;
			default:
				$sender->sendMessage(TF::RED . "Unknown subcommand. Use /cosmetics help");
				return true;
		}
	}
}
