<?php
/**
 * Результаты самопроверки в таблице indexpilot_health.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Health;

/**
 * Таблица проверенных адресов и состояния обходов (в опциях без автозагрузки).
 */
final class WpStore implements Store {

	public const OPTION  = 'indexpilot_health_db_version';
	public const VERSION = '1';

	/**
	 * Префикс опций состояния обходов.
	 */
	public const STATE_PREFIX = 'indexpilot_health_';

	private const COLUMNS = 'kind, url, post_id, status, problems, fails, ms, words, checked';

	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Таблица результатов.
	 *
	 * @param \wpdb $db База данных.
	 */
	public static function table_name( \wpdb $db ): string {
		return $db->prefix . 'indexpilot_health';
	}

	/**
	 * Таблица результатов.
	 */
	public function table(): string {
		return self::table_name( $this->db );
	}

	/**
	 * Готова ли таблица. Опция версии загружается вместе с остальными.
	 */
	public static function installed(): bool {
		return self::VERSION === (string) get_option( self::OPTION, '' );
	}

	/**
	 * Создаёт таблицу, если её версия устарела.
	 *
	 * @param \wpdb $db База данных.
	 */
	public static function maybe_install( \wpdb $db ): void {
		if ( self::installed() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . self::table_name( $db ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL,
				url text NOT NULL,
				url_hash char(32) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status smallint(5) unsigned NOT NULL DEFAULT 0,
				problems varchar(100) NOT NULL DEFAULT '',
				fails tinyint(3) unsigned NOT NULL DEFAULT 0,
				ms int(10) unsigned NOT NULL DEFAULT 0,
				words int(10) unsigned NOT NULL DEFAULT 0,
				checked bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_url (kind, url_hash),
				KEY checked (checked),
				KEY fails (fails)
			) {$db->get_charset_collate()};"
		);

		// С автозагрузкой: версия читается на каждой странице и не должна стоить отдельного запроса.
		update_option( self::OPTION, self::VERSION, true );
	}

	public function get( string $kind, string $url ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, поиск по ключу.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE kind = %s AND url_hash = %s', $this->table(), $kind, md5( $url ) ), ARRAY_A );

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	public function save( string $kind, string $url, array $fields ): void {
		$data    = array();
		$formats = array();

		foreach ( array( 'post_id', 'status', 'fails', 'ms', 'words', 'checked' ) as $name ) {
			if ( array_key_exists( $name, $fields ) ) {
				$data[ $name ] = (int) $fields[ $name ];
				$formats[]     = '%d';
			}
		}

		if ( array_key_exists( 'problems', $fields ) ) {
			$data['problems'] = substr( (string) $fields['problems'], 0, 100 );
			$formats[]        = '%s';
		}

		if ( null !== $this->get( $kind, $url ) ) {
			if ( array() !== $data ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, фоновая проверка.
				$this->db->update( $this->table(), $data, array( 'kind' => $kind, 'url_hash' => md5( $url ) ), $formats, array( '%s', '%s' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- собственная таблица, фоновая проверка.
		$this->db->insert(
			$this->table(),
			array(
				'kind'     => $kind,
				'url'      => $url,
				'url_hash' => md5( $url ),
			) + $data,
			array_merge( array( '%s', '%s', '%s' ), $formats )
		);
	}

	public function due( array $kinds, int $now, int $limit ): array {
		if ( array() === $kinds || ! self::installed() ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу видов.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, фоновая проверка.
		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT ' . self::COLUMNS . " FROM %i WHERE kind IN ( {$placeholders} ) AND ( ( fails = 0 AND checked <= %d ) OR ( fails > 0 AND checked <= %d ) ) ORDER BY checked ASC LIMIT %d",
				array_merge( array( $this->table() ), $kinds, array( $now - OutboundCheck::RECHECK_OK, $now - OutboundCheck::RECHECK_FAILED, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	public function remove_older( string $kind, int $before ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица.
		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE kind = %s AND checked < %d', $this->table(), $kind, $before ) );
	}

	public function state( string $name ): array {
		$state = get_option( self::STATE_PREFIX . $name, array() );

		return is_array( $state ) ? $state : array();
	}

	public function save_state( string $name, array $state ): void {
		update_option( self::STATE_PREFIX . $name, $state, false );
	}

	/**
	 * Страницы сайта с проблемами.
	 *
	 * @param int $limit Сколько строк.
	 * @return list<array<string, mixed>>
	 */
	public function problems( int $limit ): array {
		if ( ! self::installed() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE kind = %s AND problems <> '' ORDER BY checked DESC LIMIT %d", $this->table(), SelfCheck::KIND, $limit ), ARRAY_A );

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	/**
	 * Мёртвые внешние адреса.
	 *
	 * @param int $limit Сколько строк.
	 * @return list<array<string, mixed>>
	 */
	public function dead( int $limit ): array {
		if ( ! self::installed() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE kind <> %s AND fails >= %d ORDER BY post_id DESC LIMIT %d', $this->table(), SelfCheck::KIND, OutboundCheck::DEAD_AFTER, $limit ), ARRAY_A );

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	/**
	 * Дешёвые подсчёты для экрана «Обзор»: страницы с проблемами и мёртвые внешние адреса.
	 *
	 * @return array{problems: int, dead: int, total: int}
	 */
	public function counts(): array {
		if ( ! self::installed() ) {
			return array(
				'problems' => 0,
				'dead'     => 0,
				'total'    => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт по собственной таблице.
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT SUM( CASE WHEN kind = %s AND problems <> '' THEN 1 ELSE 0 END ) AS problems, SUM( CASE WHEN kind <> %s AND fails >= %d THEN 1 ELSE 0 END ) AS dead, COUNT(*) AS total FROM %i",
				SelfCheck::KIND,
				SelfCheck::KIND,
				OutboundCheck::DEAD_AFTER,
				$this->table()
			),
			ARRAY_A
		);

		return array(
			'problems' => (int) ( $row['problems'] ?? 0 ),
			'dead'     => (int) ( $row['dead'] ?? 0 ),
			'total'    => (int) ( $row['total'] ?? 0 ),
		);
	}

	/**
	 * Какие из адресов мертвы (вид link): для отметки в списке коротких ссылок.
	 *
	 * @param list<string> $urls Адреса.
	 * @return array<string, true>
	 */
	public function dead_urls( array $urls ): array {
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		if ( array() === $urls || ! self::installed() ) {
			return array();
		}

		$hashes       = array_map( 'md5', $urls );
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу адресов.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один запрос на список в админке.
		$dead = $this->db->get_col( $this->db->prepare( "SELECT url FROM %i WHERE kind = 'link' AND fails >= %d AND url_hash IN ( {$placeholders} )", array_merge( array( $this->table(), OutboundCheck::DEAD_AFTER ), $hashes ) ) );
		// phpcs:enable

		return array_fill_keys( array_map( 'strval', (array) $dead ), true );
	}

	/**
	 * Удаляет строку адреса.
	 *
	 * @param string $kind Вид.
	 * @param string $url  Адрес.
	 */
	public function delete( string $kind, string $url ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица.
		$this->db->delete( $this->table(), array( 'kind' => $kind, 'url_hash' => md5( $url ) ), array( '%s', '%s' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Строка таблицы с типами.
	 *
	 * @param array<string, mixed> $row Строка.
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		return array(
			'kind'     => (string) $row['kind'],
			'url'      => (string) $row['url'],
			'post_id'  => (int) $row['post_id'],
			'status'   => (int) $row['status'],
			'problems' => (string) $row['problems'],
			'fails'    => (int) $row['fails'],
			'ms'       => (int) $row['ms'],
			'words'    => (int) $row['words'],
			'checked'  => (int) $row['checked'],
		);
	}
}
