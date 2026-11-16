<?php
/**
 * Граф ссылок в базе WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Хранит ссылки в таблице indexpilot_links.
 */
final class WpLinkStore implements LinkStore {

	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Заменяет все ссылки записи новым набором.
	 *
	 * @param int                                                                         $post_id ID записи-источника.
	 * @param list<array{target_post_id: int, url: string, type: string, nofollow: bool}> $rows    Ссылки записи.
	 */
	public function replace( int $post_id, array $rows ): void {
		$this->remove( $post_id );

		// Все ссылки записи — одним запросом на сотню строк, а не запросом на каждую.
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$values = array();
			$args   = array( Schema::table( $this->db ) );

			foreach ( $chunk as $row ) {
				$values[] = '( %d, %d, %s, %d, %s )';
				array_push( $args, $post_id, (int) $row['target_post_id'], (string) $row['type'], $row['nofollow'] ? 1 : 0, (string) $row['url'] );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляются только заполнители по числу строк.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
			$this->db->query( $this->db->prepare( 'INSERT INTO %i ( post_id, target_post_id, type, nofollow, url ) VALUES ' . implode( ', ', $values ), $args ) );
			// phpcs:enable
		}
	}

	/**
	 * Удаляет ссылки записи-источника.
	 *
	 * @param int $post_id ID записи.
	 */
	public function remove( int $post_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->delete( Schema::table( $this->db ), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Число записей, которые ссылаются на каждую из заданных.
	 *
	 * @param list<int> $post_ids ID записей.
	 * @return array<int, int>
	 */
	public function incoming( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$counts   = array_fill_keys( $post_ids, 0 );

		if ( array() === $post_ids ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %d по числу ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT target_post_id, COUNT(DISTINCT post_id) AS sources FROM %i WHERE type = 'internal' AND nofollow = 0 AND target_post_id IN ( {$placeholders} ) GROUP BY target_post_id",
				array_merge( array( Schema::table( $this->db ) ), $post_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['target_post_id'] ] = (int) $row['sources'];
		}

		return $counts;
	}
}
