<?php
/**
 * Записи и рубрики сайта для отчётов по данным поиска.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Meta\MetaKeys;

/**
 * Несколько общих выборок для отчётов «Запросы», «Карта тем» и «Без жизни»: по одному запросу на список,
 * без загрузки объектов записей. Работает только на экранах отчётов в админке.
 */
final class WpSite {

	public const KEEP_META = '_indexpilot_keep';

	public function __construct( private \wpdb $db, private KeywordMatcher $matcher ) {
	}

	/**
	 * Опубликованные записи.
	 *
	 * @param list<string> $types  Типы записей.
	 * @param string       $before Только опубликованные раньше этой даты (GMT, Y-m-d H:i:s); пусто — все.
	 * @param int          $limit  Сколько.
	 * @return list<array{id: int, title: string, type: string, date: string}>
	 */
	public function posts( array $types, string $before = '', int $limit = 5000 ): array {
		$types = array() === $types ? array( 'post' ) : array_values( $types );
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$date  = '' === $before ? '9999-12-31 00:00:00' : $before;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- только список заполнителей %s.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке, один запрос на список.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT ID, post_title, post_type, post_date_gmt FROM {$this->db->posts} WHERE post_status = 'publish' AND post_type IN ( {$in} ) AND post_date_gmt < %s ORDER BY ID DESC LIMIT %d", array_merge( $types, array( $date, $limit ) ) ), ARRAY_A );
		// phpcs:enable

		return array_map(
			static fn( array $row ): array => array(
				'id'    => (int) $row['ID'],
				'title' => (string) $row['post_title'],
				'type'  => (string) $row['post_type'],
				'date'  => (string) $row['post_date_gmt'],
			),
			(array) $rows
		);
	}

	/**
	 * Названия терминов записей.
	 *
	 * @param list<string> $taxonomies Таксономии.
	 * @return array<int, array<string, list<string>>> ID записи => таксономия => названия.
	 */
	public function terms( array $taxonomies ): array {
		$taxonomies = array_values( array_filter( $taxonomies, 'taxonomy_exists' ) );

		if ( array() === $taxonomies ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- только список заполнителей %s.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке, один запрос на список.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT tr.object_id, tt.taxonomy, t.name FROM {$this->db->term_relationships} tr INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$this->db->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy IN ( {$in} )", $taxonomies ), ARRAY_A );
		// phpcs:enable

		$terms = array();

		foreach ( (array) $rows as $row ) {
			$terms[ (int) $row['object_id'] ][ (string) $row['taxonomy'] ][] = (string) $row['name'];
		}

		return $terms;
	}

	/**
	 * Значения поля записей.
	 *
	 * @param string $key Ключ меты.
	 * @return array<int, string>
	 */
	public function meta( string $key ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке, один запрос на список.
		$rows   = $this->db->get_results( $this->db->prepare( "SELECT post_id, meta_value FROM {$this->db->postmeta} WHERE meta_key = %s AND meta_value <> ''", $key ), ARRAY_A );
		$values = array();

		foreach ( (array) $rows as $row ) {
			$values[ (int) $row['post_id'] ] = (string) $row['meta_value'];
		}

		return $values;
	}

	/**
	 * Значимые основы слов фразы с учётом словаря игры.
	 *
	 * @param string $text Фраза.
	 * @return list<string>
	 */
	public function stems( string $text ): array {
		return array_values( array_unique( array_filter( $this->matcher->stems( $text ), static fn( string $stem ): bool => mb_strlen( $stem ) > 2 ) ) );
	}

	/**
	 * Проверка «есть ли на сайте страница под запрос»: все значимые слова запроса есть в заголовке
	 * или основном ключе одной записи.
	 *
	 * @param list<string> $types Типы записей.
	 * @return callable(string): bool
	 */
	public function page_finder( array $types ): callable {
		$sets     = array();
		$keywords = $this->meta( MetaKeys::FOCUS_KEYWORD );

		foreach ( $this->posts( $types ) as $post ) {
			$sets[] = array_flip( $this->stems( $post['title'] . ' ' . ( $keywords[ $post['id'] ] ?? '' ) ) );
		}

		return function ( string $query ) use ( $sets ): bool {
			$need = $this->stems( $query );

			if ( array() === $need ) {
				return true;
			}

			foreach ( $sets as $set ) {
				if ( array() === array_diff_key( array_flip( $need ), $set ) ) {
					return true;
				}
			}

			return false;
		};
	}
}
