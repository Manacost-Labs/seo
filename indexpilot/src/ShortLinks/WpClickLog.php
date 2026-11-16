<?php
/**
 * Журнал переходов без персональных данных.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\ShortLinks;

/** Агрегаты в собственной таблице. */
final class WpClickLog implements ClickLog {
	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База.
	 */
	public function __construct( private \wpdb $db ) {}
	/** Имя таблицы. */
	public function table(): string {
		return $this->db->prefix . 'indexpilot_shortlink_clicks'; }
	/** Устанавливает таблицу вместе с основной схемой. */
	public function install(): void {
		dbDelta(
			"CREATE TABLE {$this->table()} (
			slug varchar(64) NOT NULL,
			day date NOT NULL,
			referrer varchar(100) NOT NULL DEFAULT '',
			hits int NOT NULL DEFAULT 0,
			UNIQUE KEY visit (slug, day, referrer)
		) {$this->db->get_charset_collate()};"
		);
	}

	/**
	 * Записывает переход.
	 *
	 * @param string $slug Код.
	 * @param string $day День UTC.
	 * @param string $referrer Хост источника.
	 */
	public function add( string $slug, string $day, string $referrer ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- атомарный счётчик.
		$updated = $this->db->query( $this->db->prepare( 'UPDATE %i SET hits = hits + 1 WHERE slug = %s AND day = %s AND referrer = %s', $this->table(), $slug, $day, $referrer ) );
		if ( 0 === $updated ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- первая запись агрегата.
			$inserted = $this->db->insert(
				$this->table(),
				array(
					'slug'     => $slug,
					'day'      => $day,
					'referrer' => $referrer,
					'hits'     => 1,
				),
				array( '%s', '%s', '%s', '%d' )
			);
			if ( false === $inserted ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- параллельная вставка могла создать строку.
				$this->db->query( $this->db->prepare( 'UPDATE %i SET hits = hits + 1 WHERE slug = %s AND day = %s AND referrer = %s', $this->table(), $slug, $day, $referrer ) );
			}
		}
	}
	/**
	 * Переходы по дням.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @return array<string,int>
	 */
	public function by_day( string $slug, int $days ): array {
		return $this->aggregate( $slug, $days, 'day' ); }
	/**
	 * Переходы по источникам.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @return array<string,int>
	 */
	public function by_referrer( string $slug, int $days ): array {
		return $this->aggregate( $slug, $days, 'referrer' ); }

	/**
	 * Суммирует ограниченный период UTC.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @param string $column Измерение.
	 * @return array<string,int>
	 */
	private function aggregate( string $slug, int $days, string $column ): array {
		if ( $days < 1 ) {
			return array(); }
		$since = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- отчёт по собственной таблице.
		$rows   = $this->db->get_results( $this->db->prepare( 'SELECT %i AS label, SUM(hits) AS total FROM %i WHERE slug = %s AND day >= %s AND day <= %s GROUP BY %i ORDER BY %i', $column, $this->table(), $slug, $since, gmdate( 'Y-m-d' ), $column, $column ), ARRAY_A );
		$result = array();
		foreach ( $rows as $row ) {
			$result[ $row['label'] ] = (int) $row['total']; }
		return $result;
	}
}
