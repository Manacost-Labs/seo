<?php
/**
 * Опубликованная запись важнее черновика с тем же адресом.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Черновик может носить тот же адрес (post_name), что и опубликованная запись: так бывает, когда записи
 * заводятся пакетом. На одиночной странице WordPress берёт самую новую из найденных записей, а это черновик,
 * и посетитель с поисковиком получают 404. Класс ставит первой ту запись, которую можно показывать.
 */
final class PublishedFirst {

	/**
	 * Переставляет записи: первой — первая из тех, что можно показывать.
	 *
	 * @param list<object>              $posts     Найденные записи.
	 * @param callable( object ): bool $is_public Можно ли показывать запись.
	 * @return list<object>
	 */
	public static function order( array $posts, callable $is_public ): array {
		if ( count( $posts ) < 2 || $is_public( $posts[0] ) ) {
			return $posts;
		}

		foreach ( $posts as $index => $post ) {
			if ( $is_public( $post ) ) {
				unset( $posts[ $index ] );
				array_unshift( $posts, $post );

				return array_values( $posts );
			}
		}

		return $posts;
	}

	/**
	 * Подключает перестановку к основному запросу одиночной страницы на сайте.
	 */
	public function register(): void {
		add_filter( 'posts_results', array( $this, 'filter' ), 10, 2 );
	}

	/**
	 * Фильтр posts_results.
	 *
	 * @param mixed     $posts Найденные записи.
	 * @param \WP_Query $query Запрос.
	 * @return mixed
	 */
	public function filter( $posts, $query ) {
		if ( ! is_array( $posts ) || count( $posts ) < 2 || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_singular() || is_admin() ) {
			return $posts;
		}

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				return $posts;
			}
		}

		return self::order( array_values( $posts ), static fn( object $post ): bool => is_post_status_viewable( (string) $post->post_status ) );
	}
}
