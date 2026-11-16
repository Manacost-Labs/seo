<?php
/**
 * Подбор связанных страниц для колоды.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\KeywordMatcher;

/**
 * Для страницы колоды выбирает гайды по её архетипу и другие колоды того же архетипа.
 * Так колода, гайд и страница архетипа ссылаются друг на друга по данным, а не вручную.
 */
final class RelatedLinks {

	/**
	 * Выбирает связанные страницы.
	 *
	 * @param Deck               $deck     Колода.
	 * @param array<int, string> $guides   Статьи-кандидаты: ID => заголовок.
	 * @param list<int>          $siblings Колоды того же архетипа, новые первыми.
	 * @param int                $limit    Сколько ссылок каждого вида вернуть.
	 * @param KeywordMatcher|null $matcher Сравнение с учётом форм слов.
	 * @return array{guides: list<int>, decks: list<int>}
	 */
	public static function pick( Deck $deck, array $guides, array $siblings, int $limit = 5, ?KeywordMatcher $matcher = null ): array {
		$matcher ??= ( new Analyzer() )->matcher();
		$found     = array();

		if ( '' !== $deck->archetype ) {
			foreach ( $guides as $id => $title ) {
				// Гайд относится к архетипу, если название архетипа есть в заголовке гайда (в любой форме слов).
				if ( (int) $id !== $deck->id && $matcher->contains( (string) $title, $deck->archetype ) ) {
					$found[] = (int) $id;
				}
			}
		}

		$decks = array();
		foreach ( $siblings as $id ) {
			if ( (int) $id !== $deck->id ) {
				$decks[] = (int) $id;
			}
		}

		return array(
			'guides' => array_slice( $found, 0, $limit ),
			'decks'  => array_slice( $decks, 0, $limit ),
		);
	}
}
