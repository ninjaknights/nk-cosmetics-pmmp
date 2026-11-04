<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\wardrobe;

use pocketmine\player\Player;

/**
 * @internal What one player's visit to a wardrobe zone changed, so it can be undone exactly.
 */
final class WardrobeSession{

	public const PHASE_ENTERING = 0;
	public const PHASE_ACTIVE = 1;
	public const PHASE_LEAVING = 2;

	public int $phase = self::PHASE_ENTERING;
	public bool $restricted = false;
	/** @var array<int, Player> viewers this session hid the player from, by object id */
	public array $hiddenFrom = [];
	public int $nextMenuTick = 0;
	public int $nextPromptTick = 0;

	public function __construct(
		public readonly WardrobeZone $zone
	){}
}
