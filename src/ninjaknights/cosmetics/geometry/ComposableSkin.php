<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

/**
 * Protocol-independent view of the skin fields the composer reads and rewrites.
 */
final class ComposableSkin{

	public function __construct(
		public RgbaImage $image,
		public string $geometryJson,
		public string $resourcePatchJson,
		public bool $slim
	){}
}
