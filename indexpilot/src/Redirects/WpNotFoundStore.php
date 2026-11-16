<?php
/**
 * Журнал 404 в таблице indexpilot_404.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Одна строка на адрес; повторное обращение увеличивает счётчик. Запросы к базе — только на ответе 404.
 */
final class WpNotFoundStore implements NotFoundStore {

	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Таблица журнала.
	 */
	public function table(): string {
		return Schema::not_found( $this->db );
	}

	public function add( string $url, string $referrer ): int {
		$now  = current_time( 'mysql', true );
		$hash = md5( mb_strtolower( $url ) );

		if ( $this->bump( $hash, $referrer, $now ) ) {
			return 0;
		}

		// Без ON DUPLICATE KEY: так запрос одинаково работает в MySQL и SQLite. Если адрес успели записать
		// параллельно, вставка упрётся в уникальный ключ — тогда просто увеличиваем счётчик.
		$suppress = $this->db->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- собственная таблица плагина, только на ответе 404.
		$inserted = $this->db->insert(
			$this->table(),
			array(
				'url'        => $url,
				'url_hash'   => $hash,
				'hits'       => 1,
				'referrer'   => $referrer,
				'first_seen' => $now,
				'last_seen'  => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$this->db->suppress_errors( $suppress );

		if ( false === $inserted ) {
			$this->bump( $hash, $referrer, $now );

			return 0;
		}

		return (int) $this->db->insert_id;
	}

	public function trim( int $keep ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$cutoff = $this->db->get_var( $this->db->prepare( 'SELECT last_seen FROM %i ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d', $this->table(), max( 0, $keep - 1 ) ) );

		if ( null === $cutoff ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE last_seen < %s', $this->table(), (string) $cutoff ) );
	}

	/**
	 * Самые частые адреса.
	 *
	 * @param string $search Строка поиска; пусто — все.
	 * @param int    $limit  Сколько строк.
	 * @param int    $offset Сколько пропустить.
	 * @return list<array{id: int, url: string, hits: int, referrer: string, last_seen: string}>
	 */
	public function top( string $search, int $limit, int $offset = 0 ): array {
		if ( ! Schema::installed() ) {
			return array();
		}

		$like = '%' . $this->db->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- список в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT id, url, hits, referrer, last_seen FROM %i WHERE ( %s = %s OR url LIKE %s ) ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d', $this->table(), $search, '', $like, $limit, $offset ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'id'        => (int) $row['id'],
				'url'       => (string) $row['url'],
				'hits'      => (int) $row['hits'],
				'referrer'  => (string) $row['referrer'],
				'last_seen' => (string) $row['last_seen'],
			),
			(array) $rows
		);
	}

	/**
	 * Число строк журнала; с порогом — только адреса, к которым обращались не меньше $min_hits раз.
	 *
	 * @param int $min_hits Порог обращений.
	 */
	public function count( int $min_hits = 1 ): int {
		if ( ! Schema::installed() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт в собственной таблице.
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE hits >= %d', $this->table(), $min_hits ) );
	}

	/**
	 * Удаляет строку журнала (адрес исправлен или это не ошибка).
	 *
	 * @param int $id Номер строки.
	 */
	public function delete( int $id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Удаляет строку журнала по адресу.
	 *
	 * @param string $url Адрес.
	 */
	public function delete_url( string $url ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->delete( $this->table(), array( 'url_hash' => md5( mb_strtolower( $url ) ) ), array( '%s' ) );
	}

	/**
	 * Очищает журнал.
	 */
	public function clear(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE id > %d', $this->table(), 0 ) );
	}

	/**
	 * Увеличивает счётчик адреса.
	 *
	 * @param string $hash     Хеш адреса.
	 * @param string $referrer Откуда пришли.
	 * @param string $now      Время обращения.
	 * @return bool Был ли адрес в журнале.
	 */
	private function bump( string $hash, string $referrer, string $now ): bool {
		$sql = '' === $referrer
			? $this->db->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s WHERE url_hash = %s', $this->table(), $now, $hash )
			: $this->db->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s, referrer = %s WHERE url_hash = %s', $this->table(), $now, $referrer, $hash );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- запрос подготовлен выше; собственная таблица, только на ответе 404.
		return (int) $this->db->query( $sql ) > 0;
	}
}
