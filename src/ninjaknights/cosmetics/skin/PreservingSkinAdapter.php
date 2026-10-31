<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\skin;

use pocketmine\entity\InvalidSkinException;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\convert\SkinAdapter;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use function str_repeat;

/**
 * Wraps the server's skin adapter so the exact SkinData a client sent is kept alongside the Skin
 * object PocketMine works with.
 *
 * PocketMine's Skin class cannot represent persona skins (poly meshes, 256px atlases, animated
 * faces), so the stock adapter replaces them with a random solid colour. This adapter remembers
 * the original data per Skin object and sends that to other players instead, and lets
 * NKCosmetics bind composed skins to Skin handles. Skins created by other plugins are passed
 * through to the wrapped adapter unchanged.
 */
final class PreservingSkinAdapter implements SkinAdapter{

	/** @var \WeakMap<Skin, SkinData> */
	private \WeakMap $bound;

	/**
	 * @param bool $preservePersona when false, persona skins get the wrapped adapter's replacement like before
	 */
	public function __construct(
		private SkinAdapter $inner,
		private bool $preservePersona,
		private bool $acceptUnsupportedClassicSkins
	){
		$this->bound = new \WeakMap();
	}

	public function getInner() : SkinAdapter{
		return $this->inner;
	}

	public function toSkinData(Skin $skin) : SkinData{
		return $this->bound[$skin] ?? $this->inner->toSkinData($skin);
	}

	public function fromSkinData(SkinData $data) : Skin{
		try{
			$skin = $this->inner->fromSkinData($data);
		}catch(InvalidSkinException $e){
			//e.g. classic skins larger than 128x128, which the Skin class rejects but clients render fine
			if(!$this->acceptUnsupportedClassicSkins){
				throw $e;
			}
			$skin = new Skin($data->getSkinId() !== "" ? $data->getSkinId() : "nkcosmetics_placeholder", str_repeat("\x80\x80\x80\xff", 64 * 64));
		}
		if($this->preservePersona || !$data->isPersona()){
			$this->bound[$skin] = $data;
		}
		return $skin;
	}

	/**
	 * Makes $handle send $data to clients.
	 */
	public function bind(Skin $handle, SkinData $data) : void{
		$this->bound[$handle] = $data;
	}

	/**
	 * The exact data the client sent (or that was bound), if this adapter has seen the skin.
	 */
	public function getBoundData(Skin $skin) : ?SkinData{
		return $this->bound[$skin] ?? null;
	}
}
