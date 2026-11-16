<?php
/**
 * Ключи и запросы для рубрики.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Сводит запросы страницы рубрики (архетипа, класса) и запросы её записей в один список без повторов:
 * фразы, одинаковые по словарю игры, считаются одним запросом. Из списка предлагаются основной ключ,
 * дополнительные ключи и темы для вводного текста.
 */
final class TermQueries {

	public const EXTRA  = 4;
	public const TOPICS = 6;

	/**
	 * Собирает список.
	 *
	 * @param list<array<string, mixed>> $own       Запросы самой страницы рубрики.
	 * @param list<array<string, mixed>> $posts     Запросы записей рубрики.
	 * @param string                     $name      Название рубрики.
	 * @param callable(string): string   $normalize Фраза => ключ сравнения.
	 * @return array{queries: list<array{query: string, clicks: int, impressions: int, position: float}>, main: string, extra: list<string>, topics: list<string>, name_found: bool}
	 */
	public static function collect( array $own, array $posts, string $name, callable $normalize ): array {
		$rows = array_merge( $own, $posts );
		usort( $rows, static fn( array $a, array $b ): int => (int) $b['impressions'] <=> (int) $a['impressions'] );

		$merged = array();
		$weight = array();

		foreach ( $rows as $row ) {
			$key = $normalize( (string) $row['query'] );

			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = array(
					'query'       => mb_strtolower( trim( (string) $row['query'] ), 'UTF-8' ),
					'clicks'      => 0,
					'impressions' => 0,
					'position'    => 0.0,
				);
				$weight[ $key ] = 0.0;
			}

			$merged[ $key ]['clicks']      += (int) $row['clicks'];
			$merged[ $key ]['impressions'] += (int) $row['impressions'];
			$weight[ $key ]                += (float) $row['position'] * max( 1, (int) $row['impressions'] );
		}

		foreach ( $merged as $key => $item ) {
			$merged[ $key ]['position'] = round( $weight[ $key ] / max( 1, $item['impressions'] ), 1 );
		}

		$queries = array_values( $merged );
		usort( $queries, static fn( array $a, array $b ): int => $b['impressions'] <=> $a['impressions'] );

		$name_words = self::words( $normalize( $name ) );
		$found      = false;

		foreach ( array_keys( $merged ) as $key ) {
			if ( array() !== $name_words && array() === array_diff( $name_words, self::words( (string) $key ) ) ) {
				$found = true;
				break;
			}
		}

		$texts = array_column( $queries, 'query' );

		return array(
			'queries'    => $queries,
			'main'       => (string) ( $texts[0] ?? '' ),
			'extra'      => array_slice( $texts, 1, self::EXTRA ),
			'topics'     => array_slice( $texts, 1 + self::EXTRA, self::TOPICS ),
			'name_found' => $found,
		);
	}

	/**
	 * Слова нормализованной фразы.
	 *
	 * @param string $text Фраза.
	 * @return list<string>
	 */
	private static function words( string $text ): array {
		return array_values( array_filter( explode( ' ', $text ), 'strlen' ) );
	}
}
