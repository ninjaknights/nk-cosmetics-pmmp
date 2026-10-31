<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\skin;

use ninjaknights\cosmetics\geometry\ComposableSkin;
use ninjaknights\cosmetics\geometry\CompositionResult;
use ninjaknights\cosmetics\geometry\RgbaImage;
use pocketmine\network\mcpe\protocol\types\skin\SkinArmSizeType;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use function substr;

/**
 * Converts between the protocol SkinData type and the composer's protocol-independent types.
 */
final class SkinDataBridge{

	public static function toComposable(SkinData $data) : ComposableSkin{
		$image = $data->getSkinImage();
		return new ComposableSkin(
			new RgbaImage($image->getWidth(), $image->getHeight(), $image->getData()),
			$data->getGeometryDataJson(),
			$data->getResourcePatch(),
			$data->getArmSize() === SkinArmSizeType::SLIM
		);
	}

	/**
	 * Builds the SkinData sent to clients for a composed skin. Everything not touched by the
	 * composer (cape, animated face frames, arm size, colours) is copied from the original.
	 *
	 * @param bool $keepPersonaFlag send the result as a persona skin (with its piece list) rather than as a classic custom skin
	 */
	public static function fromComposition(SkinData $base, CompositionResult $result, bool $keepPersonaFlag) : SkinData{
		$image = $result->image;
		$persona = $keepPersonaFlag && $base->isPersona();
		return new SkinData(
			skinId: $base->getSkinId() . "_nkc_" . substr($result->geometryIdentifier, -8),
			resourcePatch: $result->resourcePatchJson,
			skinImage: new SkinImage($image->getHeight(), $image->getWidth(), $image->getData()),
			animations: $base->getAnimations(),
			capeImage: $base->getCapeImage(),
			geometryDataJson: $result->geometryJson,
			geometryDataEngineVersion: $base->getGeometryDataEngineVersion(),
			animationData: $base->getAnimationData(),
			capeId: $base->getCapeId(),
			fullSkinId: null,
			armSize: $base->getArmSize(),
			skinColor: $base->getSkinColor(),
			personaPieces: $persona ? $base->getPersonaPieces() : [],
			pieceTintColors: $persona ? $base->getPieceTintColors() : [],
			trustedSkinFlag: $base->getTrustedSkinFlag(),
			premium: $base->isPremium(),
			persona: $persona,
			personaCapeOnClassic: $base->isPersonaCapeOnClassic(),
			isPrimaryUser: $base->isPrimaryUser(),
			//clients log in with OverrideSkin=false; a server-changed skin must be marked as an override to be applied
			override: true,
			profileHash: $base->getProfileHash()
		);
	}
}
