<?php
/**
 * Страницы авторов в карте сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Authors\AuthorProfile;
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

		// Авторы с опубликованными записями, время их последней записи и число записей — одним групповым запросом.
		// Число записей нужно резолверу (страница автора с малым числом записей закрыта); без подсказки он считал бы
		// записи каждого автора отдельным запросом.
		$stats    = $this->stats();
		$modified = array_map( static fn( array $row ): string => $row['modified'], $stats );
		$ids      = array_slice( array_keys( $stats ), 0, 2000 );

		AuthorProfile::prime_counts( array_map( static fn( array $row ): int => $row['count'], $stats ) );
		cache_users( $ids );

		$this->entries = array();

		foreach ( $ids as $user_id ) {
			if ( false === get_userdata( $user_id ) ) {
				continue;
			}

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
	 * Авторы с опубликованными записями: время последней изменённой записи и число записей. Один групповой запрос на файл.
	 * Типы записей — те же, по которым AuthorProfile::post_count() считает записи автора.
	 *
	 * @return array<int, array{modified: string, count: int}> ID автора => сведения, по возрастанию ID.
	 */
	private function stats(): array {
		$db    = $this->db ?? $GLOBALS['wpdb'];
		$types = array();

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				$types[] = (string) $type;
			}
		}

		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %s по числу типов записей.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один групповой запрос на файл карты; готовый XML кэшируется целиком.
		$rows = $db->get_results(
			$db->prepare(
				"SELECT post_author, MAX( post_modified_gmt ) AS modified, COUNT(*) AS posts FROM {$db->posts} WHERE post_status = 'publish' AND post_type IN ( {$placeholders} ) GROUP BY post_author ORDER BY post_author ASC",
				$types
			),
			ARRAY_A
		);
		// phpcs:enable

		$stats = array();

		foreach ( (array) $rows as $row ) {
			if ( (int) $row['post_author'] > 0 ) {
				$stats[ (int) $row['post_author'] ] = array(
					'modified' => (string) $row['modified'],
					'count'    => (int) $row['posts'],
				);
			}
		}

		return $stats;
	}
}
