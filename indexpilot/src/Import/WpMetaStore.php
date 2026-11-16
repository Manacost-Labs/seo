<?php
/**
 * Мета записей WordPress для импорта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Пишет через update_post_meta, поэтому значения проходят очистку, заданную при регистрации меты.
 */
final class WpMetaStore implements MetaStore {

	/**
	 * Запись существует и не является ревизией или черновиком-заготовкой.
	 *
	 * @param int $post_id ID записи.
	 */
	public function has( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& 'revision' !== $post->post_type
			&& ! in_array( $post->post_status, array( 'auto-draft', 'trash' ), true );
	}

	/**
	 * Текущее значение меты.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ меты.
	 */
	public function get( int $post_id, string $key ): mixed {
		return get_post_meta( $post_id, $key, true );
	}

	/**
	 * Записывает значение меты.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ меты.
	 * @param mixed  $value   Значение.
	 */
	public function set( int $post_id, string $key, mixed $value ): void {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}
}
