<?php
/**
 * Текстовое описание колоды.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * У записи колоды обычно нет текста: всё содержимое — в полях. Описание собирает из полей связный текст,
 * по которому анализ может оценить страницу. Неизвестные поля в текст не попадают.
 */
final class DeckDescription {

	/**
	 * Описание колоды.
	 *
	 * @param Deck $deck Колода.
	 */
	public static function text( Deck $deck ): string {
		$facts = array();

		if ( '' !== $deck->hero_class ) {
			$facts[] = 'класс ' . $deck->hero_class;
		}

		if ( '' !== $deck->mode ) {
			$facts[] = 'режим ' . $deck->mode;
		}

		if ( $deck->dust > 0 ) {
			$facts[] = 'стоимость ' . $deck->dust . ' пыли';
		}

		$sentences = array(
			sprintf( 'Колода «%s» для Hearthstone%s.', $deck->title, array() !== $facts ? ': ' . implode( ', ', $facts ) : '' ),
		);

		if ( '' !== $deck->archetype && mb_strtolower( $deck->archetype ) !== mb_strtolower( $deck->title ) ) {
			$sentences[] = sprintf( 'Архетип: %s.', $deck->archetype );
		}

		if ( '' !== $deck->player ) {
			$sentences[] = sprintf( 'С этой колодой играл %s.', $deck->player );
		}

		if ( '' !== $deck->code ) {
			$sentences[] = 'Скопируйте код колоды и вставьте его в игре, чтобы собрать её в коллекции.';
		}

		return implode( ' ', $sentences );
	}
}
