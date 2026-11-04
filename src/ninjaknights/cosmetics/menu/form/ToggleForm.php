<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\menu\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use function array_is_list;
use function array_key_first;
use function array_keys;
use function array_shift;
use function count;
use function gettype;
use function is_array;
use function is_bool;

/**
 * A line of text above a set of on/off switches.
 */
final class ToggleForm implements Form{

	/**
	 * @param array<string, array{text: string, default: bool}> $toggles     by name
	 * @param \Closure(Player, array<string, bool>) : void      $onSubmit    receives each toggle by name
	 */
	public function __construct(
		private string $title,
		private string $label,
		private array $toggles,
		private \Closure $onSubmit
	){}

	public function getTitle() : string{
		return $this->title;
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return;
		}
		if(!is_array($data) || !array_is_list($data)){
			throw new FormValidationException("Expected a list or null, got " . gettype($data));
		}
		//clients before 1.21.70 send a null for the label; later ones leave it out
		if(count($data) === count($this->toggles) + 1){
			if($data[array_key_first($data)] !== null){
				throw new FormValidationException("Expected null for the label");
			}
			array_shift($data);
		}
		if(count($data) !== count($this->toggles)){
			throw new FormValidationException("Expected " . count($this->toggles) . " toggles, got " . count($data));
		}
		$values = [];
		foreach(array_keys($this->toggles) as $index => $name){
			$value = $data[$index];
			if(!is_bool($value)){
				throw new FormValidationException("Expected bool for \"$name\", got " . gettype($value));
			}
			$values[$name] = $value;
		}
		($this->onSubmit)($player, $values);
	}

	/**
	 * @return array{content: list<array<string, string|bool>>, type: string, title: string}
	 */
	public function jsonSerialize() : array{
		$content = [["type" => "label", "text" => $this->label]];
		foreach($this->toggles as $toggle){
			$content[] = ["default" => $toggle["default"], "type" => "toggle", "text" => $toggle["text"]];
		}
		return [
			"content" => $content,
			"type" => "custom_form",
			"title" => $this->title
		];
	}
}
