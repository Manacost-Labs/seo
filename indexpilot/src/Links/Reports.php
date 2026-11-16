<?php
/**
 * Отчёты по графу ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Redirects\RedirectService;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;

/**
 * Записи без входящих ссылок и внутренние ссылки, которые ведут не туда:
 * на адрес с редиректом, на неопубликованную запись или на страницу, которой нет.
 */
final class Reports {

	/**
	 * Конструктор.
	 *
	 * @param \wpdb           $db         База WordPress.
	 * @param MetaResolver    $resolver   Резолвер: закрытые от индексации записи в отчёт о «сиротах» не входят.
	 * @param RedirectService $redirects  Редиректы сайта и результаты проверки адресов.
	 * @param RedirectedPosts $redirected Записи под редиректом.
	 */
	public function __construct(
		private \wpdb $db,
		private MetaResolver $resolver,
		private RedirectService $redirects,
		private RedirectedPosts $redirected
	) {
	}

	/**
	 * Опубликованные записи типа, на которые не ссылается ни одна другая запись.
	 *
	 * @param string $post_type Тип записи.
	 * @param int    $limit     Сколько записей вернуть.
	 * @return list<array{post_id: int, title: string}>
	 */
	public function orphans( string $post_type, int $limit = 200 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$ids = $this->db->get_col(
			$this->db->prepare(
				"SELECT p.ID FROM {$this->db->posts} p
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''
					AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.target_post_id = p.ID AND l.type = 'internal' AND l.nofollow = 0 )
				ORDER BY p.ID DESC
				LIMIT %d",
				$post_type,
				Schema::table( $this->db ),
				$limit * 2
			)
		);

		$redirected = $this->redirected->map();
		$orphans    = array();

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			// Закрытым и перенаправленным записям входящие ссылки не нужны.
			if ( isset( $redirected[ $id ] ) || ! $this->resolver->indexing( new Context( Context::POST, $id, $post_type ) )['indexable'] ) {
				continue;
			}

			$orphans[] = array(
				'post_id' => $id,
				'title'   => wp_strip_all_tags( get_the_title( $id ) ),
			);

			if ( count( $orphans ) >= $limit ) {
				break;
			}
		}

		return $orphans;
	}

	/**
	 * Записи из списка, которым нужны входящие ссылки: открытые для индексации и без редиректа.
	 *
	 * @param list<int> $ids   ID записей.
	 * @param int       $limit Сколько записей вернуть.
	 * @return list<int>
	 */
	public function indexable( array $ids, int $limit = 200 ): array {
		$redirected = $this->redirected->map();
		$result     = array();

		foreach ( $ids as $id ) {
			$type = (string) get_post_type( $id );

			if ( '' === $type || isset( $redirected[ $id ] ) || ! $this->resolver->indexing( new Context( Context::POST, (int) $id, $type ) )['indexable'] ) {
				continue;
			}

			$result[] = (int) $id;

			if ( count( $result ) >= $limit ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Внутренние ссылки с проблемой.
	 *
	 * @param int $limit Сколько строк вернуть.
	 * @return list<array{post_id: int, title: string, sources: int, url: string, problem: string, detail: string}> problem: redirect, unpublished или not_found.
	 */
	public function broken( int $limit = 200 ): array {
		$problems = array();
		$resolver = $this->redirects->resolver();
		$targets  = $this->redirects->targets();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT l.url, l.target_post_id, MIN(l.post_id) AS post_id, COUNT(DISTINCT l.post_id) AS sources FROM %i l WHERE l.type = 'internal' GROUP BY l.url, l.target_post_id ORDER BY sources DESC",
				Schema::table( $this->db )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$url    = (string) $row['url'];
			$target = (int) $row['target_post_id'];
			$result = $resolver->resolve( $url );
			$status = $targets->status( $url );

			if ( array() !== $result['hops'] ) {
				$problem = 'redirect';
				$detail  = $result['final'];
			} elseif ( $target > 0 && 'publish' !== get_post_status( $target ) ) {
				$problem = 'unpublished';
				$detail  = wp_strip_all_tags( get_the_title( $target ) );
			} elseif ( 0 === $target && null !== $status && ( 0 === $status || $status >= 400 ) ) {
				$problem = 'not_found';
				$detail  = 0 === $status ? '' : (string) $status;
			} else {
				continue;
			}

			$problems[] = array(
				'post_id' => (int) $row['post_id'],
				'title'   => wp_strip_all_tags( get_the_title( (int) $row['post_id'] ) ),
				'sources' => (int) $row['sources'],
				'url'     => $url,
				'problem' => $problem,
				'detail'  => $detail,
			);

			if ( count( $problems ) >= $limit ) {
				break;
			}
		}

		return $problems;
	}

	/**
	 * Внутренние адреса, которые не являются записями и ещё не проверены: рубрики, файлы, удалённые страницы.
	 * Проверка покажет, какие из них отдают ошибку.
	 *
	 * @return list<string>
	 */
	public function addresses_to_check(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$urls = $this->db->get_col( $this->db->prepare( "SELECT DISTINCT url FROM %i WHERE type = 'internal' AND target_post_id = 0", Schema::table( $this->db ) ) );

		$resolver = $this->redirects->resolver();
		$pending  = array();

		foreach ( array_map( 'strval', (array) $urls ) as $url ) {
			if ( array() === $resolver->resolve( $url )['hops'] ) {
				$pending[] = $url;
			}
		}

		return $this->redirects->targets()->pending( $pending );
	}

	/**
	 * Проверяет очередную пачку адресов.
	 *
	 * @param int $limit Размер пачки.
	 * @return array{checked: int, remaining: int}
	 */
	public function check_batch( int $limit = 5 ): array {
		$checked = $this->redirects->targets()->check( $this->addresses_to_check(), $limit );

		return array(
			'checked'   => $checked,
			'remaining' => count( $this->addresses_to_check() ),
		);
	}
}
