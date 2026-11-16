<?php
/**
 * Таблица indexpilot_queries.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Запросы из поиска хранятся только в своей таблице, а не в настройках: в память WordPress при загрузке страниц
 * они не попадают и на страницах сайта не читаются. SQL одинаково работает в MySQL и SQLite.
 */
final class WpQueryStore implements QueryStore {

	public const DB_OPTION  = 'indexpilot_queries_db_version';
	public const DB_VERSION = '1';

	/**
	 * Две-три цифры для экрана «Обзор», пересчитываются после загрузки. С автозагрузкой: «Обзор» не тратит на них запрос.
	 */
	public const SUMMARY = 'indexpilot_queries_summary';

	private const COLUMNS = 'engine, period, page, object_type, object_id, query, clicks, impressions, position';

	private const CHUNK = 200;

	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Имя таблицы.
	 */
	public function table(): string {
		return $this->db->prefix . 'indexpilot_queries';
	}

	/**
	 * Готова ли таблица. Опция версии загружается вместе с остальными.
	 */
	public static function installed(): bool {
		return self::DB_VERSION === (string) get_option( self::DB_OPTION, '' );
	}

	/**
	 * Создаёт таблицу, если её версия устарела.
	 */
	public function maybe_install(): void {
		if ( self::installed() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . $this->table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				engine varchar(10) NOT NULL DEFAULT '',
				period varchar(10) NOT NULL DEFAULT '',
				page varchar(500) NOT NULL DEFAULT '',
				object_type varchar(10) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				query varchar(255) NOT NULL DEFAULT '',
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				impressions int(10) unsigned NOT NULL DEFAULT 0,
				position float NOT NULL DEFAULT 0,
				synced bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY object (object_type, object_id),
				KEY engine_period (engine, period)
			) {$this->db->get_charset_collate()};"
		);

		// С автозагрузкой: версия читается на каждой странице админки и не должна стоить отдельного запроса.
		update_option( self::DB_OPTION, self::DB_VERSION, true );
		// Пустая сводка сразу с автозагрузкой: отсутствующая опция стоила бы «Обзору» отдельного запроса.
		add_option( self::SUMMARY, array(), '', true );
	}

	public function replace( string $engine, string $period, array $rows, int $synced ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, фоновая загрузка.
		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE engine = %s AND period = %s', $this->table(), $engine, $period ) );

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$values = array();
			$args   = array( $this->table() );

			foreach ( $chunk as $row ) {
				$values[] = '( %s, %s, %s, %s, %d, %s, %d, %d, %f, %d )';
				array_push(
					$args,
					$engine,
					$period,
					mb_substr( (string) $row['page'], 0, 500 ),
					(string) ( $row['object_type'] ?? '' ),
					(int) ( $row['object_id'] ?? 0 ),
					mb_substr( (string) $row['query'], 0, 255 ),
					max( 0, (int) $row['clicks'] ),
					max( 0, (int) $row['impressions'] ),
					(float) $row['position'],
					$synced
				);
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляются только заполнители по числу строк.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, фоновая загрузка.
			$this->db->query( $this->db->prepare( 'INSERT INTO %i ( ' . self::COLUMNS . ', synced ) VALUES ' . implode( ', ', $values ), $args ) );
			// phpcs:enable
		}
	}

	public function remove_older( int $before ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица.
		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE synced < %d', $this->table(), $before ) );
	}

	public function keep_engines( array $engines ): void {
		if ( array() === $engines ) {
			$this->clear();
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $engines ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- только список заполнителей %s.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица.
		$this->db->query( $this->db->prepare( "DELETE FROM %i WHERE engine NOT IN ( {$placeholders} )", array_merge( array( $this->table() ), $engines ) ) );
		// phpcs:enable
	}

	/**
	 * Удаляет все строки.
	 */
	public function clear(): void {
		if ( self::installed() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица.
			$this->db->query( $this->db->prepare( 'DELETE FROM %i', $this->table() ) );
		}

		update_option( self::SUMMARY, array(), true );
	}

	/**
	 * Запросы страницы.
	 *
	 * @param string    $type   Тип объекта: post, term, author.
	 * @param list<int> $ids    ID объектов.
	 * @param string    $period Период.
	 * @param int       $limit  Сколько строк.
	 * @return list<array<string, mixed>>
	 */
	public function for_objects( string $type, array $ids, string $period = 'current', int $limit = 200 ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids || ! self::installed() ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- только список заполнителей %d.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- панель в админке, собственная таблица.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE object_type = %s AND period = %s AND object_id IN ( {$placeholders} ) ORDER BY impressions DESC LIMIT %d", array_merge( array( $this->table(), $type, $period ), $ids, array( $limit ) ) ), ARRAY_A );
		// phpcs:enable

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	/**
	 * Строки за последние 28 дней и предыдущие 28 для отчёта.
	 *
	 * @param int $limit Сколько строк (самые показываемые).
	 * @return list<array<string, mixed>>
	 */
	public function report_rows( int $limit = 20000 ): array {
		if ( ! self::installed() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE period IN ( 'current', 'previous' ) ORDER BY impressions DESC LIMIT %d", $this->table(), $limit ), ARRAY_A );

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	/**
	 * Самые показываемые запросы за последние 28 дней (все поисковики).
	 *
	 * @param int $limit Сколько.
	 * @return list<array{query: string, impressions: int}>
	 */
	public function top_queries( int $limit = 2000 ): array {
		if ( ! self::installed() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT query, SUM( impressions ) AS impressions FROM %i WHERE period = 'current' AND query <> '' GROUP BY query ORDER BY impressions DESC LIMIT %d", $this->table(), $limit ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'query'       => (string) $row['query'],
				'impressions' => (int) $row['impressions'],
			),
			(array) $rows
		);
	}

	/**
	 * Клики и показы записей за 90 дней (Google, по страницам).
	 *
	 * @return array<int, array{clicks: int, impressions: int}>|null null — данных за 90 дней ещё нет.
	 */
	public function posts_90_days(): ?array {
		if ( ! self::installed() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = (array) $this->db->get_results( $this->db->prepare( "SELECT object_type, object_id, SUM( clicks ) AS clicks, SUM( impressions ) AS impressions FROM %i WHERE engine = 'google' AND period = 'p90' GROUP BY object_type, object_id", $this->table() ), ARRAY_A );

		if ( array() === $rows ) {
			return null;
		}

		$posts = array();

		foreach ( $rows as $row ) {
			if ( 'post' === $row['object_type'] ) {
				$posts[ (int) $row['object_id'] ] = array(
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
				);
			}
		}

		return $posts;
	}

	/**
	 * Число строк.
	 */
	public function count(): int {
		if ( ! self::installed() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт по собственной таблице.
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) );
	}

	/**
	 * Цифры для «Обзора» без запроса к базе.
	 *
	 * @return array<string, int>
	 */
	public static function summary(): array {
		if ( ! self::installed() ) {
			return array();
		}

		$summary = get_option( self::SUMMARY, array() );

		return is_array( $summary ) ? array_map( 'intval', $summary ) : array();
	}

	/**
	 * Строка таблицы с типами.
	 *
	 * @param array<string, mixed> $row Строка.
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		return array(
			'engine'      => (string) $row['engine'],
			'period'      => (string) $row['period'],
			'page'        => (string) $row['page'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'query'       => (string) $row['query'],
			'clicks'      => (int) $row['clicks'],
			'impressions' => (int) $row['impressions'],
			'position'    => round( (float) $row['position'], 1 ),
		);
	}
}
