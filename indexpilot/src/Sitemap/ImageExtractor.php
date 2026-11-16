<?php
/**
 * Картинки страницы для карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * Достаёт адреса картинок из HTML текста записи.
 */
final class ImageExtractor {

	/**
	 * Адреса картинок без повторов, в порядке появления.
	 *
	 * @param string $html  HTML текста.
	 * @param string $base  Адрес сайта без слэша в конце — им дополняются адреса, начинающиеся со слэша.
	 * @param int    $limit Наибольшее число картинок.
	 * @return list<string>
	 */
	public static function from_html( string $html, string $base, int $limit = 10 ): array {
		if ( ! preg_match_all( '#<img\b[^>]*\bsrc=(["\'])(.*?)\1#isu', $html, $matches ) ) {
			return array();
		}

		$images = array();

		foreach ( $matches[2] as $src ) {
			$src = html_entity_decode( trim( $src ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( str_starts_with( $src, '//' ) ) {
				$src = 'https:' . $src;
			} elseif ( str_starts_with( $src, '/' ) ) {
				$src = rtrim( $base, '/' ) . $src;
			}

			if ( 1 !== preg_match( '#^https?://#i', $src ) ) {
				continue;
			}

			$images[ $src ] = true;

			if ( count( $images ) >= $limit ) {
				break;
			}
		}

		return array_map( 'strval', array_keys( $images ) );
	}
}
