<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

/**
 * Why a cosmetic was equipped, unequipped, unlocked or revoked.
 */
enum ChangeCause : string{

	/** The player used the menu or a player command. */
	case PLAYER = "player";
	/** An administrator used /nkcosmetics. */
	case ADMIN = "admin";
	/** Another plugin called the API. */
	case API = "api";
	/** Equipped cosmetics were restored when the player joined. */
	case RESTORE = "restore";
	/** The player lost access (unlock revoked, permission removed, cosmetic disabled or deleted). */
	case ACCESS_LOST = "access_lost";
	/** The cosmetic is not allowed in the player's current world. */
	case WORLD = "world";

	/**
	 * Forced changes always happen; listeners may observe them but cancelling has no effect.
	 */
	public function isForced() : bool{
		return $this === self::ACCESS_LOST || $this === self::WORLD;
	}
}
