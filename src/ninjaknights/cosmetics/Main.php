<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics;

use ninjaknights\cosmetics\api\NKCosmeticsAPI;
use ninjaknights\cosmetics\builtin\BuiltinCatalogs;
use ninjaknights\cosmetics\command\AdminCommand;
use ninjaknights\cosmetics\command\CosmeticsCommand;
use ninjaknights\cosmetics\entity\CustomEntityIdentifiers;
use ninjaknights\cosmetics\geometry\GeometryFile;
use ninjaknights\cosmetics\geometry\GeometryModel;
use ninjaknights\cosmetics\geometry\SkinComposer;
use ninjaknights\cosmetics\listener\GadgetListener;
use ninjaknights\cosmetics\listener\PlayerListener;
use ninjaknights\cosmetics\listener\SkinVisibilityListener;
use ninjaknights\cosmetics\manager\CosmeticManager;
use ninjaknights\cosmetics\manager\PermissionRegistrar;
use ninjaknights\cosmetics\manager\SessionManager;
use ninjaknights\cosmetics\menu\MenuManager;
use ninjaknights\cosmetics\cosmetic\CosmeticCategory;
use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use ninjaknights\cosmetics\registry\CosmeticFiles;
use ninjaknights\cosmetics\registry\CosmeticRegistry;
use ninjaknights\cosmetics\registry\YamlCosmeticLoader;
use ninjaknights\cosmetics\renderer\BackRenderer;
use ninjaknights\cosmetics\renderer\BalloonRenderer;
use ninjaknights\cosmetics\renderer\FollowerRenderer;
use ninjaknights\cosmetics\renderer\GadgetRenderer;
use ninjaknights\cosmetics\renderer\HatRenderer;
use ninjaknights\cosmetics\renderer\OffhandRenderer;
use ninjaknights\cosmetics\renderer\ParticleRenderer;
use ninjaknights\cosmetics\renderer\PetRenderer;
use ninjaknights\cosmetics\renderer\SkinAttachedRenderer;
use ninjaknights\cosmetics\resourcepack\ResourcePackAssetRegistry;
use ninjaknights\cosmetics\resourcepack\ResourcePackBuilder;
use ninjaknights\cosmetics\skin\PreservingSkinAdapter;
use ninjaknights\cosmetics\skin\SkinGeometryManager;
use ninjaknights\cosmetics\storage\CosmeticStorage;
use ninjaknights\cosmetics\storage\SqliteStorage;
use ninjaknights\cosmetics\storage\StorageException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;
use Symfony\Component\Filesystem\Path;
use function array_unshift;
use function count;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_dir;
use function is_string;
use function mkdir;
use function spl_object_id;
use function str_replace;
use function str_starts_with;
use function strtolower;

final class Main extends PluginBase{

	private static ?self $instance = null;

	private PluginSettings $settings;
	private CosmeticStorage $storage;
	private CosmeticRegistry $registry;
	private ResourcePackAssetRegistry $assets;
	private SessionManager $sessions;
	private CosmeticManager $cosmetics;
	private PreservingSkinAdapter $skinAdapter;
	private SkinGeometryManager $skins;
	private MenuManager $menus;
	private NKCosmeticsAPI $api;
	/** @var list<SkinAttachedRenderer> */
	private array $skinRenderers = [];
	/** @var list<FollowerRenderer> */
	private array $followerRenderers = [];
	private ParticleRenderer $particles;
	private GadgetRenderer $gadgets;
	/** @var array<string, array{raw: string, parsed: array<mixed>, error: ?string}> last read cosmetics/*.yml, by category value */
	private array $typeFiles = [];
	private bool $ready = false;

	/**
	 * @internal Use NKCosmeticsAPI::get() from other plugins.
	 */
	public static function getInstance() : ?self{
		return self::$instance;
	}

	protected function onEnable() : void{
		if(!extension_loaded("gd")){
			$this->getLogger()->critical("The GD extension is required to read cosmetic textures. Disabling NKCosmetics.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->saveDefaultConfig();
		foreach(CosmeticCategory::ordered() as $category){
			$this->saveResource(CosmeticFiles::DIRECTORY . "/" . CosmeticFiles::fileName($category));
		}
		if(file_exists(Path::join($this->getDataFolder(), "cosmetics.yml"))){
			$this->getLogger()->warning("cosmetics.yml is no longer read. Move its entries into the matching file in the cosmetics/ folder (hats.yml, back.yml, offhand.yml, balloons.yml); the 'type' key can be dropped.");
		}
		$this->extractBundledPack();
		$this->settings = $this->loadSettings();

		try{
			$this->storage = new SqliteStorage(Path::join($this->getDataFolder(), $this->settings->getSqliteFile()));
		}catch(StorageException $e){
			$this->getLogger()->critical($e->getMessage() . ". Disabling NKCosmetics.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->assets = $this->loadAssets();
		$this->registerPack();

		$this->registry = new CosmeticRegistry();
		$this->sessions = new SessionManager($this->storage, $this->getLogger());
		$this->cosmetics = new CosmeticManager($this->registry, $this->sessions, $this->settings, $this->getLogger());

		$converter = TypeConverter::getInstance();
		$this->skinAdapter = new PreservingSkinAdapter($converter->getSkinAdapter(), $this->settings->isPreservePersona(), $this->settings->isAcceptLargeClassicSkins());
		$converter->setSkinAdapter($this->skinAdapter);

		$this->skins = new SkinGeometryManager(
			new SkinComposer($this->loadFallbackGeometry("humanoid_wide.json"), $this->loadFallbackGeometry("humanoid_slim.json"), $this->settings->getMaxTextureSize()),
			$this->skinAdapter,
			$this->settings->isComposedSkinPersona(),
			$this->settings->getSkinUpdateDelay(),
			$this->getLogger(),
			fn(Player $player) : bool => !$this->cosmetics->isVisible($player)
		);
		foreach([HatRenderer::class, BackRenderer::class, OffhandRenderer::class] as $class){
			$renderer = new $class($this->skins, $this->assets, $this->settings, $this->getLogger());
			$this->skinRenderers[] = $renderer;
			$this->cosmetics->registerRenderer($renderer);
			$this->skins->registerProvider($renderer->getCategory()->value, $renderer);
		}

		$balloons = new BalloonRenderer($this->cosmetics, $this->settings);
		$this->followerRenderers[] = $balloons;
		$this->cosmetics->registerRenderer($balloons);
		$pets = new PetRenderer($this->cosmetics, $this->settings);
		$this->followerRenderers[] = $pets;
		$this->cosmetics->registerRenderer($pets);
		$this->particles = new ParticleRenderer($this->cosmetics, $this->settings);
		$this->cosmetics->registerRenderer($this->particles);
		$this->gadgets = new GadgetRenderer($this->cosmetics, $this->settings, fn() : int => $this->getServer()->getTick());
		$this->cosmetics->registerRenderer($this->gadgets);
		BuiltinCatalogs::register($this->registry, $this->settings);

		foreach($this->loadYamlCosmetics() as $line){
			$this->getLogger()->info(TF::clean($line));
		}

		$this->menus = new MenuManager($this->cosmetics, $this->settings);
		$this->api = new NKCosmeticsAPI($this->cosmetics, $this->assets);

		$visibility = new SkinVisibilityListener($this->cosmetics, $this->skins);
		$this->cosmetics->onViewerPreferenceChange($visibility->resendTo(...));
		$this->cosmetics->onViewerPreferenceChange(function(Player $viewer) : void{
			foreach($this->followerRenderers as $renderer){
				$renderer->updateVisibility();
			}
		});

		$pluginManager = $this->getServer()->getPluginManager();
		$pluginManager->registerEvents(new PlayerListener($this, $this->cosmetics, $this->skins), $this);
		$pluginManager->registerEvents($visibility, $this);
		$pluginManager->registerEvents(new GadgetListener($this->gadgets), $this);

		$this->getServer()->getCommandMap()->registerAll("nkcosmetics", [
			new CosmeticsCommand($this, $this->cosmetics, $this->menus, $this->gadgets),
			new AdminCommand($this, $this->cosmetics)
		]);

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->skins->tick($this->getServer()->getTick(), fn(int $id) : ?Player => $this->findOnlinePlayer($id));
			$this->particles->tick($this->getServer()->getTick());
		}), 1);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			foreach($this->followerRenderers as $renderer){
				$renderer->updateVisibility();
			}
		}), 10);
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->sessions->saveAll();
		}), $this->settings->getAutosaveSeconds() * 20);

		self::$instance = $this;
		$this->ready = true;
	}

	protected function onDisable() : void{
		if(!$this->ready){
			return;
		}
		$this->ready = false;
		$this->particles->clear();
		$online = $this->getServer()->getOnlinePlayers();
		foreach($this->followerRenderers as $renderer){
			$renderer->despawnAll($online);
		}
		$this->skins->restoreAll($online);
		foreach($online as $player){
			$this->gadgets->forget($player);
			$this->sessions->close($player);
		}
		$converter = TypeConverter::getInstance();
		if($converter->getSkinAdapter() === $this->skinAdapter){
			$converter->setSkinAdapter($this->skinAdapter->getInner());
		}
		$this->storage->close();
		self::$instance = null;
	}

	public function getApi() : NKCosmeticsAPI{
		return $this->api;
	}

	public function getSettings() : PluginSettings{
		return $this->settings;
	}

	public function getCosmeticManager() : CosmeticManager{
		return $this->cosmetics;
	}

	public function getSkinGeometryManager() : SkinGeometryManager{
		return $this->skins;
	}

	public function getAssets() : ResourcePackAssetRegistry{
		return $this->assets;
	}

	private function findOnlinePlayer(int $objectId) : ?Player{
		foreach($this->getServer()->getOnlinePlayers() as $player){
			if(spl_object_id($player) === $objectId){
				return $player;
			}
		}
		return null;
	}

	private function loadSettings() : PluginSettings{
		$this->reloadConfig();
		$this->typeFiles = CosmeticFiles::readAll($this->getDataFolder());
		$parsed = [];
		foreach($this->typeFiles as $category => $file){
			$parsed[$category] = $file["parsed"];
		}
		$settings = new PluginSettings($this->getConfig()->getAll(), $parsed);
		//Validate the skin composition limits and mode before use.
		$settings->getMaxTextureSize();
		$settings->isComposedSkinPersona();
		foreach($this->typeFiles as $category => $file){
			if($file["error"] !== null){
				$this->getLogger()->error(CosmeticFiles::label(CosmeticCategory::from($category)) . " " . $file["error"] . "; its settings and cosmetics were not loaded");
			}
		}
		foreach($settings->getWarnings() as $warning){
			$this->getLogger()->warning($warning);
		}
		return $settings;
	}

	/**
	 * Copies the sample resource pack into the data folder on first start.
	 */
	private function extractBundledPack() : void{
		$target = Path::join($this->getDataFolder(), "resource-pack");
		if(is_dir($target)){
			return;
		}
		foreach($this->getResources() as $path => $_){
			$path = str_replace("\\", "/", (string) $path);
			if(str_starts_with($path, "resource-pack/")){
				$this->saveResource($path);
			}
		}
	}

	private function getPackSourcePath() : string{
		return Path::makeAbsolute($this->settings->getResourcePackSource(), $this->getDataFolder());
	}

	private function loadAssets() : ResourcePackAssetRegistry{
		$source = $this->getPackSourcePath();
		try{
			$assets = ResourcePackAssetRegistry::load($source);
		}catch(\RuntimeException $e){
			$this->getLogger()->error("Cosmetics resource pack unavailable: " . $e->getMessage() . ". Custom cosmetics cannot be shown.");
			$assets = ResourcePackAssetRegistry::fromFiles($source, []);
		}
		foreach($assets->getProblems() as $problem){
			$this->getLogger()->warning("Resource pack: $problem");
		}
		return $assets;
	}

	/**
	 * Builds the .mcpack from the source folder and puts it on the server's resource stack.
	 */
	private function registerPack() : void{
		if(!$this->settings->isResourcePackAutoRegister()){
			return;
		}
		$source = $this->getPackSourcePath();
		if(!is_dir($source)){
			$this->getLogger()->warning("Not registering the cosmetics resource pack: $source is not a folder");
			return;
		}
		$buildDir = Path::join($this->getDataFolder(), "build");
		if(!is_dir($buildDir)){
			mkdir($buildDir, 0755, true);
		}
		$output = Path::join($buildDir, "NKCosmetics.mcpack");
		try{
			$info = ResourcePackBuilder::build($source, $output, Path::join($buildDir, "pack-state.json"));
			$pack = new ZippedResourcePack($output);
		}catch(\Exception $e){
			$this->getLogger()->error("Could not build the cosmetics resource pack: " . $e->getMessage());
			return;
		}
		$manager = $this->getServer()->getResourcePackManager();
		$stack = [];
		foreach($manager->getResourceStack() as $existing){
			/** @var ResourcePack $existing */
			if(strtolower($existing->getPackId()) !== strtolower($info["uuid"])){
				$stack[] = $existing;
			}
		}
		$stack[] = $pack;
		$manager->setResourceStack($stack);
		if($this->settings->isResourcePackForced()){
			$manager->setResourcePacksRequired(true);
		}
		$this->getLogger()->info("Registered cosmetics resource pack v{$info["version"]}" . ($info["rebuilt"] ? " (rebuilt)" : "") . " from $output");
	}

	private function loadFallbackGeometry(string $file) : GeometryModel{
		$json = file_get_contents($this->getResourcePath("geometry/$file"));
		if($json === false){
			throw new \RuntimeException("Missing bundled geometry $file");
		}
		return GeometryFile::parse($json)->models[0];
	}

	/**
	 * (Re)reads the custom cosmetics of cosmetics/hats.yml, back.yml, offhand.yml and
	 * balloons.yml into the registry. Uses the files read by the last loadSettings().
	 *
	 * @return list<string> report lines
	 */
	private function loadYamlCosmetics() : array{
		$loader = new YamlCosmeticLoader($this->assets);
		$definitions = [];
		$lines = [];
		$counts = [];
		foreach(CosmeticCategory::ordered() as $category){
			if(!$category->isConfigurable()){
				continue;
			}
			$file = $this->typeFiles[$category->value] ?? null;
			if($file === null || $file["error"] !== null){
				continue;
			}
			$label = CosmeticFiles::label($category);
			$report = $loader->loadCategoryFile($file["parsed"], $file["raw"], $category);
			$definitions = [...$definitions, ...$report->definitions];
			$counts[] = count($report->definitions) . " " . strtolower($category->getDisplayName());
			foreach($report->errors as $error){
				$lines[] = TF::RED . "Skipped ($label): $error";
				$this->getLogger()->warning("$label: $error");
			}
			foreach($report->warnings as $warning){
				$lines[] = TF::YELLOW . "Warning ($label): $warning";
			}
		}
		$problems = $this->registry->replaceYamlDefinitions($definitions);
		foreach($problems as $problem){
			$lines[] = TF::RED . "Skipped: $problem";
			$this->getLogger()->warning($problem);
		}
		$this->registerPermissions();
		foreach($this->settings->getUnknownBuiltinIds() as $unknown){
			$lines[] = TF::YELLOW . "Warning: $unknown";
			$this->getLogger()->warning($unknown);
		}

		array_unshift($lines, TF::GREEN . "Loaded " . (count($definitions) - count($problems)) . " custom cosmetic(s): " . implode(", ", $counts));
		return $lines;
	}

	private function registerPermissions() : void{
		foreach($this->registry->getAll() as $definition){
			PermissionRegistrar::register($definition);
			self::announceEntity($definition);
		}
	}

	/**
	 * Balloon entity types must be announced to clients at login to be rendered.
	 *
	 * @internal
	 */
	public static function announceEntity(CosmeticDefinition $definition) : void{
		$entity = $definition->getSetting("entity");
		if($definition->category === CosmeticCategory::BALLOON && is_string($entity)){
			CustomEntityIdentifiers::register($entity);
		}
	}

	/**
	 * /nkcosmetics reload: re-reads config.yml, source assets and the cosmetics/*.yml files, then
	 * re-applies every online player's cosmetics with the new definitions.
	 *
	 * @return list<string>
	 */
	public function reload() : array{
		$lines = [];
		$this->settings = $this->loadSettings();
		foreach($this->typeFiles as $category => $file){
			if($file["error"] !== null){
				$lines[] = TF::RED . CosmeticFiles::label(CosmeticCategory::from($category)) . " " . $file["error"];
			}
		}
		foreach($this->settings->getWarnings() as $warning){
			$lines[] = TF::YELLOW . $warning;
		}
		$this->cosmetics->setSettings($this->settings);
		$this->menus->setSettings($this->settings);
		$this->particles->setSettings($this->settings);
		$this->gadgets->setSettings($this->settings);
		foreach($this->followerRenderers as $renderer){
			$renderer->setSettings($this->settings);
		}

		$this->assets = $this->loadAssets();
		$this->api->setAssets($this->assets);
		foreach($this->skinRenderers as $renderer){
			$renderer->setSettings($this->settings);
			$renderer->setAssets($this->assets);
		}
		$this->skins->clearCache();
		$this->skins->setComposedSkinIsPersona($this->settings->isComposedSkinPersona());

		//The registered pack can remain open while clients download it, especially on Windows.
		$lines[] = TF::GRAY . "Resource-pack downloads update after a server restart. Players must then rejoin; new balloon types also require a restart.";

		BuiltinCatalogs::register($this->registry, $this->settings);
		$lines = [...$lines, ...$this->loadYamlCosmetics()];

		foreach($this->getServer()->getOnlinePlayers() as $player){
			$this->cosmetics->revalidate($player);
		}
		$lines[] = TF::GREEN . "Reload complete.";
		return $lines;
	}
}
