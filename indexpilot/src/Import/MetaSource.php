<?php
/**
 * Источник импорта, который хранит данные в мете записей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Общая часть для плагинов, которые пишут SEO-поля в postmeta.
 */
abstract class MetaSource implements Source {

	/**
	 * Конструктор.
	 *
	 * @param \wpdb|null $db База WordPress; без неё доступен только разбор строк.
	 */
	public function __construct( protected ?\wpdb $db = null ) {
	}

	/**
	 * Ключи меты источника, по которым ищутся записи с данными.
	 *
	 * @return list<string>
	 */
	abstract protected function keys(): array;

	/**
	 * Есть ли хотя бы одна запись с данными.
	 */
	public function available(): bool {
		return array() !== $this->post_ids( 0, 1 );
	}

	/**
	 * Число записей с данными.
	 */
	public function total(): int {
		if ( null === $this->db ) {
			return 0;
		}

		$keys = $this->keys();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %s по числу ключей.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- разовый подсчёт на странице импорта.
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$this->db->postmeta} WHERE meta_key IN ( {$this->placeholders()} ) AND meta_value <> ''",
				$keys
			)
		);
		// phpcs:enable
	}

	/**
	 * Пачка записей: для каждой — значения всех ключей источника.
	 *
	 * @param int $after_id Брать записи с ID больше этого.
	 * @param int $limit    Размер пачки.
	 * @return array<int, array<string, mixed>>
	 */
	public function batch( int $after_id, int $limit ): array {
		$batch = array();

		foreach ( $this->post_ids( $after_id, $limit ) as $post_id ) {
			$row = array();

			foreach ( $this->keys() as $key ) {
				$row[ $key ] = get_post_meta( $post_id, $key, true );
			}

			$batch[ $post_id ] = $row;
		}

		return $batch;
	}

	/**
	 * ID записей, у которых заполнен хотя бы один ключ источника.
	 *
	 * @param int $after_id Брать записи с ID больше этого.
	 * @param int $limit    Сколько записей вернуть.
	 * @return list<int>
	 */
	private function post_ids( int $after_id, int $limit ): array {
		if ( null === $this->db ) {
			return array();
		}

		$args = array_merge( array( $after_id ), $this->keys(), array( $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу ключей.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чтение пачками при импорте.
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT post_id FROM {$this->db->postmeta} WHERE post_id > %d AND meta_key IN ( {$this->placeholders()} ) AND meta_value <> '' ORDER BY post_id ASC LIMIT %d",
				$args
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Заполнители %s по числу ключей.
	 */
	private function placeholders(): string {
		return implode( ', ', array_fill( 0, count( $this->keys() ), '%s' ) );
	}

	/**
	 * Строка robots или список директив → флаги noindex и nofollow.
	 *
	 * @param mixed $robots Строка через запятую или массив директив.
	 * @return array{noindex: bool, nofollow: bool}
	 */
	protected static function robots_flags( mixed $robots ): array {
		$tokens = is_array( $robots ) ? $robots : explode( ',', (string) $robots );
		$tokens = array_map( static fn( $token ): string => strtolower( trim( (string) $token ) ), $tokens );

		return array(
			'noindex'  => in_array( 'noindex', $tokens, true ),
			'nofollow' => in_array( 'nofollow', $tokens, true ),
		);
	}

	/**
	 * Убирает пустые значения из набора полей.
	 *
	 * @param array<string, mixed> $fields Поля.
	 * @return array<string, mixed>
	 */
	protected static function without_empty( array $fields ): array {
		return array_filter( $fields, static fn( $value ): bool => '' !== $value && array() !== $value && 0 !== $value );
	}
}
