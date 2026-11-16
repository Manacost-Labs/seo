<?php
/**
 * Регистрация мета-полей в WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Meta;

use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Schema\ArticleType;

/**
 * Регистрирует ключи из MetaKeys для публичных типов записей и таксономий и следит, чтобы пустые значения не хранились.
 */
final class MetaRegistrar {

	/**
	 * Временная замена знака процента на время очистки текста.
	 */
	private const PERCENT = "\u{E000}ip\u{E000}";

	/**
	 * Очищает текст, в котором могут быть переменные шаблонов.
	 *
	 * Штатная очистка WordPress вырезает сочетания вида «%da» как процентные коды и ломает
	 * переменные %date%, %category%, %cf_…%. Поэтому знак процента на время очистки прячется.
	 *
	 * @param string $value     Текст из формы.
	 * @param bool   $multiline Сохранять переводы строк.
	 */
	public static function template_text( string $value, bool $multiline = false ): string {
		$value = str_replace( '%', self::PERCENT, $value );
		$value = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

		return str_replace( self::PERCENT, '%', $value );
	}

	/**
	 * Вызывается на хуке init.
	 */
	public function register(): void {
		$definitions = MetaKeys::definitions();

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			foreach ( $definitions as $key => $definition ) {
				if ( in_array( 'post', $definition['objects'], true ) ) {
					register_post_meta( $post_type, $key, $this->args( $definition, 'post' ) );
				}
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			foreach ( $definitions as $key => $definition ) {
				if ( in_array( 'term', $definition['objects'], true ) ) {
					register_term_meta( $taxonomy, $key, $this->args( $definition, 'term' ) );
				}
			}
		}

		foreach ( array( 'post', 'term' ) as $object_type ) {
			$prune = function ( $meta_id, $object_id, $meta_key, $meta_value ) use ( $object_type ): void {
				$this->prune_empty( $object_type, (int) $object_id, (string) $meta_key, $meta_value );
			};

			add_action( "added_{$object_type}_meta", $prune, 10, 4 );
			add_action( "updated_{$object_type}_meta", $prune, 10, 4 );
		}
	}

	/**
	 * Аргументы для register_meta.
	 *
	 * @param array{type: string, kind: string, objects: list<string>, editable: bool} $definition  Описание ключа.
	 * @param string                                                                   $object_type «post» или «term».
	 * @return array<string, mixed>
	 */
	private function args( array $definition, string $object_type ): array {
		$show_in_rest = $definition['editable'];

		if ( $show_in_rest && 'array' === $definition['type'] ) {
			$items = array( 'type' => 'string' );

			if ( 'faq' === $definition['kind'] ) {
				$items = array(
					'type'       => 'object',
					'properties' => array(
						'question' => array( 'type' => 'string' ),
						'answer'   => array( 'type' => 'string' ),
					),
				);
			}

			$show_in_rest = array(
				'schema' => array(
					'type'  => 'array',
					'items' => $items,
				),
			);
		}

		return array(
			'type'              => $definition['type'],
			'single'            => true,
			'show_in_rest'      => $show_in_rest,
			'sanitize_callback' => function ( $value ) use ( $definition ) {
				return $this->sanitize( $value, $definition['kind'] );
			},
			'auth_callback'     => static function ( $allowed, $meta_key, $object_id ) use ( $object_type ): bool {
				$capability = 'term' === $object_type ? 'edit_term' : 'edit_post';

				return current_user_can( $capability, (int) $object_id );
			},
		);
	}

	/**
	 * Очищает значение перед записью в базу.
	 *
	 * @param mixed  $value Значение из формы или REST.
	 * @param string $kind  Способ очистки из MetaKeys.
	 * @return mixed
	 */
	public function sanitize( $value, string $kind ) {
		switch ( $kind ) {
			case 'textarea':
				return self::template_text( (string) $value, true );

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'flag':
				return rest_sanitize_boolean( $value ) ? '1' : '';

			case 'int':
				return absint( $value );

			case 'html':
				return wp_kses_post( (string) $value );

			case 'keywords':
				$keywords = array();
				foreach ( (array) $value as $keyword ) {
					$keyword = sanitize_text_field( (string) $keyword );
					if ( '' !== $keyword ) {
						$keywords[ mb_strtolower( $keyword ) ] = $keyword;
					}
				}

				return array_slice( array_values( $keywords ), 0, MetaKeys::MAX_KEYWORDS );

			case 'faq':
				return FaqBlock::sanitize( $value, static fn( string $text, bool $multiline ): string => self::template_text( $text, $multiline ) );

			case 'schema_type':
				return ArticleType::sanitize( is_scalar( $value ) ? (string) $value : '' );

			case 'json':
				$value = (string) $value;

				return null === json_decode( $value, true ) ? '' : $value;

			default:
				return self::template_text( (string) $value );
		}
	}

	/**
	 * Удаляет мету, если в неё записали пустое значение: пустые строки в базе не нужны.
	 *
	 * @param string $object_type «post» или «term».
	 * @param int    $object_id   ID записи или термина.
	 * @param string $meta_key    Ключ меты.
	 * @param mixed  $meta_value  Записанное значение.
	 */
	private function prune_empty( string $object_type, int $object_id, string $meta_key, $meta_value ): void {
		// Оценка 0 — настоящее значение, а не «пусто», поэтому её не удаляем.
		if ( MetaKeys::SCORE === $meta_key || ! in_array( $meta_key, MetaKeys::all(), true ) ) {
			return;
		}

		$value = maybe_unserialize( $meta_value );

		if ( '' === $value || '0' === $value || 0 === $value || false === $value || array() === $value ) {
			delete_metadata( $object_type, $object_id, $meta_key );
		}
	}
}
