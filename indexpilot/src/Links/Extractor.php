<?php
/**
 * Разбор ссылок в тексте.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Достаёт ссылки из HTML и делит их на внутренние и внешние.
 */
final class Extractor {

	/**
	 * Ссылки текста без повторов, в порядке появления. Якоря, почта, телефоны и скрипты пропускаются.
	 * Внутренние адреса приводятся к одному виду: https, хост сайта без www, без параметров и якоря.
	 *
	 * @param string $html      HTML текста.
	 * @param string $home_host Хост сайта.
	 * @return list<array{url: string, type: string, nofollow: bool}>
	 */
	public static function extract( string $html, string $home_host ): array {
		if ( ! preg_match_all( '#<a\b([^>]*)>#isu', $html, $matches ) ) {
			return array();
		}

		$home  = self::bare_host( $home_host );
		$links = array();

		foreach ( $matches[1] as $attributes ) {
			if ( 1 !== preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#isu', $attributes, $href ) ) {
				continue;
			}

			$url = html_entity_decode( trim( $href[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( str_starts_with( $url, '//' ) ) {
				$url = 'https:' . $url;
			} elseif ( str_starts_with( $url, '/' ) ) {
				$url = 'https://' . $home . $url;
			}

			if ( 1 !== preg_match( '#^https?://#i', $url ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress и проверяется модульными тестами.
			$parts    = parse_url( $url );
			$host     = self::bare_host( (string) ( $parts['host'] ?? '' ) );
			$internal = '' !== $host && $host === $home;

			if ( $internal ) {
				$url = 'https://' . $home . ( $parts['path'] ?? '/' );
			}

			if ( isset( $links[ $url ] ) ) {
				continue;
			}

			$nofollow = 1 === preg_match( '#\brel\s*=\s*(["\'])([^"\']*)\1#isu', $attributes, $rel )
				&& in_array( 'nofollow', explode( ' ', (string) preg_replace( '/\s+/', ' ', strtolower( trim( $rel[2] ) ) ) ), true );

			$links[ $url ] = array(
				'url'      => $url,
				'type'     => $internal ? 'internal' : 'external',
				'nofollow' => $nofollow,
			);
		}

		return array_values( $links );
	}

	/**
	 * Хост в нижнем регистре без www.
	 *
	 * @param string $host Хост.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
