<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\event;

use ninjaknights\cosmetics\cosmetic\CosmeticDefinition;
use pocketmine\event\Event;

abstract class CosmeticEvent extends Event{

	public function __construct(
		protected CosmeticDefinition $definition
	){}

	public function getDefinition() : CosmeticDefinition{
		return $this->definition;
	}
}
