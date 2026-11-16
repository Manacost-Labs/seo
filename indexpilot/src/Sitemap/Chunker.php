<?php
/**
 * Деление карты сайта на страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * Страница карты — это диапазон ID. Запрос «ID больше X и не больше Y» одинаково быстр
 * для первой и для последней страницы, в отличие от запроса со смещением (OFFSET).
 */
final class Chunker {

	/**
	 * Верхние границы страниц: ID последней записи каждой страницы.
	 *
	 * @param list<int> $sorted_ids Все ID по возрастанию.
	 * @param int       $size       Записей на странице.
	 * @return list<int>
	 */
	public static function boundaries( array $sorted_ids, int $size ): array {
		$boundaries = array();
		$total      = count( $sorted_ids );
		$size       = max( 1, $size );

		for ( $index = $size - 1; $index < $total; $index += $size ) {
			$boundaries[] = (int) $sorted_ids[ $index ];
		}

		if ( $total > 0 && 0 !== $total % $size ) {
			$boundaries[] = (int) $sorted_ids[ $total - 1 ];
		}

		return $boundaries;
	}

	/**
	 * Диапазон ID страницы: больше after и не больше upto; null — такой страницы нет.
	 *
	 * @param list<int> $boundaries Верхние границы страниц.
	 * @param int       $page       Номер страницы, начиная с 1.
	 * @return array{after: int, upto: int}|null
	 */
	public static function range( array $boundaries, int $page ): ?array {
		if ( $page < 1 || ! isset( $boundaries[ $page - 1 ] ) ) {
			return null;
		}

		return array(
			'after' => $page > 1 ? (int) $boundaries[ $page - 2 ] : 0,
			'upto'  => (int) $boundaries[ $page - 1 ],
		);
	}
}
