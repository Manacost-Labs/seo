<?php
/**
 * Переменные шаблонов для колод.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Resolver\Context;
use IndexPilot\Variables\VariableRegistry;

/**
 * Переменные %deck_class%, %deck_mode%, %deck_archetype%, %deck_dust%, %deck_player%.
 * Работают одинаково на обоих сайтах: данные приводит к одному виду адаптер сайта.
 * У страниц, которые не являются колодами, все эти переменные пустые.
 */
final class DeckVariables {

	public const POST_TYPE = 'hs_deck';

	/**
	 * Конструктор.
	 *
	 * @param DeckData $decks Данные колод.
	 */
	public function __construct( private DeckData $decks ) {
	}

	/**
	 * Регистрирует переменные.
	 *
	 * @param VariableRegistry $registry Реестр переменных.
	 */
	public function register( VariableRegistry $registry ): void {
		$fields = array(
			'deck_class'     => static fn( Deck $deck ): string => $deck->hero_class,
			'deck_mode'      => static fn( Deck $deck ): string => $deck->mode,
			'deck_archetype' => static fn( Deck $deck ): string => $deck->archetype,
			'deck_dust'      => static fn( Deck $deck ): string => $deck->dust > 0 ? (string) $deck->dust : '',
			'deck_player'    => static fn( Deck $deck ): string => $deck->player,
		);

		foreach ( $fields as $name => $field ) {
			$registry->register(
				$name,
				fn( Context $context ): string => Context::POST === $context->type && self::POST_TYPE === $context->subtype
					? $field( $this->decks->deck( $context->object_id ) )
					: ''
			);
		}
	}
}
