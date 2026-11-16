<?php
/**
 * Выборка адресов для сравнения из карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tools\CompareHead;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- свойства DOM названы в camelCase.

/**
 * Берёт из карты сайта по N адресов каждого вида (записи, страницы, колоды, рубрики, метки, авторы…)
 * и добавляет главную, страницу поиска и несуществующий адрес.
 *
 * Вид определяется по имени файла карты: `sitemap-pt-post-2.xml` (IndexPilot), `wp-sitemap-posts-post-1.xml`
 * (WordPress), `post-sitemap2.xml` (AIOSEO) — все дают вид `post`, поэтому выборки «до» и «после» сопоставимы.
 */
final class SitemapSampler {

	/**
	 * Где искать индекс карты: сначала адрес IndexPilot, затем общий, затем штатный WordPress.
	 */
	public const INDEXES = array( '/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml' );

	public const SEARCH    = '/?s=%D0%BA%D0%BE%D0%BB%D0%BE%D0%B4%D0%B0';
	public const NOT_FOUND = '/indexpilot-compare-net-takoj-stranicy/';

	/**
	 * Загрузчик: адрес → `{ status, body }`.
	 *
	 * @var callable(string): array{status: int, body: string}
	 */
	private $fetch;

	/**
	 * Конструктор.
	 *
	 * @param callable(string): array{status: int, body: string} $fetch   Загрузчик.
	 * @param int                                                $per_kind Сколько адресов брать на вид.
	 */
	public function __construct( callable $fetch, private int $per_kind = 50 ) {
		$this->fetch = $fetch;
	}

	/**
	 * Собирает выборку.
	 *
	 * @param string $site Адрес сайта, например https://hs-manacost.ru.
	 * @return array{index: string, kinds: array<string, int>, urls: list<string>}
	 */
	public function sample( string $site ): array {
		$site  = rtrim( $site, '/' );
		$index = '';
		$maps  = array();

		foreach ( self::INDEXES as $path ) {
			$response = ( $this->fetch )( $site . $path );

			if ( 200 !== $response['status'] ) {
				continue;
			}

			$parsed = self::parse( $response['body'] );

			if ( $parsed['maps'] || $parsed['urls'] ) {
				$index = $site . $path;
				$maps  = $parsed['maps'] ? $parsed['maps'] : array( $site . $path );
				break;
			}
		}

		$by_kind = array();
		$seen    = array();
		$queue   = $maps;
		$depth   = array_fill_keys( $maps, 0 );

		while ( $queue ) {
			$map  = array_shift( $queue );
			$kind = self::kind( $map );

			if ( isset( $by_kind[ $kind ] ) && count( $by_kind[ $kind ] ) >= $this->per_kind ) {
				continue;
			}

			$response = ( $this->fetch )( $map );

			if ( 200 !== $response['status'] ) {
				continue;
			}

			$parsed = self::parse( $response['body'] );

			// Индекс внутри индекса (так бывает у AIOSEO): вложенные карты встают в очередь.
			foreach ( $parsed['maps'] as $child ) {
				if ( ! isset( $depth[ $child ] ) && $depth[ $map ] < 2 ) {
					$depth[ $child ] = $depth[ $map ] + 1;
					$queue[]         = $child;
				}
			}

			foreach ( $parsed['urls'] as $url ) {
				if ( isset( $seen[ $url ] ) || ( isset( $by_kind[ $kind ] ) && count( $by_kind[ $kind ] ) >= $this->per_kind ) ) {
					continue;
				}

				$seen[ $url ]       = true;
				$by_kind[ $kind ][] = $url;
			}
		}

		$urls = array( $site . '/' );

		foreach ( $by_kind as $list ) {
			foreach ( $list as $url ) {
				$urls[] = $url;
			}
		}

		$urls[] = $site . self::SEARCH;
		$urls[] = $site . self::NOT_FOUND;

		return array(
			'index' => $index,
			'kinds' => array_map( 'count', $by_kind ),
			'urls'  => array_values( array_unique( $urls ) ),
		);
	}

	/**
	 * Вид карты по имени файла.
	 *
	 * @param string $map_url Адрес файла карты.
	 */
	public static function kind( string $map_url ): string {
		$path = (string) parse_url( $map_url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- инструмент работает без WordPress.
		$name = strtolower( basename( $path, '.xml' ) );

		// Номер страницы карты: -2, 2, _2.
		$name = (string) preg_replace( '/[-_]?\d+$/', '', $name );

		foreach ( array( '/^wp-sitemap-(posts|taxonomies|users)-/', '/^sitemap-(pt|tax)-/', '/^sitemap[-_]/', '/[-_]sitemap$/' ) as $pattern ) {
			$name = (string) preg_replace( $pattern, '', $name );
		}

		if ( preg_match( '/^wp-sitemap-users$|^author$|^authors$|^users$/', $name ) ) {
			return 'author';
		}

		return '' === $name ? 'sitemap' : $name;
	}

	/**
	 * Разбирает XML карты.
	 *
	 * @param string $xml Текст карты.
	 * @return array{maps: list<string>, urls: list<string>}
	 */
	public static function parse( string $xml ): array {
		$result = array(
			'maps' => array(),
			'urls' => array(),
		);

		if ( '' === trim( $xml ) ) {
			return $result;
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || null === $doc->documentElement ) {
			return $result;
		}

		$root = $doc->documentElement->localName;
		$key  = 'sitemapindex' === $root ? 'maps' : ( 'urlset' === $root ? 'urls' : '' );

		if ( '' === $key ) {
			return $result;
		}

		foreach ( $doc->documentElement->childNodes as $entry ) {
			if ( ! $entry instanceof \DOMElement ) {
				continue;
			}

			foreach ( $entry->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'loc' === $child->localName ) {
					$loc = trim( $child->textContent );

					if ( '' !== $loc ) {
						$result[ $key ][] = $loc;
					}
				}
			}
		}

		return $result;
	}
}
