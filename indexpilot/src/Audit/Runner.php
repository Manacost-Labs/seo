<?php
/**
 * Аудит записей сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Audit;

use IndexPilot\Game\GameScope;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Game\SiteGames;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;
use IndexPilot\Sitemap\EntryPolicy;

/**
 * Обходит опубликованные записи пачками и запоминает признаки каждой в таблице indexpilot_audit.
 * По таблице строится сводка: дубли заголовков и описаний, пустые описания, лишние H1, короткие и давние тексты,
 * а по отпечаткам текста (столбец fingerprint, с версии таблицы 2) — пары почти одинаковых записей.
 * После первого обхода строка записи обновляется при каждом её сохранении.
 */
final class Runner {

	public const OPTION     = 'indexpilot_audit_state';
	public const DB_OPTION  = 'indexpilot_audit_db_version';
	public const DB_VERSION = '2';

	private const BATCH = 50;

	/**
	 * Проблемы: ключ => условие для таблицы аудита.
	 */
	private const PROBLEMS = array(
		'no_description'   => 'indexable = 1 AND no_description = 1',
		'auto_description' => 'indexable = 1 AND auto_description = 1',
		'h1'               => 'indexable = 1 AND h1 > 0',
		'thin'             => 'indexable = 1 AND thin = 1',
		'stale'            => 'indexable = 1 AND stale = 1',
		'closed'           => 'indexable = 0',
	);

	/**
	 * Конструктор.
	 *
	 * @param \wpdb        $db       База WordPress.
	 * @param MetaResolver $resolver Резолвер: итоговые заголовок и описание страницы.
	 * @param Settings     $settings Настройки: норма объёма текста.
	 */
	public function __construct(
		private \wpdb $db,
		private MetaResolver $resolver,
		private Settings $settings
	) {
	}

	/**
	 * Имя таблицы аудита.
	 */
	public function table(): string {
		return $this->db->prefix . 'indexpilot_audit';
	}

	/**
	 * Создаёт таблицу при первом запуске и подключает обновление строки при сохранении записи.
	 */
	public function register(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			dbDelta(
				"CREATE TABLE {$this->table()} (
					post_id bigint(20) unsigned NOT NULL,
					post_type varchar(20) NOT NULL DEFAULT '',
					title_hash char(32) NOT NULL DEFAULT '',
					description_hash char(32) NOT NULL DEFAULT '',
					indexable tinyint(1) NOT NULL DEFAULT 1,
					no_description tinyint(1) NOT NULL DEFAULT 0,
					auto_description tinyint(1) NOT NULL DEFAULT 0,
					h1 smallint(5) unsigned NOT NULL DEFAULT 0,
					words int(10) unsigned NOT NULL DEFAULT 0,
					thin tinyint(1) NOT NULL DEFAULT 0,
					stale tinyint(1) NOT NULL DEFAULT 0,
					fingerprint varchar(800) NOT NULL DEFAULT '',
					PRIMARY KEY  (post_id),
					KEY title_hash (title_hash),
					KEY description_hash (description_hash)
				) {$this->db->get_charset_collate()};"
			);

			// С автозагрузкой: версия читается на каждой странице и не должна стоить отдельного запроса.
			update_option( self::DB_OPTION, self::DB_VERSION, true );
		}

		// Позже пересчёта оценки: к этому моменту мета записи уже сохранена.
		add_action( 'wp_after_insert_post', array( $this, 'refresh' ), 25 );
		add_action( 'deleted_post', array( $this, 'forget' ) );
	}

	/**
	 * Типы записей, которые проверяет аудит.
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
	 * Обновляет строку записи, если аудит уже запускался. Неопубликованная запись из аудита убирается.
	 *
	 * @param int $post_id ID записи.
	 */
	public function refresh( $post_id ): void {
		if ( 0 === $this->state()['processed'] ) {
			return;
		}

		$this->audit_post( (int) $post_id );
	}

	/**
	 * Убирает запись из аудита.
	 *
	 * @param int $post_id ID записи.
	 */
	public function forget( $post_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->delete( $this->table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
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
	 * Число опубликованных записей, которые нужно проверить.
	 */
	public function total(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт для страницы аудита.
		return (int) $this->db->get_var( $this->with_types( "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_status = 'publish' AND post_type IN ( %TYPES% )" ) );
	}

	/**
	 * Проверяет очередную пачку записей.
	 *
	 * @param bool $restart Начать сначала: таблица очищается.
	 * @return array{processed: int, total: int, done: bool}
	 */
	public function run_batch( bool $restart = false ): array {
		$state = $this->state();

		if ( $restart ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- очистка собственной таблицы перед новым обходом.
			$this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE post_id > %d', $this->table(), 0 ) );

			$state = array(
				'last_id'   => 0,
				'processed' => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- выборка пачки по диапазону ID.
		$ids = $this->db->get_col(
			$this->with_types(
				"SELECT ID FROM {$this->db->posts} WHERE post_status = 'publish' AND post_type IN ( %TYPES% ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				array( (int) $state['last_id'], self::BATCH )
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		_prime_post_caches( $ids, false, true );

		foreach ( $ids as $id ) {
			$this->audit_post( $id );
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
	 * Сводка: сколько записей с каждой проблемой.
	 *
	 * @return array<string, int>
	 */
	public function summary(): array {
		$summary = array();

		foreach ( self::PROBLEMS as $key => $where ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- условие берётся из константы класса.
			$summary[ $key ] = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $this->table() ) );
		}

		foreach ( array( 'title_hash', 'description_hash' ) as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
			$summary[ 'duplicate_' . $column ] = (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COALESCE( SUM( pages ), 0 ) FROM ( SELECT COUNT(*) AS pages FROM %i WHERE indexable = 1 AND %i <> '' GROUP BY %i HAVING COUNT(*) > 1 ) grouped",
					$this->table(),
					$column,
					$column
				)
			);
		}

		return $summary;
	}

	/**
	 * Записи с проблемой.
	 *
	 * @param string $problem Ключ проблемы из сводки.
	 * @param int    $limit   Сколько записей вернуть.
	 * @return list<int>
	 */
	public function posts_with( string $problem, int $limit = 200 ): array {
		if ( ! isset( self::PROBLEMS[ $problem ] ) ) {
			return array();
		}

		$where = self::PROBLEMS[ $problem ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- условие берётся из константы класса.
		$ids = $this->db->get_col( $this->db->prepare( "SELECT post_id FROM %i WHERE {$where} ORDER BY post_id DESC LIMIT %d", $this->table(), $limit ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Группы записей с одинаковым заголовком или описанием.
	 *
	 * @param string $column title_hash или description_hash.
	 * @param int    $limit  Сколько групп вернуть.
	 * @return list<list<int>>
	 */
	public function duplicate_groups( string $column, int $limit = 50 ): array {
		if ( ! in_array( $column, array( 'title_hash', 'description_hash' ), true ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT a.post_id, a.%i AS hash FROM %i a
				JOIN ( SELECT %i AS hash FROM %i WHERE indexable = 1 AND %i <> '' GROUP BY %i HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT %d ) d ON d.hash = a.%i
				WHERE a.indexable = 1
				ORDER BY a.post_id ASC",
				$column,
				$this->table(),
				$column,
				$this->table(),
				$column,
				$column,
				$limit,
				$column
			),
			ARRAY_A
		);

		$hashes = array();
		foreach ( (array) $rows as $row ) {
			$hashes[ (int) $row['post_id'] ] = (string) $row['hash'];
		}

		return Duplicates::groups( $hashes );
	}

	/**
	 * Записывает признаки одной записи.
	 *
	 * @param int $post_id ID записи.
	 */
	private function audit_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || ! in_array( $post->post_type, $this->types(), true ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			$this->forget( $post_id );

			return;
		}

		$meta  = $this->resolver->resolve( new Context( Context::POST, $post_id, $post->post_type ) );
		$facts = PageFacts::build(
			array(
				'title'              => $meta->title,
				'description'        => $meta->description,
				'description_source' => $meta->description_source,
				// Страница с каноническим адресом на другую страницу (старая версия колоды) в поиске не участвует.
				'indexable'          => EntryPolicy::allows(
					(string) get_permalink( $post ),
					array(
						'indexable' => $meta->is_indexable(),
						'canonical' => $meta->canonical,
					),
					false
				),
				'content'            => $post->post_content,
				'modified'           => (int) strtotime( $post->post_modified_gmt . ' UTC' ),
				'min_words'          => (int) $this->settings->post_type( $post->post_type )['min_words'],
			)
		);

		$is_deck = MaterialType::DECK === PostMaterial::detect( $post );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- собственная таблица плагина.
		$this->db->replace(
			$this->table(),
			array(
				'post_id'     => $post_id,
				'post_type'   => $post->post_type,
				// Отпечаток текста для поиска похожих записей; у колод и коротких текстов его нет.
				'fingerprint' => Fingerprints::for_post( $post->post_content, $is_deck ),
			) + $facts
		);
	}

	/**
	 * Одинаковые основные ключи у опубликованных записей разных игр: «гайд на жреца» в Hearthstone и в WoW.
	 * Такие записи не мешают друг другу в проверке занятого ключа, но в выдаче спорят за один запрос —
	 * в заголовках стоит назвать игру.
	 *
	 * @return list<array{keyword: string, posts: list<int>}>
	 */
	public function cross_game_keywords(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке; три столбца по индексу ключа меты.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT m.post_id, m.meta_value AS keyword, g.meta_value AS game FROM {$this->db->postmeta} m
				JOIN {$this->db->posts} p ON p.ID = m.post_id
				LEFT JOIN {$this->db->postmeta} g ON g.post_id = m.post_id AND g.meta_key = %s
				WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_status = 'publish'
				ORDER BY m.post_id ASC LIMIT 5000",
				SiteGames::META,
				MetaKeys::FOCUS_KEYWORD
			),
			ARRAY_A
		);

		return GameScope::cross_game_groups( (array) $rows, SiteGames::DEFAULT_GAME );
	}

	/**
	 * Пары открытых записей с почти одинаковым текстом, от самых похожих.
	 *
	 * @param int $limit Сколько пар вернуть.
	 * @return list<array{a: int, b: int, similarity: float}>
	 */
	public function near_duplicates( int $limit = 100 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT post_id, fingerprint FROM %i WHERE indexable = 1 AND fingerprint <> ''", $this->table() ), ARRAY_A );

		$stored = array();
		foreach ( (array) $rows as $row ) {
			$stored[ (int) $row['post_id'] ] = (string) $row['fingerprint'];
		}

		return Fingerprints::pairs( $stored, $limit );
	}

	/**
	 * Подставляет в запрос список типов записей и остальные значения.
	 *
	 * @param string           $sql  Запрос с меткой %TYPES% на месте списка типов.
	 * @param list<int|string> $args Остальные значения по порядку.
	 */
	private function with_types( string $sql, array $args = array() ): string {
		$types = $this->types();
		$sql   = str_replace( '%TYPES%', implode( ', ', array_fill( 0, count( $types ), '%s' ) ), $sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- в запрос подставляется только список заполнителей %s по числу типов записей.
		return (string) $this->db->prepare( $sql, array_merge( $types, $args ) );
	}
}
