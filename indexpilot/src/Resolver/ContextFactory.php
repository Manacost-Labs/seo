<?php
/**
 * Определение контекста текущей страницы сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Переводит основной запрос WordPress в объект Context.
 */
final class ContextFactory {

	/**
	 * Контекст страницы, которую сейчас отдаёт сайт. Вызывать не раньше хука wp.
	 */
	public static function from_query(): Context {
		$paged = max( 1, (int) get_query_var( 'paged' ) );

		if ( is_404() ) {
			return new Context( Context::NOT_FOUND );
		}

		if ( is_search() ) {
			return new Context( Context::SEARCH, 0, '', $paged );
		}

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();

			// У записи, разбитой на страницы, номер лежит в переменной page.
			return new Context( Context::POST, $post_id, (string) get_post_type( $post_id ), max( 1, (int) get_query_var( 'page' ) ) );
		}

		if ( is_home() ) {
			$posts_page = (int) get_option( 'page_for_posts' );

			// Отдельная «страница записей» — это запись типа page, её поля задаются в редакторе.
			if ( $posts_page > 0 && ! is_front_page() ) {
				return new Context( Context::POST, $posts_page, 'page', $paged );
			}

			return new Context( Context::HOME, 0, '', $paged );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				return new Context( Context::TERM, (int) $term->term_id, $term->taxonomy, $paged );
			}
		}

		if ( is_author() ) {
			return new Context( Context::AUTHOR, (int) get_queried_object_id(), '', $paged );
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );

			return new Context( Context::POST_TYPE_ARCHIVE, 0, (string) ( is_array( $post_type ) ? reset( $post_type ) : $post_type ), $paged );
		}

		if ( is_date() ) {
			return new Context( Context::DATE, 0, '', $paged );
		}

		return new Context( Context::HOME, 0, '', $paged );
	}
}
