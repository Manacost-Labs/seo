<?php
/**
 * REST-маршруты массового редактора.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Analysis\ScoreUpdater;
use IndexPilot\Bulk\BatchSaver;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;
use IndexPilot\Text\TextWidth;

/**
 * Список записей или терминов с их заголовками и описаниями и сохранение правок пачкой.
 */
final class BulkController {

	private const PER_PAGE = 50;

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер: показывает, что сейчас выводится на сайте.
	 * @param ScoreUpdater $updater  Пересчёт оценки после правки.
	 * @param Settings     $settings Настройки: пределы длины.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private ScoreUpdater $updater,
		private Settings $settings
	) {
	}

	/**
	 * Регистрирует маршруты.
	 */
	public function register(): void {
		$common = array(
			'kind' => array(
				'type'    => 'string',
				'enum'    => array( 'post', 'term' ),
				'default' => 'post',
			),
			'type' => array(
				'type'              => 'string',
				'default'           => 'post',
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			'indexpilot/v1',
			'/bulk',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rows' ),
					'permission_callback' => array( $this, 'can_list' ),
					'args'                => $common + array(
						'filter' => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'no_description', 'long', 'duplicate' ),
							'default' => 'all',
						),
						'page'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'search' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can_list' ),
					'args'                => $common + array(
						'items' => array(
							'type'     => 'array',
							'required' => true,
							'maxItems' => BatchSaver::MAX_ITEMS,
							'items'    => array( 'type' => 'object' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Может ли пользователь работать со списком объектов этого вида.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 */
	public function can_list( \WP_REST_Request $request ): bool {
		if ( 'term' === $request['kind'] ) {
			$taxonomy = get_taxonomy( (string) $request['type'] );

			return $taxonomy && is_taxonomy_viewable( $taxonomy ) && current_user_can( $taxonomy->cap->edit_terms );
		}

		$post_type = get_post_type_object( (string) $request['type'] );

		return $post_type && is_post_type_viewable( $post_type ) && current_user_can( $post_type->cap->edit_posts );
	}

	/**
	 * Страница списка.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response
	 */
	public function rows( \WP_REST_Request $request ) {
		$type   = (string) $request['type'];
		$page   = (int) $request['page'];
		$filter = (string) $request['filter'];
		$search = (string) $request['search'];

		$found = 'term' === $request['kind']
			? $this->term_ids( $type, $filter, $search, $page )
			: $this->post_ids( $type, $filter, $search, $page );

		$rows = array();
		foreach ( $found['ids'] as $id ) {
			$rows[] = $this->row( (string) $request['kind'], $type, $id );
		}

		return rest_ensure_response(
			array(
				'rows'  => $rows,
				'total' => $found['total'],
				'pages' => (int) ceil( $found['total'] / self::PER_PAGE ),
				'limit' => array(
					'title_px'        => (int) $this->settings->general( 'title_max_px' ),
					'description_min' => (int) $this->settings->general( 'description_min' ),
					'description_max' => (int) $this->settings->general( 'description_max' ),
				),
			)
		);
	}

	/**
	 * Сохраняет правки. Право проверяется для каждой строки.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response
	 */
	public function save( \WP_REST_Request $request ) {
		$kind = (string) $request['kind'];
		$type = (string) $request['type'];

		$saver = new BatchSaver(
			static function ( int $id ) use ( $kind, $type ): bool {
				if ( 'term' === $kind ) {
					$term = get_term( $id, $type );

					return $term instanceof \WP_Term && current_user_can( 'edit_term', $id );
				}

				return get_post_type( $id ) === $type && current_user_can( 'edit_post', $id );
			},
			function ( int $id, array $fields ) use ( $kind, $type ): void {
				$keys = array(
					'seo_title'       => MetaKeys::TITLE,
					'seo_description' => MetaKeys::DESCRIPTION,
				);

				foreach ( $fields as $field => $value ) {
					// Значение очищает sanitize_callback, заданный при регистрации меты (см. MetaRegistrar).
					if ( 'term' === $kind ) {
						update_term_meta( $id, $keys[ $field ], wp_slash( $value ) );
					} else {
						update_post_meta( $id, $keys[ $field ], wp_slash( $value ) );
					}
				}

				if ( 'term' === $kind ) {
					$this->updater->update_term( $id, 0, $type );
				} else {
					$this->updater->update_post( $id, get_post( $id ) );
				}
			}
		);

		$results = array();
		foreach ( $saver->save( (array) $request['items'] ) as $result ) {
			$results[] = $result['saved'] ? $result + array( 'row' => $this->row( $kind, $type, $result['id'] ) ) : $result;
		}

		return rest_ensure_response( array( 'results' => $results ) );
	}

	/**
	 * Строка списка: поля редактора и то, что сейчас выводится на сайте.
	 *
	 * @param string $kind Вид объекта: post или term.
	 * @param string $type Тип записи или таксономия.
	 * @param int    $id   ID объекта.
	 * @return array<string, mixed>
	 */
	private function row( string $kind, string $type, int $id ): array {
		$is_term = 'term' === $kind;
		$meta    = $this->resolver->resolve( new Context( $is_term ? Context::TERM : Context::POST, $id, $type ) );
		$term    = $is_term ? get_term( $id, $type ) : null;
		$link    = $is_term ? get_term_link( $id, $type ) : get_permalink( $id );
		$read    = $is_term ? 'get_term_meta' : 'get_post_meta';

		return array(
			'id'                   => $id,
			'name'                 => $is_term ? ( $term instanceof \WP_Term ? $term->name : '' ) : wp_strip_all_tags( get_the_title( $id ) ),
			'url'                  => is_string( $link ) ? $link : '',
			'edit_url'             => (string) ( $is_term ? get_edit_term_link( $id, $type ) : get_edit_post_link( $id, 'raw' ) ),
			'seo_title'            => (string) $read( $id, MetaKeys::TITLE, true ),
			'seo_description'      => (string) $read( $id, MetaKeys::DESCRIPTION, true ),
			'resolved_title'       => $meta->title,
			'resolved_description' => $meta->description,
			'description_source'   => $meta->description_source,
			'title_px'             => TextWidth::estimate( $meta->title ),
			'score'                => (string) $read( $id, MetaKeys::SCORE, true ),
			'indexable'            => $meta->is_indexable(),
		);
	}

	/**
	 * ID записей страницы списка.
	 *
	 * @param string $type   Тип записи.
	 * @param string $filter Фильтр.
	 * @param string $search Поиск по названию.
	 * @param int    $page   Номер страницы.
	 * @return array{ids: list<int>, total: int}
	 */
	private function post_ids( string $type, string $filter, string $search, int $page ): array {
		$args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
			's'              => $search,
		);

		if ( 'no_description' === $filter ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- фильтр массового редактора в админке.
			$args['meta_query'] = array(
				array(
					'key'     => MetaKeys::DESCRIPTION,
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'all' !== $filter ) {
			$ids = $this->filtered_post_ids( $type, $filter );

			if ( array() === $ids ) {
				return array(
					'ids'   => array(),
					'total' => 0,
				);
			}

			$args['post__in'] = $ids;
		}

		$query = new \WP_Query( $args );

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * ID записей с длинными или повторяющимися полями.
	 *
	 * @param string $type   Тип записи.
	 * @param string $filter Фильтр: long или duplicate.
	 * @return list<int>
	 */
	private function filtered_post_ids( string $type, string $filter ): array {
		global $wpdb;

		if ( 'long' === $filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- фильтр массового редактора в админке.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE p.post_type = %s AND p.post_status = 'publish'
						AND ( ( m.meta_key = %s AND CHAR_LENGTH( m.meta_value ) > %d ) OR ( m.meta_key = %s AND CHAR_LENGTH( m.meta_value ) > 70 ) )
					LIMIT 2000",
					$type,
					MetaKeys::DESCRIPTION,
					(int) $this->settings->general( 'description_max' ),
					MetaKeys::TITLE
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- фильтр массового редактора в админке.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_key IN ( %s, %s )
						AND m.meta_value IN (
							SELECT d.meta_value FROM {$wpdb->postmeta} d JOIN {$wpdb->posts} dp ON dp.ID = d.post_id
							WHERE dp.post_type = %s AND dp.post_status = 'publish' AND d.meta_key = m.meta_key
							GROUP BY d.meta_value HAVING COUNT(*) > 1
						)
					LIMIT 2000",
					$type,
					MetaKeys::TITLE,
					MetaKeys::DESCRIPTION,
					$type
				)
			);
		}

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * ID терминов страницы списка.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param string $filter   Фильтр: all или no_description.
	 * @param string $search   Поиск по названию.
	 * @param int    $page     Номер страницы.
	 * @return array{ids: list<int>, total: int}
	 */
	private function term_ids( string $taxonomy, string $filter, string $search, int $page ): array {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
			'orderby'    => 'count',
			'order'      => 'DESC',
			'search'     => $search,
		);

		if ( 'no_description' === $filter ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- фильтр массового редактора в админке.
			$args['meta_query'] = array(
				array(
					'key'     => MetaKeys::DESCRIPTION,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$all = get_terms( $args );
		$all = is_array( $all ) ? array_map( 'intval', $all ) : array();

		return array(
			'ids'   => array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total' => count( $all ),
		);
	}
}
