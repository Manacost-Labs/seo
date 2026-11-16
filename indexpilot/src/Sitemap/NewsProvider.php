<?php
/**
 * Записи карты новостей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;

/**
 * Записи, опубликованные за последние 48 часов. Закрытые от индексации, перенаправленные,
 * под паролем и записи с каноникалом на другую страницу в карту новостей не попадают.
 */
final class NewsProvider {

	/**
	 * Конструктор.
	 *
	 * @param \wpdb           $db         База WordPress.
	 * @param MetaResolver    $resolver   Резолвер: решает, индексируется ли страница.
	 * @param RedirectedPosts $redirected Записи под редиректом.
	 */
	public function __construct(
		private \wpdb $db,
		private MetaResolver $resolver,
		private RedirectedPosts $redirected
	) {
	}

	/**
	 * Новости: сначала свежие.
	 *
	 * @param list<string> $types Типы записей, которые считаются новостями.
	 * @param int          $now   Текущее время.
	 * @return list<array{loc: string, published: string, title: string, time: int}>
	 */
	public function entries( array $types, int $now ): array {
		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу типов записей.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- выборка по дате; готовый XML кэшируется целиком.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT ID, post_type, post_date_gmt FROM {$this->db->posts} WHERE post_type IN ( {$placeholders} ) AND post_status = 'publish' AND post_password = '' AND post_date_gmt >= %s ORDER BY post_date_gmt DESC LIMIT %d",
				array_merge( $types, array( gmdate( 'Y-m-d H:i:s', News::since( $now ) ), News::LIMIT ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$recent = array();

		foreach ( (array) $rows as $row ) {
			$recent[] = array(
				'id'        => (int) $row['ID'],
				'type'      => (string) $row['post_type'],
				'published' => (int) strtotime( $row['post_date_gmt'] . ' UTC' ),
			);
		}

		$recent = News::recent( $recent, $now );

		_prime_post_caches( array_column( $recent, 'id' ), false, true );

		$redirected = array() === $recent ? array() : $this->redirected->map();
		$entries    = array();

		foreach ( $recent as $row ) {
			$post = get_post( $row['id'] );
			$url  = $post instanceof \WP_Post ? (string) get_permalink( $post ) : '';

			if ( '' === $url || ! EntryPolicy::allows( $url, $this->resolver->indexing( new Context( Context::POST, $row['id'], $row['type'] ) ), isset( $redirected[ $row['id'] ] ) ) ) {
				continue;
			}

			$entries[] = array(
				'loc'       => $url,
				'published' => gmdate( 'Y-m-d\TH:i:s+00:00', $row['published'] ),
				'title'     => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'time'      => $row['published'],
			);
		}

		return $entries;
	}
}
