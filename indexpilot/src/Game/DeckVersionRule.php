<?php
/**
 * Правило версий колоды.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Одна и та же колода публикуется много раз: у версий одинаковые архетип, класс и режим и одинаковое название.
 * Поисковику нужна одна страница — актуальная версия. Остальные указывают на неё каноническим адресом.
 *
 * Актуальная версия — самая новая из тех, что не в архиве; если в архиве все, то просто самая новая.
 */
final class DeckVersionRule {

	/**
	 * Для каждой колоды — ID её актуальной версии.
	 *
	 * @param list<array{id: int, group: string, date: string, archived: bool}> $decks Опубликованные колоды.
	 * @return array<int, int> ID колоды => ID актуальной версии (у актуальной — она сама).
	 */
	public static function current( array $decks ): array {
		$best = array();

		foreach ( $decks as $deck ) {
			$group = (string) $deck['group'];

			if ( '' === $group ) {
				continue;
			}

			if ( ! isset( $best[ $group ] ) || self::better( $deck, $best[ $group ] ) ) {
				$best[ $group ] = $deck;
			}
		}

		$map = array();

		foreach ( $decks as $deck ) {
			$group                    = (string) $deck['group'];
			$map[ (int) $deck['id'] ] = '' === $group ? (int) $deck['id'] : (int) $best[ $group ]['id'];
		}

		return $map;
	}

	/**
	 * Лучше ли первая колода подходит на роль актуальной, чем вторая.
	 *
	 * @param array{id: int, group: string, date: string, archived: bool} $candidate Претендент.
	 * @param array{id: int, group: string, date: string, archived: bool} $current   Текущая лучшая.
	 */
	private static function better( array $candidate, array $current ): bool {
		if ( (bool) $candidate['archived'] !== (bool) $current['archived'] ) {
			return ! $candidate['archived'];
		}

		if ( $candidate['date'] !== $current['date'] ) {
			return $candidate['date'] > $current['date'];
		}

		return (int) $candidate['id'] > (int) $current['id'];
	}
}
