<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\manager;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;

/**
 * Registers each cosmetic's unlock permission. New nodes default to nobody; the
 * nkcosmetics.all group (given to operators) includes all of them.
 */
final class PermissionRegistrar{

	public const ALL = "nkcosmetics.all";

	public static function register(CosmeticDefinition $definition) : void{
		$permissions = PermissionManager::getInstance();
		if($permissions->getPermission($definition->permission) === null){
			$permissions->addPermission(new Permission($definition->permission, "Use the {$definition->name} cosmetic"));
		}
		$all = $permissions->getPermission(self::ALL);
		if($all === null){
			$all = new Permission(self::ALL, "Use every cosmetic");
			$permissions->addPermission($all);
			$permissions->getPermission(DefaultPermissions::ROOT_OPERATOR)?->addChild(self::ALL, true);
		}
		$all->addChild($definition->permission, true);
	}
}
