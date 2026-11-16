<?php
/**
 * Данные о странице из WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Реализация DataSource поверх функций WordPress. Несохранённые значения из редактора имеют приоритет.
 */
final class WpDataSource implements DataSource {

	/**
	 * Мета записи или термина.
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $key     Ключ меты.
	 */
	public function meta( Context $context, string $key ): mixed {
		if ( $context->object_id <= 0 ) {
			return '';
		}

		if ( Context::POST === $context->type ) {
			return get_post_meta( $context->object_id, $key, true );
		}

		if ( Context::TERM === $context->type ) {
			return get_term_meta( $context->object_id, $key, true );
		}

		if ( Context::AUTHOR === $context->type ) {
			return get_user_meta( $context->object_id, $key, true );
		}

		return '';
	}

	/**
	 * Исходный текст для автоописания.
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $source  Источник: excerpt, content, term_description, author_bio, tagline или cf:КЛЮЧ.
	 */
	public function text( Context $context, string $source ): string {
		$override = $context->override( $source );
		if ( is_string( $override ) ) {
			return $override;
		}

		if ( str_starts_with( $source, 'cf:' ) ) {
			$value = $this->meta( $context, substr( $source, 3 ) );

			return is_scalar( $value ) ? (string) $value : '';
		}

		switch ( $source ) {
			case 'excerpt':
				return Context::POST === $context->type ? (string) get_post_field( 'post_excerpt', $context->object_id ) : '';

			case 'content':
				return Context::POST === $context->type ? (string) get_post_field( 'post_content', $context->object_id ) : '';

			case 'term_description':
				$term = Context::TERM === $context->type ? get_term( $context->object_id, $context->subtype ) : null;

				return $term instanceof \WP_Term ? (string) $term->description : '';

			case 'author_bio':
				return Context::AUTHOR === $context->type ? (string) get_the_author_meta( 'description', $context->object_id ) : '';

			case 'tagline':
				return (string) get_bloginfo( 'description' );

			default:
				return '';
		}
	}

	/**
	 * Адрес первой страницы объекта.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function url( Context $context ): string {
		switch ( $context->type ) {
			case Context::POST:
				$url = get_permalink( $context->object_id );
				break;

			case Context::TERM:
				$url = get_term_link( $context->object_id, $context->subtype );
				break;

			case Context::AUTHOR:
				$url = get_author_posts_url( $context->object_id );
				break;

			case Context::POST_TYPE_ARCHIVE:
				$url = get_post_type_archive_link( $context->subtype );
				break;

			case Context::HOME:
				$url = home_url( '/' );
				break;

			default:
				$url = '';
		}

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Адрес с учётом номера страницы пагинации.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function paged_url( Context $context ): string {
		$url = $this->url( $context );

		if ( '' === $url || ! PaginationRules::is_paged( $context ) ) {
			return $url;
		}

		global $wp_rewrite;

		$is_post = Context::POST === $context->type;

		// Статичная главная листается как архив: /page/2/, а не /2/ (адрес /2/ — чужая запись или вложение).
		if ( $is_post && self::is_static_front( $context->object_id ) ) {
			$is_post = false;
		}

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
			return add_query_arg( $is_post ? 'page' : 'paged', $context->page, $url );
		}

		// Запись, разбитая тегом nextpage: /slug/2/. Архив: /category/slug/page/2/.
		$suffix = $is_post ? (string) $context->page : $wp_rewrite->pagination_base . '/' . $context->page;

		return user_trailingslashit( trailingslashit( $url ) . $suffix, $is_post ? 'single_paged' : 'paged' );
	}

	/**
	 * Запись — статичная главная страница сайта.
	 *
	 * @param int $post_id ID записи.
	 */
	private static function is_static_front( int $post_id ): bool {
		return $post_id > 0 && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post_id;
	}

	/**
	 * Закрыта ли запись паролем или статусом «личное».
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function is_private( Context $context ): bool {
		if ( Context::POST !== $context->type ) {
			return false;
		}

		$post = get_post( $context->object_id );

		return $post instanceof \WP_Post && ( '' !== $post->post_password || 'private' === $post->post_status );
	}

	/**
	 * Число записей в архиве термина.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function count( Context $context ): int {
		if ( Context::AUTHOR === $context->type ) {
			// Несуществующий автор: записей у него нет, даже если на сайте есть записи без автора.
			return $context->object_id > 0 && false !== get_userdata( $context->object_id ) ? \IndexPilot\Authors\AuthorProfile::post_count( $context->object_id ) : 0;
		}

		if ( Context::TERM !== $context->type ) {
			return -1;
		}

		$term = get_term( $context->object_id, $context->subtype );

		return $term instanceof \WP_Term ? (int) $term->count : -1;
	}

	/**
	 * Миниатюра записи.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function image_id( Context $context ): int {
		if ( Context::AUTHOR === $context->type ) {
			return (int) get_user_meta( $context->object_id, \IndexPilot\Authors\AuthorProfile::IMAGE_ID, true );
		}

		return Context::POST === $context->type ? (int) get_post_thumbnail_id( $context->object_id ) : 0;
	}
}
