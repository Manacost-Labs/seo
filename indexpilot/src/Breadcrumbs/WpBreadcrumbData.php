<?php
/**
 * Данные WordPress для хлебных крошек.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Breadcrumbs;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Meta\PrimaryCategory;
use IndexPilot\Resolver\Context;
use IndexPilot\Settings\Settings;

/**
 * Читает записи, термины и архивы из WordPress.
 */
final class WpBreadcrumbData implements BreadcrumbData {

	private const NOTHING = array(
		'name' => '',
		'url'  => '',
	);

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки: в них задана таксономия для цепочки у каждого типа записи.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Главная страница.
	 *
	 * @return array{name: string, url: string}
	 */
	public function home(): array {
		return array(
			'name' => __( 'Главная', 'indexpilot' ),
			'url'  => home_url( '/' ),
		);
	}

	/**
	 * Запись.
	 *
	 * @param int $post_id ID записи.
	 * @return array{name: string, url: string, type: string, parent: int}
	 */
	public function post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return self::NOTHING + array(
				'type'   => '',
				'parent' => 0,
			);
		}

		// Название для цепочки из панели записи: короче названия самой записи.
		$name = trim( (string) get_post_meta( $post->ID, MetaKeys::BREADCRUMB_TITLE, true ) );

		return array(
			'name'   => '' !== $name ? $name : self::plain_title( $post ),
			'url'    => (string) get_permalink( $post ),
			'type'   => $post->post_type,
			'parent' => (int) $post->post_parent,
		);
	}

	/**
	 * Название записи без разметки и без приставки «Защищено:» или «Личное:», которую WordPress добавляет
	 * закрытым записям: в разметке и в цепочке она лишняя.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public static function plain_title( \WP_Post $post ): string {
		$plain = static fn(): string => '%s';

		add_filter( 'protected_title_format', $plain, 999 );
		add_filter( 'private_title_format', $plain, 999 );

		$title = wp_strip_all_tags( get_the_title( $post ) );

		remove_filter( 'protected_title_format', $plain, 999 );
		remove_filter( 'private_title_format', $plain, 999 );

		return $title;
	}

	/**
	 * Архив типа записи. Для обычных записей это страница блога, если она назначена в настройках чтения.
	 *
	 * @param string $post_type Тип записи.
	 * @return array{name: string, url: string}
	 */
	public function archive( string $post_type ): array {
		if ( 'post' === $post_type ) {
			$page_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

			return $page_id > 0
				? array(
					'name' => wp_strip_all_tags( get_the_title( $page_id ) ),
					'url'  => (string) get_permalink( $page_id ),
				)
				: self::NOTHING;
		}

		$object = get_post_type_object( $post_type );
		$link   = $object && $object->has_archive ? get_post_type_archive_link( $post_type ) : false;

		return $object && is_string( $link )
			? array(
				'name' => (string) $object->labels->name,
				'url'  => $link,
			)
			: self::NOTHING;
	}

	/**
	 * Главный термин записи: из таксономии, заданной в настройках типа записи, иначе из рубрик,
	 * иначе из первой древовидной таксономии. Из нескольких терминов берётся самый вложенный;
	 * из нескольких рубрик — основная, если редактор выбрал её в панели записи.
	 *
	 * @param int    $post_id   ID записи.
	 * @param string $post_type Тип записи.
	 * @return array{id: int, taxonomy: string}
	 */
	public function post_term( int $post_id, string $post_type ): array {
		$taxonomies = array( (string) ( $this->settings->post_type( $post_type )['breadcrumb_taxonomy'] ?? '' ), 'category' );

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->hierarchical && $taxonomy->public ) {
				$taxonomies[] = $taxonomy->name;
			}
		}

		foreach ( array_unique( array_filter( $taxonomies ) ) as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}

			// Основная рубрика, выбранная в панели записи, сильнее правила «самый вложенный термин».
			$primary = 'category' === $taxonomy ? PrimaryCategory::id( $post_id ) : 0;

			if ( $primary > 0 ) {
				return array(
					'id'       => $primary,
					'taxonomy' => $taxonomy,
				);
			}

			$best  = 0;
			$depth = -1;

			foreach ( $terms as $term ) {
				$term_depth = count( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );

				// При равной вложенности выбирается термин с меньшим ID: результат не зависит от порядка выборки.
				if ( $term_depth > $depth || ( $term_depth === $depth && $term->term_id < $best ) ) {
					$best  = (int) $term->term_id;
					$depth = $term_depth;
				}
			}

			return array(
				'id'       => $best,
				'taxonomy' => $taxonomy,
			);
		}

		return array(
			'id'       => 0,
			'taxonomy' => '',
		);
	}

	/**
	 * Термин.
	 *
	 * @param int    $term_id  ID термина.
	 * @param string $taxonomy Таксономия.
	 * @return array{name: string, url: string, parent: int}
	 */
	public function term( int $term_id, string $taxonomy ): array {
		$term = get_term( $term_id, $taxonomy );
		$link = $term instanceof \WP_Term ? get_term_link( $term ) : '';

		if ( ! $term instanceof \WP_Term || ! is_string( $link ) ) {
			return self::NOTHING + array( 'parent' => 0 );
		}

		return array(
			'name'   => $term->name,
			'url'    => $link,
			'parent' => (int) $term->parent,
		);
	}

	/**
	 * Автор.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array{name: string, url: string}
	 */
	public function author( int $user_id ): array {
		return array(
			'name' => (string) get_the_author_meta( 'display_name', $user_id ),
			'url'  => get_author_posts_url( $user_id ),
		);
	}

	/**
	 * Название страницы поиска, архива по дате или 404.
	 *
	 * @param Context $context Контекст страницы.
	 * @return array{name: string, url: string}
	 */
	public function current( Context $context ): array {
		switch ( $context->type ) {
			case Context::SEARCH:
				/* translators: %s: поисковый запрос. */
				$name = sprintf( __( 'Поиск: %s', 'indexpilot' ), get_search_query( false ) );
				break;

			case Context::NOT_FOUND:
				$name = __( 'Страница не найдена', 'indexpilot' );
				break;

			default:
				$name = wp_strip_all_tags( get_the_archive_title() );
		}

		return array(
			'name' => $name,
			'url'  => '',
		);
	}
}
