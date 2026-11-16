<?php
/**
 * Обход записей для графа ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Первый раз граф строится обходом всех опубликованных записей пачками (с возобновлением после обрыва).
 * Дальше он обновляется сам: при сохранении записи её ссылки пересчитываются.
 */
final class Scanner {

	public const OPTION = 'indexpilot_links_scan';

	private const BATCH = 25;

	/**
	 * Конструктор.
	 *
	 * @param \wpdb   $db      База WordPress.
	 * @param Indexer $indexer Запись ссылок в граф.
	 */
	public function __construct( private \wpdb $db, private Indexer $indexer ) {
	}

	/**
	 * Подключает пересчёт ссылок при сохранении и удалении записи.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'index_post' ), 15 );
		add_action( 'deleted_post', array( $this->indexer, 'remove' ) );
	}

	/**
	 * Типы записей, тексты которых разбираются.
	 *
	 * @return list<string>
	 */
	public function types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				$types[] = (string) $type;
			}
		}

		return $types;
	}

	/**
	 * Пересчитывает ссылки одной записи. У неопубликованной записи ссылки из графа убираются.
	 *
	 * @param int $post_id ID записи.
	 */
	public function index_post( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || ! in_array( $post->post_type, $this->types(), true ) ) {
			return;
		}

		if ( 'publish' === $post->post_status ) {
			$this->indexer->index( (int) $post->ID, $post->post_content );
		} else {
			$this->indexer->remove( (int) $post->ID );
		}
	}

	/**
	 * Состояние обхода.
	 *
	 * @return array{last_id: int, processed: int, done: bool, updated: int}
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'last_id'   => (int) ( $saved['last_id'] ?? 0 ),
			'processed' => (int) ( $saved['processed'] ?? 0 ),
			'done'      => ! empty( $saved['done'] ),
			'updated'   => (int) ( $saved['updated'] ?? 0 ),
		);
	}

	/**
	 * Число опубликованных записей, которые нужно обойти.
	 */
	public function total(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт для страницы отчёта.
		return (int) $this->db->get_var( $this->prepare_types( "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_status = 'publish' AND post_type IN ( %TYPES% )" ) );
	}

	/**
	 * Обходит очередную пачку записей.
	 *
	 * @param bool $restart Начать обход сначала.
	 * @return array{processed: int, total: int, done: bool}
	 */
	public function scan_batch( bool $restart = false ): array {
		$state = $restart ? array(
			'last_id'   => 0,
			'processed' => 0,
		) : $this->state();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- выборка пачки по диапазону ID.
		$ids = $this->db->get_col(
			$this->prepare_types(
				"SELECT ID FROM {$this->db->posts} WHERE post_status = 'publish' AND post_type IN ( %TYPES% ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				array( (int) $state['last_id'], self::BATCH )
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		foreach ( $ids as $id ) {
			$this->index_post( $id );
			$state['last_id'] = $id;
			++$state['processed'];
		}

		$state['done']    = count( $ids ) < self::BATCH;
		$state['updated'] = time();

		update_option( self::OPTION, $state, false );

		return array(
			'processed' => (int) $state['processed'],
			'total'     => $this->total(),
			'done'      => $state['done'],
		);
	}

	/**
	 * Подставляет в запрос список типов записей и остальные значения.
	 *
	 * @param string           $sql  Запрос с меткой %TYPES% на месте списка типов.
	 * @param list<int|string> $args Остальные значения по порядку.
	 */
	private function prepare_types( string $sql, array $args = array() ): string {
		$types = $this->types();
		$sql   = str_replace( '%TYPES%', implode( ', ', array_fill( 0, count( $types ), '%s' ) ), $sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- в запрос подставляется только список заполнителей %s по числу типов записей.
		return (string) $this->db->prepare( $sql, array_merge( $types, $args ) );
	}
}
