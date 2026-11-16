<?php
/**
 * Соседние страницы пагинации.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

/**
 * Номера соседних страниц для тегов rel="prev" и rel="next" и адрес страницы разбитой записи.
 * Не зависит от WordPress.
 */
final class Pagination {

	/**
	 * Номера предыдущей и следующей страницы; 0 — соседа нет.
	 *
	 * @param int $page  Текущая страница, с 1.
	 * @param int $total Всего страниц.
	 * @return array{prev: int, next: int}
	 */
	public static function neighbours( int $page, int $total ): array {
		$page = max( 1, $page );

		return array(
			'prev' => $page > 1 && $page <= $total ? $page - 1 : 0,
			'next' => $page < $total ? $page + 1 : 0,
		);
	}

	/**
	 * Число частей записи, разбитой тегом <!--nextpage-->.
	 *
	 * @param string $content Текст записи.
	 */
	public static function post_parts( string $content ): int {
		return substr_count( $content, '<!--nextpage-->' ) + 1;
	}

	/**
	 * Адрес части записи: первая — сам адрес записи, остальные — «адрес/N/» или «?page=N».
	 *
	 * @param string $permalink Адрес записи.
	 * @param int    $page      Номер части.
	 * @param bool   $pretty    Включены ли ЧПУ.
	 * @param bool   $slash     Ставит ли сайт косую черту в конце адресов.
	 */
	public static function post_page_url( string $permalink, int $page, bool $pretty, bool $slash = true ): string {
		if ( $page <= 1 ) {
			return $permalink;
		}

		if ( ! $pretty || str_contains( $permalink, '?' ) ) {
			return $permalink . ( str_contains( $permalink, '?' ) ? '&' : '?' ) . 'page=' . $page;
		}

		return rtrim( $permalink, '/' ) . '/' . $page . ( $slash ? '/' : '' );
	}
}
