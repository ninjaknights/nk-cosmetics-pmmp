<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\skin;

use pocketmine\entity\Skin;

/**
 * @internal
 */
final class SkinState{

	/** The composed skin handle currently set on the player, or null if they wear their original. */
	public ?Skin $applied = null;
	/** @var array<string, true> messages already shown to the player this session */
	public array $notified = [];

	public function __construct(
		public Skin $original
	){}
}
