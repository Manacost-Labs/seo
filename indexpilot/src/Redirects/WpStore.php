<?php
/**
 * Свои правила редиректов в таблице indexpilot_redirects.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Хранит правила в своей таблице и держит индекс поиска в опции с автозагрузкой.
 */
final class WpStore implements Store {

	public const INDEX_OPTION = 'indexpilot_redirect_index';

	private const COLUMNS = 'id, source, regex, target, code, enabled, hits, last_hit, note, created';

	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Таблица правил.
	 */
	public function table(): string {
		return Schema::redirects( $this->db );
	}

	public function all(): array {
		if ( ! Schema::installed() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица; читается при сохранении правил и в отчётах.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i ORDER BY id ASC', $this->table() ), ARRAY_A );

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	public function find( int $id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, поиск по первичному ключу.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	public function find_by_source( string $key ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица, поиск по ключу адреса.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE source_key = %s AND regex = 0 ORDER BY id ASC LIMIT 1', $this->table(), Matcher::hash( $key ) ), ARRAY_A );

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	public function insert( array $rule ): int {
		$rule += array(
			'regex'   => false,
			'target'  => '',
			'code'    => 301,
			'enabled' => true,
			'hits'    => 0,
			'note'    => '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- собственная таблица плагина.
		$this->db->insert(
			$this->table(),
			array(
				'source'     => (string) $rule['source'],
				'source_key' => self::source_key( (string) $rule['source'], (bool) $rule['regex'] ),
				'regex'      => $rule['regex'] ? 1 : 0,
				'target'     => (string) $rule['target'],
				'code'       => (int) $rule['code'],
				'enabled'    => $rule['enabled'] ? 1 : 0,
				'hits'       => (int) $rule['hits'],
				'note'       => (string) $rule['note'],
				'created'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) $this->db->insert_id;
	}

	public function update( int $id, array $fields ): void {
		$data    = array();
		$formats = array();

		foreach ( array( 'source', 'target', 'note' ) as $name ) {
			if ( array_key_exists( $name, $fields ) ) {
				$data[ $name ] = (string) $fields[ $name ];
				$formats[]     = '%s';
			}
		}

		foreach ( array( 'regex', 'enabled', 'code', 'hits' ) as $name ) {
			if ( array_key_exists( $name, $fields ) ) {
				$data[ $name ] = (int) $fields[ $name ];
				$formats[]     = '%d';
			}
		}

		if ( isset( $data['source'] ) ) {
			$regex              = array_key_exists( 'regex', $data ) ? (bool) $data['regex'] : (bool) ( $this->find( $id )['regex'] ?? false );
			$data['source_key'] = self::source_key( $data['source'], $regex );
			$formats[]          = '%s';
		}

		if ( array() === $data ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public function delete( int $id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function rebuild_index(): void {
		update_option( self::INDEX_OPTION, Matcher::index( $this->all() ), true );
	}

	/**
	 * Индекс поиска правил из опции с автозагрузкой.
	 *
	 * @return array{exact: array<string, int>, patterns: array<int, string>}
	 */
	public static function index(): array {
		$index = get_option( self::INDEX_OPTION, array() );

		return is_array( $index ) ? $index + array(
			'exact'    => array(),
			'patterns' => array(),
		) : Matcher::index( array() );
	}

	/**
	 * Засчитывает срабатывание правила.
	 *
	 * @param int $id Номер правила.
	 */
	public function hit( int $id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- счётчик в собственной таблице, только при срабатывании правила.
		$this->db->query( $this->db->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', $this->table(), current_time( 'mysql', true ), $id ) );
	}

	/**
	 * Правила для экрана управления: поиск по адресам и пометке.
	 *
	 * @param string $search Строка поиска; пусто — все.
	 * @param int    $limit  Сколько строк.
	 * @param int    $offset Сколько пропустить.
	 * @return list<array<string, mixed>>
	 */
	public function search( string $search, int $limit, int $offset ): array {
		$like = '%' . $this->db->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- список в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE ( %s = %s OR source LIKE %s OR target LIKE %s OR note LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d', $this->table(), $search, '', $like, $like, $like, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'typed' ), (array) $rows );
	}

	/**
	 * Число правил, подходящих под поиск.
	 *
	 * @param string $search Строка поиска; пусто — все.
	 */
	public function count( string $search = '' ): int {
		if ( ! Schema::installed() ) {
			return 0;
		}

		$like = '%' . $this->db->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт в собственной таблице.
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE ( %s = %s OR source LIKE %s OR target LIKE %s OR note LIKE %s )', $this->table(), $search, '', $like, $like, $like ) );
	}

	/**
	 * Ключ исходного адреса для поиска по индексу таблицы; у шаблонов ключа нет.
	 *
	 * @param string $source Исходный адрес.
	 * @param bool   $regex  Это шаблон.
	 */
	private static function source_key( string $source, bool $regex ): string {
		return $regex ? '' : Matcher::hash( Matcher::key( $source ) );
	}

	/**
	 * Строка таблицы с типами.
	 *
	 * @param array<string, mixed> $row Строка.
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		return array(
			'id'       => (int) $row['id'],
			'source'   => (string) $row['source'],
			'regex'    => (bool) (int) $row['regex'],
			'target'   => (string) $row['target'],
			'code'     => (int) $row['code'],
			'enabled'  => (bool) (int) $row['enabled'],
			'hits'     => (int) $row['hits'],
			'last_hit' => (string) ( $row['last_hit'] ?? '' ),
			'note'     => (string) $row['note'],
			'created'  => (string) $row['created'],
		);
	}
}
