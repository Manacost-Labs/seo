<?php
/**
 * Чтение данных записи из WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Записи, поля и термины WordPress.
 */
final class WpPostReader implements PostReader {

	/**
	 * Название записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public function title( int $post_id ): string {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? wp_strip_all_tags( $post->post_title ) : '';
	}

	/**
	 * Дата публикации.
	 *
	 * @param int $post_id ID записи.
	 */
	public function date( int $post_id ): string {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? (string) $post->post_date : '';
	}

	/**
	 * Значение произвольного поля.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ поля.
	 */
	public function meta( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Названия терминов записи в таксономии.
	 *
	 * @param int    $post_id  ID записи.
	 * @param string $taxonomy Таксономия.
	 * @return list<string>
	 */
	public function terms( int $post_id, string $taxonomy ): array {
		$terms = taxonomy_exists( $taxonomy ) ? get_the_terms( $post_id, $taxonomy ) : false;

		return is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'name' ) ) : array();
	}
}
