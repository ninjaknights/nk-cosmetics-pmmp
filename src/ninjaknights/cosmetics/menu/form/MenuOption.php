<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\menu\form;

/**
 * One button of a MenuForm.
 */
final class MenuOption implements \JsonSerializable{

	/**
	 * @param string|null $iconPath resource pack texture path, without an extension
	 */
	public function __construct(
		private string $text,
		private ?string $iconPath = null
	){}

	public function getText() : string{
		return $this->text;
	}

	/**
	 * @return array{text: string, image?: array{type: string, data: string}}
	 */
	public function jsonSerialize() : array{
		$data = ["text" => $this->text];
		if($this->iconPath !== null){
			$data["image"] = ["type" => "path", "data" => $this->iconPath];
		}
		return $data;
	}
}
