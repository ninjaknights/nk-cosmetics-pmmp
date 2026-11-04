<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\menu;

use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\event\ChangeCause;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\manager\EquipResult;
use ninjaknights\cosmetics\menu\form\MenuForm;
use ninjaknights\cosmetics\menu\form\MenuOption;
use ninjaknights\cosmetics\PluginSettings;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\utils\TextFormat as TF;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function max;
use function mb_strimwidth;
use function min;
use function usort;

/**
 * The grid menu drawn by ui/nk_catalog.json. The button indices below and that file's "$index"
 * values must match: JSON UI can only reach a form button by its position.
 */
final class CatalogMenu{

	public const TITLE = "NK Cosmetics";
	public const TITLE_WITH_PREVIEW = "NK Cosmetics Preview";

	public const SETTINGS = 7;
	public const CLEAR = 8;
	public const CLOSE = 9;
	public const PREVIOUS = 10;
	public const NEXT = 11;
	public const PAGE = 12;
	public const FIRST_ITEM = 13;
	public const PAGE_SIZE = 40;

	/** Invisible formatting the layout looks for to draw the Equipped badge or the selected tab. */
	private const WEARING_MARKER = TF::RESET . TF::BOLD . TF::RESET;
	/** Invisible formatting the layout looks for to draw the lock. */
	private const LOCKED_MARKER = TF::RESET . TF::ITALIC . TF::RESET;
	private const MAX_NAME_WIDTH = 20;

	/** @var \WeakMap<Player, int> Stops an old form or a queued refresh replacing a newer menu. */
	private \WeakMap $generations;
	private int $nextGeneration = 0;

	/**
	 * @param \Closure(Player): void                          $openSettings
	 * @param \Closure(Player, CosmeticDefinition, int): void $openDetails
	 */
	public function __construct(
		private CosmeticManager $cosmetics,
		private PluginSettings $settings,
		private ?TaskScheduler $scheduler,
		private \Closure $openSettings,
		private \Closure $openDetails
	){
		$this->generations = new \WeakMap();
	}

	public function setSettings(PluginSettings $settings) : void{
		$this->settings = $settings;
		$this->generations = new \WeakMap();
	}

	public function open(Player $player, CosmeticCategory $category, int $page = 0) : void{
		$generation = ++$this->nextGeneration;
		$this->generations[$player] = $generation;

		$items = $this->sortedItems($player, $category);
		$pages = max(1, (int) ceil(count($items) / self::PAGE_SIZE));
		$page = max(0, min($page, $pages - 1));
		$visible = array_values(array_slice($items, $page * self::PAGE_SIZE, self::PAGE_SIZE));
		$equipped = $this->cosmetics->getEquipped($player, $category);

		$options = [];
		foreach(CosmeticCategory::ordered() as $tab){
			$options[] = new MenuOption($this->tabLabel($tab, $category));
		}
		$options[self::SETTINGS] = new MenuOption("Settings");
		$options[self::CLEAR] = new MenuOption("Unequip all");
		$options[self::CLOSE] = new MenuOption("X");
		$options[self::PREVIOUS] = new MenuOption($pages === 1 ? "" : ($page > 0 ? "" : TF::DARK_GRAY) . "< Previous");
		$options[self::NEXT] = new MenuOption($pages === 1 ? "" : ($page + 1 < $pages ? "" : TF::DARK_GRAY) . "Next >");
		$options[self::PAGE] = new MenuOption($pages === 1 ? "" : "Page " . ($page + 1) . " / " . $pages);
		for($slot = 0; $slot < self::PAGE_SIZE; ++$slot){
			$item = $visible[$slot] ?? null;
			$options[self::FIRST_ITEM + $slot] = $item === null ? new MenuOption("") : $this->card($player, $item, $equipped);
		}

		$player->sendForm(new MenuForm(
			$this->settings->isPlayerPreviewShown() ? self::TITLE_WITH_PREVIEW : self::TITLE,
			$this->header($player, $category, count($items)),
			$options,
			function(Player $player, int $selected) use ($category, $page, $visible, $generation) : void{
				if(($this->generations[$player] ?? 0) === $generation){
					$this->handle($player, $selected, $category, $page, $visible, $generation);
				}
			},
			function(Player $player) use ($generation) : void{
				if(($this->generations[$player] ?? 0) === $generation){
					unset($this->generations[$player]);
				}
			}
		));
	}

	/**
	 * @param list<CosmeticDefinition> $visible
	 */
	private function handle(Player $player, int $selected, CosmeticCategory $category, int $page, array $visible, int $generation) : void{
		$tab = CosmeticCategory::ordered()[$selected] ?? null;
		if($tab !== null){
			$this->open($player, $this->cosmetics->isCategoryAvailable($tab) ? $tab : $category);
			return;
		}
		if($selected === self::PREVIOUS || $selected === self::NEXT){
			$this->open($player, $category, $page + ($selected === self::NEXT ? 1 : -1));
			return;
		}
		if($selected === self::SETTINGS || $selected === self::CLOSE){
			unset($this->generations[$player]);
			if($selected === self::SETTINGS){
				($this->openSettings)($player);
			}
			return;
		}
		if($selected === self::CLEAR){
			$this->cosmetics->clear($player, ChangeCause::PLAYER);
			$this->refresh($player, $category, $page, $generation);
			return;
		}

		$item = $visible[$selected - self::FIRST_ITEM] ?? null;
		if($item === null){
			return;
		}
		if($this->cosmetics->getEquipped($player, $category)?->id === $item->id){
			$this->cosmetics->unequip($player, $category, ChangeCause::PLAYER);
		}elseif(!$this->cosmetics->isUnlocked($player, $item)){
			unset($this->generations[$player]);
			($this->openDetails)($player, $item, $page);
			return;
		}else{
			$result = $this->cosmetics->equip($player, $item->id, ChangeCause::PLAYER);
			if($result !== EquipResult::SUCCESS){
				$player->sendMessage(TF::RED . $result->getMessage());
			}
		}
		$this->refresh($player, $category, $page, $generation);
	}

	/**
	 * Reopens after the new skin was sent, so the form never hides the change being applied.
	 */
	private function refresh(Player $player, CosmeticCategory $category, int $page, int $generation) : void{
		$refresh = function() use ($player, $category, $page, $generation) : void{
			if($player->isConnected() && ($this->generations[$player] ?? 0) === $generation){
				$this->open($player, $category, $page);
			}
		};
		if($this->scheduler === null){
			$refresh();
			return;
		}
		$this->scheduler->scheduleDelayedTask(new ClosureTask($refresh), $this->settings->getSkinUpdateDelay() + 2);
	}

	/**
	 * @return list<CosmeticDefinition> owned first, then by rarity and name
	 */
	private function sortedItems(Player $player, CosmeticCategory $category) : array{
		if(!$this->cosmetics->isCategoryAvailable($category)){
			return [];
		}
		$items = $this->cosmetics->getRegistry()->getByCategory($category, true);
		usort($items, function(CosmeticDefinition $a, CosmeticDefinition $b) use ($player) : int{
			return [!$this->cosmetics->isUnlocked($player, $a), $a->rarity->ordinal(), $a->name, $a->id] <=> [!$this->cosmetics->isUnlocked($player, $b), $b->rarity->ordinal(), $b->name, $b->id];
		});
		return $items;
	}

	private function tabLabel(CosmeticCategory $tab, CosmeticCategory $current) : string{
		if(!$this->cosmetics->isCategoryAvailable($tab)){
			return TF::DARK_GRAY . $tab->getDisplayName();
		}
		return ($tab === $current ? self::WEARING_MARKER . TF::WHITE : TF::GRAY) . $tab->getDisplayName();
	}

	private function card(Player $player, CosmeticDefinition $item, ?CosmeticDefinition $equipped) : MenuOption{
		$name = mb_strimwidth(TF::clean($item->name), 0, self::MAX_NAME_WIDTH, "..");
		if($item->id === $equipped?->id){
			$text = self::WEARING_MARKER . TF::GREEN . $name;
		}elseif($this->cosmetics->isUnlocked($player, $item)){
			$text = TF::WHITE . $name;
		}else{
			$text = self::LOCKED_MARKER . TF::GRAY . $name;
		}
		return new MenuOption($text, $item->icon ?? "textures/nkcosmetics/ui/" . $item->category->value);
	}

	private function header(Player $player, CosmeticCategory $category, int $itemCount) : string{
		if(!$this->cosmetics->isWorldAllowed($player)){
			return TF::RED . "Cosmetics are disabled in this world";
		}
		if($itemCount === 0){
			return TF::GRAY . "No " . $category->getDisplayName() . " available";
		}
		return TF::BOLD . TF::WHITE . "Cosmetics";
	}
}
