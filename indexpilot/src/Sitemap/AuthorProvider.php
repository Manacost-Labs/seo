<?php
/**
 * Страницы авторов в карте сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;

/**
 * Авторы, у которых есть опубликованные записи и чья страница открыта для индексации.
 * Дата изменения страницы автора — дата его последней изменённой опубликованной записи.
 */
final class AuthorProvider {

	/**
	 * Страницы авторов, собранные в этом запросе.
	 *
	 * @var list<array{loc: string, lastmod: string}>|null
	 */
	private ?array $entries = null;

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер: решает, индексируется ли страница автора.
	 * @param \wpdb|null   $db       База WordPress; без неё берётся общая.
	 */
	public function __construct( private MetaResolver $resolver, private ?\wpdb $db = null ) {
	}

	/**
	 * Страницы авторов. Авторов на сайте немного, поэтому карта авторов всегда одна.
	 *
	 * @return list<array{loc: string, lastmod: string}>
	 */
	public function entries(): array {
		if ( null !== $this->entries ) {
			return $this->entries;
		}

		$ids = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => 'ID',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'number'              => 2000,
			)
		);

		$this->entries = array();
		$modified      = array() === $ids ? array() : $this->modified();

		foreach ( array_map( 'intval', (array) $ids ) as $user_id ) {
			$url = get_author_posts_url( $user_id );

			if ( EntryPolicy::allows( $url, $this->resolver->indexing( new Context( Context::AUTHOR, $user_id ) ), false ) ) {
				$this->entries[] = array(
					'loc'     => $url,
					'lastmod' => Lastmod::w3c( $modified[ $user_id ] ?? '' ),
				);
			}
		}

		return $this->entries;
	}

	/**
	 * Время последнего изменения карты авторов: самая свежая запись среди всех авторов.
	 */
	public function lastmod(): string {
		return Lastmod::latest( array_column( $this->entries(), 'lastmod' ) );
	}

	/**
	 * Время последней изменённой опубликованной записи каждого автора: один групповой запрос на файл.
	 *
	 * @return array<int, string> ID автора => время MySQL по Гринвичу.
	 */
	private function modified(): array {
		$db    = $this->db ?? $GLOBALS['wpdb'];
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %s по числу типов записей.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один групповой запрос на файл карты; готовый XML кэшируется целиком.
		$rows = $db->get_results(
			$db->prepare(
				"SELECT post_author, MAX( post_modified_gmt ) AS modified FROM {$db->posts} WHERE post_status = 'publish' AND post_type IN ( {$placeholders} ) GROUP BY post_author",
				$types
			),
			ARRAY_A
		);
		// phpcs:enable

		$modified = array();

		foreach ( (array) $rows as $row ) {
			$modified[ (int) $row['post_author'] ] = (string) $row['modified'];
		}

		return $modified;
	}
}
