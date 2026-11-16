<?php
/**
 * Разведение игр без WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Правила, по которым материалы разных игр не смешиваются: название игры в заголовке, одинаковые ключи
 * в разных играх, предки и потомки рубрик для определения раздела игры.
 */
final class GameScope {

	/**
	 * Сколько уровней рубрик просматривается вверх и вниз.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * Названия игры, которые стоит добавить в заголовок: заголовок говорит о классе, который есть и в другой игре
	 * («жрец» есть и в Hearthstone, и в World of Warcraft), а игра не названа. Игру сайта по умолчанию называть
	 * не нужно: без уточнения читатель и поисковик и так считают материал о ней.
	 *
	 * @param string $title Заголовок.
	 * @param string $game  Игра материала.
	 * @param Games  $games Реестр игр.
	 * @return list<string> Пусто — добавлять нечего.
	 */
	public static function missing_names( string $title, string $game, Games $games ): array {
		if ( $game === $games->default_game() ) {
			return array();
		}

		$entry = $games->get( $game );

		if ( array() === (array) ( $entry['root_term_ids'] ?? array() ) ) {
			return array();
		}

		$names = array_values( array_unique( array_filter( array_merge( array( (string) $entry['name'], (string) ( $entry['schema_name'] ?? '' ) ), (array) ( $entry['aliases'] ?? array() ) ), 'strlen' ) ) );

		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu', $title ) ) {
				return array();
			}
		}

		if ( ! self::shared_class( $games->lexicon( $game )->classes( $title ), $game, $games ) ) {
			return array();
		}

		return array_values( array_unique( array_merge( array( (string) $entry['name'] ), array_slice( (array) ( $entry['aliases'] ?? array() ), 0, 1 ) ) ) );
	}

	/**
	 * Записи с одинаковым основным ключом в разных играх.
	 *
	 * @param list<array{post_id: int|string, keyword: string, game: string}> $rows    Строки: ID записи, ключ, игра (пусто — игра по умолчанию).
	 * @param string                                                          $default_game Игра по умолчанию.
	 * @return list<array{keyword: string, posts: list<int>}>
	 */
	public static function cross_game_groups( array $rows, string $default_game ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			$keyword = self::key( (string) ( $row['keyword'] ?? '' ) );

			if ( '' === $keyword ) {
				continue;
			}

			$game = '' !== (string) ( $row['game'] ?? '' ) ? (string) $row['game'] : $default_game;

			$groups[ $keyword ]['games'][ $game ] = true;
			$groups[ $keyword ]['posts'][]        = (int) $row['post_id'];
		}

		$result = array();

		foreach ( $groups as $keyword => $group ) {
			if ( count( $group['games'] ) > 1 ) {
				$result[] = array(
					'keyword' => (string) $keyword,
					'posts'   => $group['posts'],
				);
			}
		}

		return $result;
	}

	/**
	 * Предки каждой рубрики по карте «рубрика => родитель», от ближнего к дальнему.
	 *
	 * @param list<int>       $term_ids ID рубрик.
	 * @param array<int, int> $parents  Карта родителей.
	 * @return array<int, list<int>>
	 */
	public static function ancestry( array $term_ids, array $parents ): array {
		$result = array();

		foreach ( $term_ids as $term_id ) {
			$chain  = array();
			$seen   = array( (int) $term_id => true );
			$parent = (int) ( $parents[ (int) $term_id ] ?? 0 );
			$depth  = 0;

			while ( $parent > 0 && ! isset( $seen[ $parent ] ) && $depth < self::MAX_DEPTH ) {
				++$depth;
				$seen[ $parent ] = true;
				$chain[]         = $parent;
				$parent          = (int) ( $parents[ $parent ] ?? 0 );
			}

			$result[ (int) $term_id ] = $chain;
		}

		return $result;
	}

	/**
	 * Корни и все их потомки по карте «рубрика => родитель», по возрастанию ID.
	 *
	 * @param list<int>       $roots   ID корней.
	 * @param array<int, int> $parents Карта родителей.
	 * @return list<int>
	 */
	public static function descendants( array $roots, array $parents ): array {
		$found = array();

		foreach ( $roots as $root ) {
			if ( (int) $root > 0 ) {
				$found[ (int) $root ] = true;
			}
		}

		for ( $depth = 0; $depth < self::MAX_DEPTH; ++$depth ) {
			$added = false;

			foreach ( $parents as $term_id => $parent ) {
				if ( isset( $found[ (int) $parent ] ) && ! isset( $found[ (int) $term_id ] ) ) {
					$found[ (int) $term_id ] = true;
					$added                   = true;
				}
			}

			if ( ! $added ) {
				break;
			}
		}

		$ids = array_keys( $found );
		sort( $ids );

		return $ids;
	}

	/**
	 * О ком раздел, в родительном падеже: «жреца», у специализации — «жреца (Тьма)». Пусто — класс неизвестен.
	 *
	 * @param string      $slug    Слаг класса или специализации из словаря.
	 * @param GameLexicon $lexicon Словарь игры.
	 */
	public static function subject( string $slug, GameLexicon $lexicon ): string {
		foreach ( $lexicon->class_slugs() as $base ) {
			if ( $slug !== $base && ! str_starts_with( $slug, $base . '-' ) ) {
				continue;
			}

			$genitive = HubSummary::genitive( $base );

			if ( '' === $genitive ) {
				return '';
			}

			if ( $slug === $base ) {
				return $genitive;
			}

			$spec = trim( mb_substr( $lexicon->class_name( $slug ), mb_strlen( $lexicon->class_name( $base ) ) ) );

			return '' !== $spec ? $genitive . ' (' . $spec . ')' : $genitive;
		}

		return '';
	}

	/**
	 * Есть ли среди классов материала класс, который есть и в другой игре.
	 *
	 * @param list<string> $classes Классы и специализации из словаря игры материала.
	 * @param string       $game    Игра материала.
	 * @param Games        $games   Реестр игр.
	 */
	private static function shared_class( array $classes, string $game, Games $games ): bool {
		foreach ( $games->all() as $other ) {
			if ( $other['slug'] === $game ) {
				continue;
			}

			foreach ( $games->lexicon( (string) $other['slug'] )->class_slugs() as $slug ) {
				foreach ( $classes as $found ) {
					if ( $found === $slug || str_starts_with( $found, $slug . '-' ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Ключ для сравнения: нижний регистр, «ё» как «е», одиночные пробелы.
	 *
	 * @param string $keyword Ключ.
	 */
	private static function key( string $keyword ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( 'ё', 'е', mb_strtolower( $keyword, 'UTF-8' ) ) ) );
	}
}
