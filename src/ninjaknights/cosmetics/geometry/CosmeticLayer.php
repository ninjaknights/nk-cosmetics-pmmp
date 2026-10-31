<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

/**
 * One cosmetic model to graft onto a skin.
 *
 * The model is authored in player model space (the head spans y 24..32). Its root bones are
 * re-parented onto the first attach bone that exists in the skin's geometry.
 */
final class CosmeticLayer{

	/**
	 * @param string   $key          short unique key used to prefix bone names (e.g. "hat")
	 * @param string   $slotBone     persona/vanilla slot bone (helmet, bodyarmor, leftitem); its baked content may be stripped
	 * @param string[] $fallbackBones attach bones tried, in order, when the slot bone is missing; never stripped
	 * @param bool     $replaceSlot  remove whatever the skin already renders in the slot bone (e.g. a persona back item)
	 * @param string[] $stripBones   extra bones whose geometry is removed (e.g. "hat" to hide a classic hair layer)
	 * @param array{float, float, float} $offset     model-space translation applied to the cosmetic
	 * @param array{float, float, float} $slimOffset extra translation when the skin has slim arms and a fallback bone is used
	 */
	public function __construct(
		public string $key,
		public string $slotBone,
		public array $fallbackBones,
		public bool $replaceSlot,
		public GeometryModel $model,
		public RgbaImage $texture,
		public array $stripBones = [],
		public array $offset = [0.0, 0.0, 0.0],
		public array $slimOffset = [0.0, 0.0, 0.0]
	){}
}
