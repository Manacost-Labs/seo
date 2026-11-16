<?php
/**
 * Правило включения страницы в карту сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * В карту попадают только страницы, которые поисковик может проиндексировать под их собственным адресом.
 */
final class EntryPolicy {

	/**
	 * Можно ли включить страницу в карту.
	 *
	 * @param string                                    $url        Адрес страницы.
	 * @param array{indexable: bool, canonical: string} $indexing   Решение резолвера: открыта ли страница и её каноникал.
	 * @param bool                                      $redirected Стоит ли с адреса страницы редирект.
	 */
	public static function allows( string $url, array $indexing, bool $redirected ): bool {
		if ( $redirected || empty( $indexing['indexable'] ) ) {
			return false;
		}

		$canonical = (string) ( $indexing['canonical'] ?? '' );

		return '' === $canonical || self::same_address( $canonical, $url );
	}

	/**
	 * Один ли это адрес: без учёта протокола, регистра хоста и слэша в конце.
	 *
	 * @param string $first  Первый адрес.
	 * @param string $second Второй адрес.
	 */
	public static function same_address( string $first, string $second ): bool {
		return self::key( $first ) === self::key( $second );
	}

	/**
	 * Адрес для сравнения: без протокола, регистра хоста и слэша в конце.
	 *
	 * @param string $url Адрес.
	 */
	private static function key( string $url ): string {
		$url   = (string) preg_replace( '#^https?://#i', '', trim( $url ) );
		$parts = explode( '/', $url, 2 );

		return rtrim( strtolower( $parts[0] ) . '/' . rawurldecode( $parts[1] ?? '' ), '/' );
	}
}
