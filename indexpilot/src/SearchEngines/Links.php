<?php
/**
 * Ссылки на кабинеты поисковиков.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchEngines;

/**
 * Прямые ссылки в Google Search Console, Яндекс Вебмастер и Bing Webmaster для этого сайта и его страниц:
 * не нужно искать нужный раздел кабинета и вставлять адрес вручную.
 */
final class Links {

	/**
	 * Ссылки на разделы кабинетов для сайта.
	 *
	 * @param string $home Адрес главной страницы.
	 * @return array{google: array<string, string>, yandex: array<string, string>, bing: array<string, string>}
	 */
	public static function for_site( string $home ): array {
		$home   = rtrim( $home, '/' ) . '/';
		$site   = rawurlencode( $home );
		$yandex = 'https://webmaster.yandex.ru/site/' . self::yandex_host( $home );

		return array(
			'google' => array(
				'cabinet'  => 'https://search.google.com/search-console?resource_id=' . $site,
				'sitemaps' => 'https://search.google.com/search-console/sitemaps?resource_id=' . $site,
				'add'      => 'https://search.google.com/search-console/welcome',
			),
			'yandex' => array(
				'cabinet'  => $yandex . '/dashboard/',
				'sitemaps' => $yandex . '/indexing/sitemap/',
				'recrawl'  => $yandex . '/indexing/reindex/',
				'add'      => 'https://webmaster.yandex.ru/sites/add/',
			),
			'bing'   => array(
				'cabinet'  => 'https://www.bing.com/webmasters/home?siteUrl=' . $site,
				'sitemaps' => 'https://www.bing.com/webmasters/sitemaps?siteUrl=' . $site,
				'add'      => 'https://www.bing.com/webmasters/about',
			),
		);
	}

	/**
	 * Ссылки для проверки одной страницы: есть ли она в поиске и что о ней знает Google.
	 *
	 * @param string $home Адрес главной страницы.
	 * @param string $url  Адрес страницы.
	 * @return array{google_inspect: string, google_index: string, yandex_index: string}
	 */
	public static function for_url( string $home, string $url ): array {
		$home = rtrim( $home, '/' ) . '/';

		return array(
			'google_inspect' => 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $home ) . '&id=' . rawurlencode( $url ),
			'google_index'   => 'https://www.google.com/search?q=' . rawurlencode( 'site:' . $url ),
			'yandex_index'   => 'https://yandex.ru/search/?text=' . rawurlencode( 'url:' . $url ),
		);
	}

	/**
	 * Обозначение сайта в адресах Яндекс Вебмастера: протокол, хост и порт через двоеточие.
	 *
	 * @param string $home Адрес главной страницы.
	 */
	private static function yandex_host( string $home ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress и проверяется модульными тестами.
		$parts  = parse_url( $home );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$port   = (int) ( $parts['port'] ?? ( 'http' === $scheme ? 80 : 443 ) );

		return $scheme . ':' . strtolower( (string) ( $parts['host'] ?? '' ) ) . ':' . $port;
	}
}
