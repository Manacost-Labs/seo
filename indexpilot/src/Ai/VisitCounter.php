<?php
/**
 * Счётчик заходов ассистентов на сайте.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai;

use IndexPilot\Http\Referrers;

/**
 * Считает заходы роботов ассистентов и переходы читателей из ассистентов.
 *
 * Как устроена лёгкость. На обычной странице плагин только сравнивает строку браузера и Referer со списком —
 * запросов к базе нет. Заход засчитывается лишь роботу ассистента или читателю из ассистента, и только после
 * ответа 200. Он кладётся в копилку: при постоянном объектном кеше (Redis, Memcached) — в кеш, без базы;
 * без него — в опцию без автозагрузки (одно чтение и одна запись на такой заход). В таблицу копилка выгружается
 * пачкой не чаще раза в 10 минут или при 300 разных строках. Строка таблицы — страница, робот и сутки; IP не хранится.
 */
final class VisitCounter {

	public const BUFFER_OPTION = 'indexpilot_ai_buffer';
	public const DB_OPTION     = 'indexpilot_ai_db_version';
	public const DB_VERSION    = '1';

	private const CACHE_GROUP = 'indexpilot_ai';

	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База данных.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Таблица счётчиков.
	 */
	public function table(): string {
		return $this->db->prefix . 'indexpilot_ai_visits';
	}

	/**
	 * Подключает учёт. Вызывается при загрузке плагина.
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'capture' ), 0 );
	}

	/**
	 * Узнаёт робота или переход из ассистента. Обычный посетитель стоит два сравнения строк.
	 */
	public function capture(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- строки только сравниваются со списком и не сохраняются.
		$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		// phpcs:enable

		$source = Visits::source( substr( $agent, 0, 500 ), '' !== $referrer ? Referrers::host( $referrer ) : '' );

		if ( null === $source ) {
			return;
		}

		$path = Visits::path( $uri );

		// Засчитывается только страница, которая ответила 200: llms.txt и .md отдаются раньше шаблона, поэтому — на shutdown.
		add_action(
			'shutdown',
			function () use ( $path, $source ): void {
				if ( 200 === http_response_code() ) {
					$this->record( $path, $source['kind'], $source['agent'] );
				}
			}
		);
	}

	/**
	 * Кладёт заход в копилку и при необходимости выгружает её.
	 *
	 * @param string $path  Путь страницы.
	 * @param string $kind  bot или ref.
	 * @param string $agent Робот или ассистент.
	 */
	public function record( string $path, string $kind, string $agent ): void {
		$buffer = Visits::add( $this->buffer(), gmdate( 'Y-m-d' ), $path, $kind, $agent, time() );

		if ( Visits::due( $buffer, time() ) ) {
			$this->write( $buffer );
			$this->store( array() );

			return;
		}

		$this->store( $buffer );
	}

	/**
	 * Выгружает копилку сразу: перед показом отчёта.
	 */
	public function flush(): void {
		$buffer = $this->buffer();

		if ( array() !== Visits::rows( $buffer ) ) {
			$this->write( $buffer );
			$this->store( array() );
		}
	}

	/**
	 * Страницы, которые читают роботы ассистентов, или страницы, куда приходят читатели из ассистентов.
	 *
	 * @param string $kind bot или ref.
	 * @param int    $days За сколько дней.
	 * @return list<array{path: string, agent: string, hits: int}>
	 */
	public function top( string $kind, int $days = 30 ): array {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт по собственной таблице.
		$rows = (array) $this->db->get_results( $this->db->prepare( 'SELECT path, agent, SUM(hits) AS hits FROM %i WHERE kind = %s AND day >= %s GROUP BY path, agent ORDER BY hits DESC LIMIT 100', $this->table(), $kind, gmdate( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS ) ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'path'  => (string) $row['path'],
				'agent' => (string) $row['agent'],
				'hits'  => (int) $row['hits'],
			),
			$rows
		);
	}

	/**
	 * Копилка из кеша или опции.
	 *
	 * @return array<string, mixed>
	 */
	private function buffer(): array {
		$buffer = wp_using_ext_object_cache() ? wp_cache_get( 'buffer', self::CACHE_GROUP ) : get_option( self::BUFFER_OPTION, array() );

		return is_array( $buffer ) ? $buffer : array();
	}

	/**
	 * Сохраняет копилку.
	 *
	 * @param array<string, mixed> $buffer Копилка.
	 */
	private function store( array $buffer ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( 'buffer', $buffer, self::CACHE_GROUP, DAY_IN_SECONDS );

			return;
		}

		update_option( self::BUFFER_OPTION, $buffer, false );
	}

	/**
	 * Пишет копилку в таблицу: по строке на страницу, робота и сутки.
	 *
	 * @param array<string, mixed> $buffer Копилка.
	 */
	private function write( array $buffer ): void {
		$this->maybe_install();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- пачка счётчиков в собственную таблицу раз в несколько минут.
		foreach ( Visits::rows( $buffer ) as $row ) {
			$hash    = md5( $row['path'] );
			$updated = $this->db->query( $this->db->prepare( 'UPDATE %i SET hits = hits + %d WHERE day = %s AND kind = %s AND agent = %s AND path_hash = %s', $this->table(), $row['hits'], $row['day'], $row['kind'], $row['agent'], $hash ) );

			if ( 0 === $updated ) {
				$this->db->insert(
					$this->table(),
					array(
						'day'       => $row['day'],
						'kind'      => $row['kind'],
						'agent'     => $row['agent'],
						'path'      => $row['path'],
						'path_hash' => $hash,
						'hits'      => $row['hits'],
					),
					array( '%s', '%s', '%s', '%s', '%s', '%d' )
				);
			}
		}
		// phpcs:enable
	}

	/**
	 * Создаёт таблицу при первой выгрузке. Опция версии без автозагрузки: на обычных страницах её не читают.
	 */
	public function maybe_install(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$this->table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				day date NOT NULL,
				kind varchar(3) NOT NULL,
				agent varchar(40) NOT NULL,
				path varchar(190) NOT NULL,
				path_hash char(32) NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY visit (day, kind, agent, path_hash),
				KEY kind_day (kind, day)
			) {$this->db->get_charset_collate()};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}
}
