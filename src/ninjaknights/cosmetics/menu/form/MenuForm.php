<?php

declare(strict_types=1);

namespace ninjaknights\cosmetics\menu\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use function array_values;
use function gettype;
use function is_int;

/**
 * A list of buttons, of which the player picks one.
 */
final class MenuForm implements Form{

	/** @var list<MenuOption> */
	private array $options;

	/**
	 * @param MenuOption[]                  $options
	 * @param \Closure(Player, int) : void  $onSubmit
	 * @param (\Closure(Player) : void)|null $onClose
	 */
	public function __construct(
		private string $title,
		private string $text,
		array $options,
		private \Closure $onSubmit,
		private ?\Closure $onClose = null
	){
		$this->options = array_values($options);
	}

	public function getTitle() : string{
		return $this->title;
	}

	public function getOption(int $index) : ?MenuOption{
		return $this->options[$index] ?? null;
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			if($this->onClose !== null){
				($this->onClose)($player);
			}
			return;
		}
		if(!is_int($data)){
			throw new FormValidationException("Expected int or null, got " . gettype($data));
		}
		if(!isset($this->options[$data])){
			throw new FormValidationException("Option $data does not exist");
		}
		($this->onSubmit)($player, $data);
	}

	/**
	 * @return array{content: string, buttons: list<MenuOption>, type: string, title: string}
	 */
	public function jsonSerialize() : array{
		return [
			"content" => $this->text,
			"buttons" => $this->options,
			"type" => "form",
			"title" => $this->title
		];
	}
}
