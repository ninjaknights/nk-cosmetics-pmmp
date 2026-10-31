<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\command;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\event\ChangeCause;
use ninjaknights\cosmetics\Main;
use ninjaknights\cosmetics\manager\CosmeticManager;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\Server;
use pocketmine\utils\TextFormat as TF;
use function count;
use function implode;
use function preg_match;
use function strtolower;

/**
 * /nkcosmetics give|remove|clear|list|reload|debug
 */
final class AdminCommand extends Command implements PluginOwned{
	use PluginOwnedTrait;

	public const PERMISSION_ROOT = "nkcosmetics.command.admin";
	private const PLAYER_NAME = '/^[A-Za-z0-9 _]{1,32}$/';

	private const SUBCOMMANDS = [
		"give" => ["nkcosmetics.admin.give", "/nkcosmetics give <player> <id>"],
		"remove" => ["nkcosmetics.admin.remove", "/nkcosmetics remove <player> <id>"],
		"clear" => ["nkcosmetics.admin.clear", "/nkcosmetics clear <player>"],
		"list" => ["nkcosmetics.admin.list", "/nkcosmetics list [category]"],
		"reload" => ["nkcosmetics.admin.reload", "/nkcosmetics reload"],
		"debug" => ["nkcosmetics.admin.debug", "/nkcosmetics debug <player>"]
	];

	public function __construct(
		private Main $plugin,
		private CosmeticManager $cosmetics
	){
		parent::__construct("nkcosmetics", "Manage NKCosmetics", "/nkcosmetics <give|remove|clear|list|reload|debug>", ["nkc"]);
		$this->owningPlugin = $plugin;
		$this->setPermission(self::PERMISSION_ROOT);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		$sub = strtolower($args[0] ?? "");
		if(!isset(self::SUBCOMMANDS[$sub])){
			$lines = [TF::GOLD . "NKCosmetics admin commands:"];
			foreach(self::SUBCOMMANDS as [$permission, $usage]){
				if($sender->hasPermission($permission)){
					$lines[] = TF::YELLOW . $usage;
				}
			}
			$sender->sendMessage(implode("\n", $lines));
			return true;
		}
		[$permission, $usage] = self::SUBCOMMANDS[$sub];
		if(!$sender->hasPermission($permission)){
			$sender->sendMessage(TF::RED . "You do not have permission to use /nkcosmetics $sub.");
			return true;
		}

		switch($sub){
			case "give":
			case "remove":
				if(count($args) !== 3 || preg_match(self::PLAYER_NAME, $args[1]) !== 1){
					$sender->sendMessage(TF::RED . "Usage: $usage");
					return true;
				}
				$definition = $this->cosmetics->getRegistry()->get(strtolower($args[2]));
				if($definition === null){
					$sender->sendMessage(TF::RED . "Unknown cosmetic '{$args[2]}'. See /nkcosmetics list");
					return true;
				}
				$name = $args[1];
				if($sub === "give"){
					$done = $this->cosmetics->unlock($name, $definition, ChangeCause::ADMIN);
					$sender->sendMessage($done ? TF::GREEN . "Gave {$definition->name} to $name." : TF::RED . "Could not give {$definition->name} to $name (unknown player, already owned, or cancelled by another plugin).");
					if($done){
						Server::getInstance()->getPlayerExact($name)?->sendMessage(TF::GREEN . "You unlocked " . $definition->rarity->getColor() . $definition->name . TF::GREEN . "!");
					}
				}else{
					$done = $this->cosmetics->revoke($name, $definition, ChangeCause::ADMIN);
					$sender->sendMessage($done ? TF::GREEN . "Removed {$definition->name} from $name." : TF::RED . "$name does not have a granted {$definition->name} (permission or default unlocks cannot be removed here).");
				}
				return true;
			case "clear":
				if(count($args) !== 2 || preg_match(self::PLAYER_NAME, $args[1]) !== 1){
					$sender->sendMessage(TF::RED . "Usage: $usage");
					return true;
				}
				$target = Server::getInstance()->getPlayerExact($args[1]);
				if($target instanceof Player){
					$count = $this->cosmetics->clear($target, ChangeCause::ADMIN);
					$sender->sendMessage(TF::GREEN . "Unequipped $count cosmetic(s) from {$target->getName()}.");
				}else{
					$sender->sendMessage($this->cosmetics->clearOffline($args[1]) ? TF::GREEN . "Cleared equipped cosmetics of {$args[1]}." : TF::RED . "{$args[1]} has never joined or has nothing equipped.");
				}
				return true;
			case "list":
				$filter = isset($args[1]) ? CosmeticCategory::tryFrom(strtolower($args[1])) : null;
				if(isset($args[1]) && $filter === null){
					$sender->sendMessage(TF::RED . "Unknown category. Use hat, back, offhand, balloon, pet, particle or gadget.");
					return true;
				}
				$lines = [];
				foreach(CosmeticCategory::ordered() as $category){
					if($filter !== null && $category !== $filter){
						continue;
					}
					$entries = [];
					foreach($this->cosmetics->getRegistry()->getByCategory($category) as $definition){
						$entries[] = ($definition->enabled ? $definition->rarity->getColor() : TF::DARK_GRAY . TF::STRIKETHROUGH) . $definition->id . TF::RESET;
					}
					$lines[] = TF::GOLD . $category->getDisplayName() . " (" . count($entries) . ")" . TF::WHITE . ": " . ($entries === [] ? TF::GRAY . "none" : implode(TF::GRAY . ", ", $entries));
				}
				$sender->sendMessage(implode("\n", $lines));
				return true;
			case "reload":
				$sender->sendMessage(TF::YELLOW . "Reloading NKCosmetics...");
				foreach($this->plugin->reload() as $line){
					$sender->sendMessage($line);
				}
				return true;
			case "debug":
				$target = isset($args[1]) ? Server::getInstance()->getPlayerExact($args[1]) : ($sender instanceof Player ? $sender : null);
				if($target === null){
					$sender->sendMessage(TF::RED . "Usage: $usage (the player must be online)");
					return true;
				}
				$directory = $this->plugin->getDataFolder() . "debug/" . $target->getName();
				foreach($this->plugin->getSkinGeometryManager()->writeDebugDump($target, $directory) as $line){
					$sender->sendMessage(TF::GRAY . $line);
				}
				$sender->sendMessage(TF::GREEN . "Skin files written to $directory");
				return true;
		}
	}
}
