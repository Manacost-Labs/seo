<?php
/**
 * Источник данных колоды.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * На двух сайтах колоды хранятся по-разному. Адаптер сайта приводит их к одному виду.
 */
interface DeckData {

	/**
	 * Колода по ID записи. Для несуществующей записи возвращается колода с пустыми значениями.
	 *
	 * @param int $post_id ID записи.
	 */
	public function deck( int $post_id ): Deck;
}
