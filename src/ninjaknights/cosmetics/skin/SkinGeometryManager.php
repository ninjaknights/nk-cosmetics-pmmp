<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\skin;

use ninjaknights\cosmetics\geometry\GeometryException;
use ninjaknights\cosmetics\geometry\RgbaImage;
use ninjaknights\cosmetics\geometry\SkinComposer;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function array_key_first;
use function array_keys;
use function count;
use function implode;
use function microtime;
use function round;
use function sha1;
use function spl_object_id;

/**
 * Keeps each player's original skin and the composed skin that carries their hat, back and
 * offhand cosmetics.
 *
 * Rebuilds are queued and run a few ticks later so several changes (equip + armour change)
 * cause one skin update, and results are cached by original skin + layer set so re-equipping or
 * many players with the same skin cost nothing.
 */
final class SkinGeometryManager{

	private const CACHE_SIZE = 128;
	private const MAX_REBUILDS_PER_TICK = 4;

	/** @var array<int, SkinState> */
	private array $states = [];
	/** @var array<string, SkinLayerProvider> */
	private array $providers = [];
	/** @var array<int, int> player object id => tick at which to rebuild */
	private array $pending = [];
	/** @var array<string, SkinData|string> composition key => result or failure message */
	private array $cache = [];
	/** @var \WeakMap<Skin, string> */
	private \WeakMap $skinFingerprints;
	/** @var \Closure(Player) : bool */
	private \Closure $isHiddenFor;

	private int $currentTick = 0;

	/**
	 * @param \Closure(Player) : bool $isHidden whether the player chose to hide their own cosmetics
	 */
	public function __construct(
		private SkinComposer $composer,
		private PreservingSkinAdapter $adapter,
		private bool $composedSkinIsPersona,
		private int $updateDelay,
		private \Logger $logger,
		\Closure $isHidden
	){
		$this->skinFingerprints = new \WeakMap();
		$this->isHiddenFor = $isHidden;
	}

	public function registerProvider(string $key, SkinLayerProvider $provider) : void{
		$this->providers[$key] = $provider;
	}

	public function attach(Player $player) : void{
		$this->states[spl_object_id($player)] = new SkinState($player->getSkin());
	}

	public function detach(Player $player) : void{
		unset($this->states[spl_object_id($player)], $this->pending[spl_object_id($player)]);
	}

	/**
	 * Queues a rebuild of the player's skin.
	 */
	public function requestUpdate(Player $player) : void{
		$id = spl_object_id($player);
		if(isset($this->states[$id]) && !isset($this->pending[$id])){
			$this->pending[$id] = $this->currentTick + $this->updateDelay;
		}
	}

	/**
	 * Returns the skin the player had before NKCosmetics changed it.
	 */
	public function getOriginalSkin(Player $player) : ?Skin{
		return ($this->states[spl_object_id($player)] ?? null)?->original;
	}

	public function hasComposedSkin(Player $player) : bool{
		return ($this->states[spl_object_id($player)] ?? null)?->applied !== null;
	}

	/**
	 * The data clients receive for $skin, if it is a skin composed by NKCosmetics, mapped to the
	 * data of the original skin it was built from. Used to show un-cosmeticed skins to players
	 * who hide other players' cosmetics.
	 */
	public function getOriginalDataFor(Player $player) : ?SkinData{
		$state = $this->states[spl_object_id($player)] ?? null;
		if($state === null || $state->applied === null){
			return null;
		}
		return $this->adapter->toSkinData($state->original);
	}

	public function clearCache() : void{
		$this->cache = [];
	}

	/**
	 * Changes whether composed persona skins keep their persona flag (config reload).
	 */
	public function setComposedSkinIsPersona(bool $persona) : void{
		if($this->composedSkinIsPersona !== $persona){
			$this->composedSkinIsPersona = $persona;
			$this->cache = [];
		}
	}

	/**
	 * Writes what NKCosmetics currently sends for a player (and what it started from) to $directory,
	 * for diagnosing skins the client does not display.
	 *
	 * @return list<string> summary lines
	 */
	public function writeDebugDump(Player $player, string $directory) : array{
		$state = $this->states[spl_object_id($player)] ?? null;
		if($state === null){
			return ["No skin state for {$player->getName()}"];
		}
		@mkdir($directory, 0777, true);
		$lines = [
			"Pending rebuild: " . (isset($this->pending[spl_object_id($player)]) ? "yes" : "no"),
			"Composed skin applied: " . ($state->applied !== null ? "yes" : "no"),
			"Current skin is the applied one: " . ($player->getSkin() === $state->applied ? "yes" : "no"),
			"Composed skin type: " . ($this->composedSkinIsPersona ? "persona" : "classic")
		];
		foreach(["original" => $this->adapter->toSkinData($state->original), "sent" => $this->adapter->toSkinData($player->getSkin())] as $label => $data){
			$image = $data->getSkinImage();
			$lines[] = "[$label] skinId={$data->getSkinId()} persona=" . ($data->isPersona() ? "yes" : "no") . " pieces=" . count($data->getPersonaPieces()) . " image={$image->getWidth()}x{$image->getHeight()} geometry=" . strlen($data->getGeometryDataJson()) . "B engine={$data->getGeometryDataEngineVersion()} arm={$data->getArmSize()->value} animations=" . count($data->getAnimations()) . " trusted={$data->getTrustedSkinFlag()} premium=" . ($data->isPremium() ? "yes" : "no");
			$lines[] = "[$label] resourcePatch=" . $data->getResourcePatch();
			file_put_contents("$directory/$label.geometry.json", $data->getGeometryDataJson());
			file_put_contents("$directory/$label.resource_patch.json", $data->getResourcePatch());
			if($image->getWidth() > 0 && $image->getHeight() > 0){
				file_put_contents("$directory/$label.png", (new RgbaImage($image->getWidth(), $image->getHeight(), $image->getData()))->toPng());
			}
		}
		$lines[] = "Layers now:";
		foreach($this->providers as $key => $provider){
			$layer = $provider->getLayer($player);
			$lines[] = "  $key: " . ($layer === null ? "none" : $layer[1] . " (model " . $layer[0]->model->identifier . ", slot " . $layer[0]->slotBone . ", replace " . ($layer[0]->replaceSlot ? "yes" : "no") . ")");
		}
		$lines[] = "Hidden by preference/world: " . (($this->isHiddenFor)($player) ? "yes" : "no");
		file_put_contents("$directory/summary.txt", implode("\n", $lines) . "\n");
		return $lines;
	}

	public function tick(int $currentTick, callable $playerLookup) : void{
		$this->currentTick = $currentTick;
		$processed = 0;
		foreach($this->pending as $id => $due){
			if($due > $currentTick){
				continue;
			}
			unset($this->pending[$id]);
			$player = $playerLookup($id);
			if($player instanceof Player && $player->isConnected() && isset($this->states[$id])){
				$this->rebuild($player, $this->states[$id]);
			}
			if(++$processed >= self::MAX_REBUILDS_PER_TICK){
				break;
			}
		}
	}

	/**
	 * Puts every player back in their original skin (plugin shutdown).
	 *
	 * @param Player[] $players
	 */
	public function restoreAll(array $players) : void{
		foreach($players as $player){
			$state = $this->states[spl_object_id($player)] ?? null;
			if($state !== null && $state->applied !== null && $player->isConnected()){
				$this->apply($player, $state, $state->original, null);
			}
		}
		$this->states = [];
		$this->pending = [];
	}

	private function rebuild(Player $player, SkinState $state) : void{
		$current = $player->getSkin();
		if($current !== $state->applied){
			//the player (or another plugin) changed the skin since we last applied ours
			$state->original = $current;
			$state->applied = null;
		}

		$layers = [];
		$fingerprints = [];
		if(!($this->isHiddenFor)($player)){
			foreach($this->providers as $key => $provider){
				$layer = $provider->getLayer($player);
				if($layer !== null){
					$layers[] = $layer[0];
					$fingerprints[] = "$key=" . $layer[1];
				}
			}
		}

		if($layers === []){
			if($state->applied !== null){
				$this->apply($player, $state, $state->original, null);
			}
			return;
		}

		$base = $this->adapter->toSkinData($state->original);
		$key = $this->fingerprint($state->original, $base) . "|" . ($this->composedSkinIsPersona ? "p" : "c") . "|" . implode(";", $fingerprints);

		if(!isset($this->cache[$key])){
			$start = microtime(true);
			try{
				$result = $this->composer->compose(SkinDataBridge::toComposable($base), $layers);
				$this->remember($key, SkinDataBridge::fromComposition($base, $result, $this->composedSkinIsPersona));
				$this->logger->debug("Composed skin for {$player->getName()} in " . round((microtime(true) - $start) * 1000, 1) . "ms (" . $result->image->getWidth() . "px, " . count($layers) . " layer(s), sent as " . ($this->composedSkinIsPersona && $base->isPersona() ? "persona" : "classic") . ")");
				foreach($result->skippedLayers as $layerKey => $reason){
					$this->notifyOnce($player, $state, "skip:$layerKey:$reason", "Your $layerKey cosmetic cannot be shown on your current skin: $reason.");
				}
			}catch(GeometryException|\InvalidArgumentException $e){
				$this->remember($key, $e->getMessage());
				$this->logger->warning("Could not add cosmetics to the skin of {$player->getName()}: " . $e->getMessage());
			}
		}

		$composed = $this->cache[$key];
		if(!$composed instanceof SkinData){
			$this->notifyOnce($player, $state, "fail:$composed", "Your cosmetics cannot be shown on your current skin ($composed). Your skin was left unchanged.");
			if($state->applied !== null){
				$this->apply($player, $state, $state->original, null);
			}
			return;
		}
		$original = $state->original;
		$handle = new Skin($original->getSkinId(), $original->getSkinData(), $original->getCapeData(), $original->getGeometryName(), $original->getGeometryData());
		$this->adapter->bind($handle, $composed);
		$this->apply($player, $state, $handle, $handle);
	}

	private function apply(Player $player, SkinState $state, Skin $skin, ?Skin $applied) : void{
		$state->applied = $applied;
		$player->setSkin($skin);
		$player->sendSkin();
	}

	private function fingerprint(Skin $skin, SkinData $data) : string{
		return $this->skinFingerprints[$skin] ??= sha1($data->getSkinImage()->getData() . "\x00" . $data->getGeometryDataJson() . "\x00" . $data->getResourcePatch() . "\x00" . $data->getArmSize()->value);
	}

	private function remember(string $key, SkinData|string $value) : void{
		if(count($this->cache) >= self::CACHE_SIZE){
			unset($this->cache[array_key_first($this->cache)]);
		}
		$this->cache[$key] = $value;
	}

	private function notifyOnce(Player $player, SkinState $state, string $key, string $message) : void{
		if(!isset($state->notified[$key])){
			$state->notified[$key] = true;
			$player->sendMessage(TextFormat::YELLOW . $message);
		}
	}

	/**
	 * @return list<int>
	 */
	public function getPendingIds() : array{
		return array_keys($this->pending);
	}
}
