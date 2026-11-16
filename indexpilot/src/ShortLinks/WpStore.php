<?php
/**
 * Короткие ссылки в базе WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\ShortLinks;

/**
 * Хранит свои короткие ссылки в таблице indexpilot_shortlinks.
 */
final class WpStore implements Store {

	public const DB_OPTION  = 'indexpilot_shortlinks_db_version';
	public const DB_VERSION = '2';

	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Имя таблицы.
	 */
	public function table(): string {
		return $this->db->prefix . 'indexpilot_shortlinks';
	}

	/**
	 * Создаёт таблицу при первом запуске.
	 */
	public function maybe_install(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$this->table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(64) NOT NULL,
				target text NOT NULL,
				campaign varchar(60) NOT NULL DEFAULT '',
				expires_at date DEFAULT NULL,
				code smallint(5) unsigned NOT NULL DEFAULT 302,
				clicks bigint(20) unsigned NOT NULL DEFAULT 0,
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_click datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$this->db->get_charset_collate()};"
		);

		( new WpClickLog( $this->db ) )->install();
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Ссылка по коду.
	 *
	 * @param string $slug Код.
	 * @return array{id: int, slug: string, target: string, code: int, clicks: int}|null
	 */
	public function find( string $slug ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, поиск по уникальному ключу.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT id, slug, target, code, campaign, expires_at, clicks FROM %i WHERE slug = %s', $this->table(), $slug ), ARRAY_A );

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	/**
	 * Создаёт ссылку.
	 *
	 * @param string $slug   Код.
	 * @param string $target Адрес, на который ведёт ссылка.
	 * @param int    $code   Код перенаправления.
	 * @param string $campaign Кампания.
	 * @param ?string $expires_at Срок действия.
	 */
	public function insert( string $slug, string $target, int $code, string $campaign = '', ?string $expires_at = null ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- собственная таблица плагина.
		$this->db->insert(
			$this->table(),
			array(
				'slug'       => $slug,
				'target'     => $target,
				'code'       => $code,
				'campaign'   => $campaign,
				'expires_at' => $expires_at,
				'created'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return (int) $this->db->insert_id;
	}

	/**
	 * Засчитывает переход.
	 *
	 * @param string $slug Код.
	 */
	public function hit( string $slug ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- счётчик в собственной таблице.
		$this->db->query( $this->db->prepare( 'UPDATE %i SET clicks = clicks + 1, last_click = %s WHERE slug = %s', $this->table(), current_time( 'mysql', true ), $slug ) );
	}

	/**
	 * Ссылки, новые первыми.
	 *
	 * @param int $limit  Сколько вернуть.
	 * @param int $offset Сколько пропустить.
	 * @param ?string $campaign Фильтр кампании.
	 * @return list<array<string, mixed>>
	 */
	public function all( int $limit, int $offset, ?string $campaign = null ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- список в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT id, slug, target, code, campaign, expires_at, clicks, created, last_click FROM %i WHERE (%s = %s OR campaign = %s) ORDER BY id DESC LIMIT %d OFFSET %d', $this->table(), null === $campaign ? 'all' : 'filter', 'all', $campaign ?? '', $limit, $offset ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => self::typed( $row ) + array(
				'created'    => (string) $row['created'],
				'last_click' => (string) ( $row['last_click'] ?? '' ),
			),
			(array) $rows
		);
	}

	/**
	 * Число ссылок.
	 */
	public function count(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт в собственной таблице.
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', $this->table(), 0 ) );
	}

	/**
	 * Удаляет ссылку.
	 *
	 * @param int $id ID ссылки.
	 */
	public function delete( int $id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Приводит типы строки из базы.
	 *
	 * @param array<string, mixed> $row Строка таблицы.
	 * @return array{id: int, slug: string, target: string, code: int, clicks: int}
	 */
	private static function typed( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'slug'       => (string) $row['slug'],
			'target'     => (string) $row['target'],
			'code'       => (int) $row['code'],
			'clicks'     => (int) $row['clicks'],
			'campaign'   => (string) $row['campaign'],
			'expires_at' => $row['expires_at'],
		);
	}

	/**
	 * Изменяет адрес, сохраняя код.
	 *
	 * @param int $id ID ссылки.
	 * @param string $target Новый адрес.
	 */
	public function update_target( int $id, string $target ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- собственная таблица.
		return false !== $this->db->update( $this->table(), array( 'target' => $target ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Список непустых кампаний.
	 *
	 * @return list<string>
	 */
	public function campaigns(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- список в админке.
		return $this->db->get_col( $this->db->prepare( 'SELECT DISTINCT campaign FROM %i WHERE campaign <> %s ORDER BY campaign', $this->table(), '' ) );
	}

	/**
	 * Находит ссылку по ID.
	 *
	 * @param int $id ID ссылки.
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- поиск по первичному ключу.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::typed( $row ) : null;
	}
}
