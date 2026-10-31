<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\geometry;

final class CompositionResult{

	/**
	 * @param array<string, string> $skippedLayers layer key => reason it could not be applied
	 */
	public function __construct(
		public RgbaImage $image,
		public string $geometryJson,
		public string $resourcePatchJson,
		public string $geometryIdentifier,
		public array $skippedLayers
	){}
}
