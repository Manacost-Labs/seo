<?php
/**
 * Поиск записи по адресу ссылки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Находит запись сайта по адресу ссылки.
 *
 * Штатная url_to_postid() на каждый адрес строит отдельный WP_Query — около пяти запросов к базе на ссылку.
 * При обходе пачки записей адреса всех её ссылок сначала ищутся разом: по последней части пути выбираются
 * опубликованные записи с таким ярлыком, и запись принимается, только если её постоянный адрес совпадает
 * с путём ссылки. Что так не нашлось (архивы, страницы пагинации, старые адреса), ищет url_to_postid().
 */
final class PostPathResolver {

	/**
	 * Найденные записи: путь ссылки → ID записи (0 — не запись).
	 *
	 * @var array<string, int>
	 */
	private array $cache = array();

	/**
	 * Пути, найденные при подготовке пачки: путь → ID записи.
	 *
	 * @var array<string, int>
	 */
	private array $primed = array();

	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * ID записи по адресу ссылки; 0 — это не запись.
	 *
	 * @param string $url Адрес ссылки (без параметров и порта).
	 */
	public function resolve( string $url ): int {
		$path = self::path( $url );

		if ( ! isset( $this->cache[ $path ] ) ) {
			$this->cache[ $path ] = $this->primed[ $path ] ?? (int) url_to_postid( home_url( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		}

		return $this->cache[ $path ];
	}

	/**
	 * Ищет записи для адресов пачки разом.
	 *
	 * @param list<string> $urls Адреса внутренних ссылок.
	 */
	public function prime( array $urls ): void {
		$slugs = array();

		foreach ( $urls as $url ) {
			$path = self::path( $url );

			if ( ! isset( $this->cache[ $path ] ) && ! isset( $this->primed[ $path ] ) ) {
				$slug = self::slug( $path );

				if ( '' !== $slug ) {
					$slugs[ $slug ] = true;
				}
			}
		}

		foreach ( array_chunk( array_keys( $slugs ), 200 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %s по числу ярлыков.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один запрос на пачку обхода ссылок.
			$ids = $this->db->get_col( $this->db->prepare( "SELECT ID FROM {$this->db->posts} WHERE post_status = 'publish' AND post_name IN ( {$placeholders} )", $chunk ) );
			// phpcs:enable

			$ids = array_map( 'intval', (array) $ids );

			_prime_post_caches( $ids, false, false );

			foreach ( $ids as $id ) {
				$path = self::path( (string) get_permalink( $id ) );

				if ( '' !== $path && ! isset( $this->primed[ $path ] ) ) {
					$this->primed[ $path ] = $id;
				}
			}
		}
	}

	/**
	 * Путь адреса для сравнения: без хоста и параметров, раскодированный, в нижнем регистре, без косой черты в конце.
	 *
	 * @param string $url Адрес.
	 */
	public static function path( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- чистая функция, проверяется модульными тестами.
		$path = (string) parse_url( $url, PHP_URL_PATH );

		return '/' . trim( mb_strtolower( rawurldecode( $path ) ), '/' );
	}

	/**
	 * Ярлык записи из пути: последняя часть пути в том виде, в каком WordPress хранит post_name.
	 *
	 * @param string $path Путь из path().
	 */
	public static function slug( string $path ): string {
		$parts = explode( '/', trim( $path, '/' ) );
		$last  = (string) end( $parts );

		// Кириллический ярлык WordPress хранит закодированным, в нижнем регистре.
		return '' === $last ? '' : strtolower( rawurlencode( $last ) );
	}
}
