<?php
/**
 * Импорт из All in One SEO.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

use IndexPilot\Meta\MetaKeys;

/**
 * Читает таблицу aioseo_posts. Строк в ней намного больше, чем записей с данными,
 * поэтому пустые строки отсекаются ещё в запросе.
 */
final class AioseoImporter implements Source {

	/**
	 * В JSON поля keyphrases пустая ключевая фраза выглядит так.
	 */
	private const EMPTY_KEYPHRASE = '%"focus":{"keyphrase":""%';

	/**
	 * Конструктор.
	 *
	 * @param \wpdb|null $db База WordPress; без неё доступен только разбор строк.
	 */
	public function __construct( private ?\wpdb $db = null ) {
	}

	/**
	 * Идентификатор источника.
	 */
	public function id(): string {
		return 'aioseo';
	}

	/**
	 * Название источника.
	 */
	public function label(): string {
		return 'All in One SEO';
	}

	/**
	 * Основной источник.
	 */
	public function is_fallback(): bool {
		return false;
	}

	/**
	 * Есть ли таблица плагина.
	 */
	public function available(): bool {
		if ( null === $this->db ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чужая таблица, разовая проверка на странице импорта.
		return $this->table() === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->table() ) ) );
	}

	/**
	 * Число строк с данными.
	 */
	public function total(): int {
		if ( ! $this->available() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чужая таблица, разовый подсчёт на странице импорта.
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE title <> '' OR description <> '' OR canonical_url <> '' OR og_title <> '' OR og_description <> ''
					OR og_image_custom_url <> '' OR robots_default = 0
					OR ( keywords IS NOT NULL AND keywords NOT IN ( '', '[]' ) )
					OR ( keyphrases <> '' AND keyphrases NOT LIKE %s )",
				$this->table(),
				self::EMPTY_KEYPHRASE
			)
		);
	}

	/**
	 * Пачка строк с данными.
	 *
	 * @param int $after_id Брать записи с ID больше этого.
	 * @param int $limit    Размер пачки.
	 * @return array<int, array<string, mixed>>
	 */
	public function batch( int $after_id, int $limit ): array {
		if ( ! $this->available() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чужая таблица, чтение пачками при импорте.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM %i
				WHERE post_id > %d AND (
					title <> '' OR description <> '' OR canonical_url <> '' OR og_title <> '' OR og_description <> ''
					OR og_image_custom_url <> '' OR robots_default = 0
					OR ( keywords IS NOT NULL AND keywords NOT IN ( '', '[]' ) )
					OR ( keyphrases <> '' AND keyphrases NOT LIKE %s )
				)
				ORDER BY post_id ASC
				LIMIT %d",
				$this->table(),
				$after_id,
				self::EMPTY_KEYPHRASE,
				$limit
			),
			ARRAY_A
		);

		$batch = array();
		foreach ( (array) $rows as $row ) {
			$batch[ (int) $row['post_id'] ] = $row;
		}

		return $batch;
	}

	/**
	 * Строка таблицы → поля IndexPilot.
	 *
	 * @param array<string, mixed> $row Строка aioseo_posts.
	 * @return array<string, mixed>
	 */
	public function map( array $row ): array {
		$keyphrases = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
		$keyphrases = is_array( $keyphrases ) ? $keyphrases : array();
		$focus      = trim( (string) ( $keyphrases['focus']['keyphrase'] ?? '' ) );

		$keywords = array();
		foreach ( (array) ( $keyphrases['additional'] ?? array() ) as $item ) {
			$keywords[] = is_array( $item ) ? ( $item['keyphrase'] ?? '' ) : '';
		}

		$tags = json_decode( (string) ( $row['keywords'] ?? '' ), true );
		foreach ( is_array( $tags ) ? $tags : array() as $tag ) {
			$keywords[] = is_array( $tag ) ? ( $tag['value'] ?? '' ) : $tag;
		}

		$custom_robots = isset( $row['robots_default'] ) && 0 === (int) $row['robots_default'];
		$custom_image  = 'custom' === ( $row['og_image_type'] ?? '' );

		$fields = array(
			MetaKeys::TITLE          => VariableSyntaxConverter::from_aioseo( (string) ( $row['title'] ?? '' ) ),
			MetaKeys::DESCRIPTION    => VariableSyntaxConverter::from_aioseo( (string) ( $row['description'] ?? '' ) ),
			MetaKeys::FOCUS_KEYWORD  => $focus,
			MetaKeys::KEYWORDS       => Importer::keywords( $keywords, $focus ),
			MetaKeys::CANONICAL      => trim( (string) ( $row['canonical_url'] ?? '' ) ),
			MetaKeys::OG_TITLE       => VariableSyntaxConverter::from_aioseo( (string) ( $row['og_title'] ?? '' ) ),
			MetaKeys::OG_DESCRIPTION => VariableSyntaxConverter::from_aioseo( (string) ( $row['og_description'] ?? '' ) ),
			Importer::OG_IMAGE_URL   => $custom_image ? trim( (string) ( $row['og_image_custom_url'] ?? '' ) ) : '',
			MetaKeys::NOINDEX        => $custom_robots && ! empty( $row['robots_noindex'] ) ? '1' : '',
			MetaKeys::NOFOLLOW       => $custom_robots && ! empty( $row['robots_nofollow'] ) ? '1' : '',
			MetaKeys::CORNERSTONE    => ! empty( $row['pillar_content'] ) ? '1' : '',
		);

		return array_filter( $fields, static fn( $value ): bool => '' !== $value && array() !== $value );
	}

	/**
	 * Имя таблицы плагина.
	 */
	private function table(): string {
		return $this->db->prefix . 'aioseo_posts';
	}
}
