<?php
/**
 * Поле indexpilot в стандартных REST-ответах WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;

/**
 * Добавляет к записям и терминам в REST поле indexpilot. Внешние инструменты читают из него итоговые теги страницы
 * и записывают SEO-поля тем же запросом, которым правят запись.
 *
 * Итоговые теги видны всем — они и так есть в HTML страницы. Основной ключ, оценка и исходные значения полей
 * отдаются только в контексте правки, то есть тем, кто может редактировать запись.
 */
final class RestField {

	private const FIELD = 'indexpilot';

	/**
	 * Поля, которые можно записать: имя в REST => ключ меты.
	 */
	private const WRITABLE = array(
		'seo_title'       => MetaKeys::TITLE,
		'seo_description' => MetaKeys::DESCRIPTION,
		'focus_keyword'   => MetaKeys::FOCUS_KEYWORD,
		'keywords'        => MetaKeys::KEYWORDS,
		'canonical'       => MetaKeys::CANONICAL,
		'noindex'         => MetaKeys::NOINDEX,
	);

	/**
	 * Поля панели записи, которых нет у терминов: имя в REST => ключ меты.
	 */
	private const POST_WRITABLE = array(
		'answer'           => MetaKeys::ANSWER,
		'faq'              => MetaKeys::FAQ,
		'schema_type'      => MetaKeys::SCHEMA_TYPE,
		'breadcrumb_title' => MetaKeys::BREADCRUMB_TITLE,
		'primary_category' => MetaKeys::PRIMARY_CATEGORY,
	);

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер мета-данных.
	 */
	public function __construct( private MetaResolver $resolver ) {
	}

	/**
	 * Регистрирует поле для типов записей и таксономий, у которых есть страницы на сайте.
	 */
	public function register(): void {
		$schema = array(
			'description' => __( 'SEO-данные IndexPilot.', 'indexpilot' ),
			'type'        => 'object',
			'context'     => array( 'view', 'edit' ),
		);

		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
			if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				register_rest_field(
					$type,
					self::FIELD,
					array(
						'get_callback'    => fn( array $item, string $field, \WP_REST_Request $request ): array => $this->get( Context::POST, (int) $item['id'], (string) $type, $request ),
						'update_callback' => fn( $value, $post ) => $post instanceof \WP_Post ? $this->update( $value, 'post', (int) $post->ID ) : null,
						'schema'          => $schema,
					)
				);
			}
		}

		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
			if ( is_taxonomy_viewable( $taxonomy ) ) {
				register_rest_field(
					$this->term_object_type( $taxonomy->name ),
					self::FIELD,
					array(
						'get_callback'    => fn( array $item, string $field, \WP_REST_Request $request ): array => $this->get( Context::TERM, (int) $item['id'], $taxonomy->name, $request ),
						'update_callback' => fn( $value, $term ) => $term instanceof \WP_Term ? $this->update( $value, 'term', (int) $term->term_id ) : null,
						'schema'          => $schema,
					)
				);
			}
		}
	}

	/**
	 * Значение поля.
	 *
	 * @param string           $type      Вид страницы: запись или термин.
	 * @param int              $id        ID объекта.
	 * @param string           $subtype   Тип записи или таксономия.
	 * @param \WP_REST_Request $request   Запрос.
	 * @return array<string, mixed>
	 */
	private function get( string $type, int $id, string $subtype, \WP_REST_Request $request ): array {
		$meta  = $this->resolver->resolve( new Context( $type, $id, $subtype ) );
		$value = array(
			'title'       => $meta->title,
			'description' => $meta->description,
			'canonical'   => $meta->canonical,
			'robots'      => $meta->robots_string(),
		);

		if ( 'edit' !== $request['context'] ) {
			return $value;
		}

		$read = Context::TERM === $type ? 'get_term_meta' : 'get_post_meta';

		$value += array(
			'seo_title'       => (string) $read( $id, MetaKeys::TITLE, true ),
			'seo_description' => (string) $read( $id, MetaKeys::DESCRIPTION, true ),
			'focus_keyword'   => (string) $read( $id, MetaKeys::FOCUS_KEYWORD, true ),
			'keywords'        => array_values( (array) $read( $id, MetaKeys::KEYWORDS, true ) ),
			'noindex'         => '1' === (string) $read( $id, MetaKeys::NOINDEX, true ),
			'score'           => (string) $read( $id, MetaKeys::SCORE, true ),
		);

		if ( Context::POST === $type ) {
			$value += array(
				'answer'           => (string) get_post_meta( $id, MetaKeys::ANSWER, true ),
				'faq'              => FaqBlock::sanitize( get_post_meta( $id, MetaKeys::FAQ, true ) ),
				'schema_type'      => (string) get_post_meta( $id, MetaKeys::SCHEMA_TYPE, true ),
				'breadcrumb_title' => (string) get_post_meta( $id, MetaKeys::BREADCRUMB_TITLE, true ),
				'primary_category' => (int) get_post_meta( $id, MetaKeys::PRIMARY_CATEGORY, true ),
			);
		}

		return $value;
	}

	/**
	 * Записывает присланные поля. Право на правку объекта к этому моменту проверил WordPress; проверяем ещё раз.
	 *
	 * @param mixed  $value Значение поля из запроса.
	 * @param string $kind  post или term.
	 * @param int    $id    ID объекта.
	 * @return true|\WP_Error
	 */
	private function update( $value, string $kind, int $id ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'indexpilot_invalid_field', __( 'Поле indexpilot должно быть объектом.', 'indexpilot' ), array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'term' === $kind ? 'edit_term' : 'edit_post', $id ) ) {
			return new \WP_Error( 'indexpilot_forbidden', __( 'Нет права править этот объект.', 'indexpilot' ), array( 'status' => 403 ) );
		}

		foreach ( self::WRITABLE + ( 'post' === $kind ? self::POST_WRITABLE : array() ) as $name => $meta_key ) {
			if ( ! array_key_exists( $name, $value ) ) {
				continue;
			}

			$field = $value[ $name ];

			if ( 'noindex' === $name ) {
				$field = rest_sanitize_boolean( $field ) ? '1' : '';
			} elseif ( 'keywords' === $name ) {
				$field = is_array( $field ) ? array_map( 'strval', $field ) : array();
			} elseif ( 'faq' === $name ) {
				$field = FaqBlock::sanitize( $field );
			} elseif ( 'primary_category' === $name ) {
				$field = is_scalar( $field ) ? absint( $field ) : 0;
			} else {
				$field = is_scalar( $field ) ? (string) $field : '';
			}

			// Значение очищает sanitize_callback, заданный при регистрации меты (см. MetaRegistrar).
			if ( 'term' === $kind ) {
				update_term_meta( $id, $meta_key, wp_slash( $field ) );
			} else {
				update_post_meta( $id, $meta_key, wp_slash( $field ) );
			}
		}

		return true;
	}

	/**
	 * Имя типа объекта REST для таксономии: у меток это «tag», у остальных — имя таксономии.
	 *
	 * @param string $taxonomy Таксономия.
	 */
	private function term_object_type( string $taxonomy ): string {
		return 'post_tag' === $taxonomy ? 'tag' : $taxonomy;
	}
}
