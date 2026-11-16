<?php
/**
 * Базовые переменные шаблонов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Variables;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Meta\PrimaryCategory;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\PostDates;

/**
 * Переменные, которые берут значения из WordPress. Несохранённые значения из редактора имеют приоритет.
 */
final class CoreVariables {

	/**
	 * Сколько терминов таксономии подставлять в %tax_ТАКСОНОМИЯ%.
	 */
	private const MAX_TERMS = 3;

	/**
	 * Значения-заглушки, которые на сайтах стоят вместо пустого поля; в шаблонах считаются пустыми.
	 */
	private const PLACEHOLDERS = array( 'unknown', 'n/a', 'none', 'null', '-', '—' );

	/**
	 * Служебные поля (имя начинается с «_»), которые можно выводить через %cf_КЛЮЧ%: поля колод.
	 * Остальные служебные поля закрыты — в них плагины держат закрытый текст и ключи.
	 */
	public const ALLOWED_PRIVATE_FIELDS = array( '_dust_cost', '_deck_player', '_deck_streamer', '_deck_code', '_hs_deck_code', '_deck_copies', '_deck_wins', '_deck_losses', '_deck_peak', '_hs_archetype_key' );

	/**
	 * Регистрирует переменные в реестре.
	 *
	 * @param VariableRegistry $registry Реестр переменных.
	 */
	public function register( VariableRegistry $registry ): void {
		$registry->register( 'title', array( $this, 'title' ) );
		$registry->register( 'sitename', static fn(): string => self::plain( get_bloginfo( 'name' ) ) );
		$registry->register( 'tagline', static fn(): string => self::plain( get_bloginfo( 'description' ) ) );
		$registry->register( 'excerpt', array( $this, 'excerpt' ) );
		$registry->register( 'category', fn( Context $context ): string => $this->terms( 'category', $context, 1 ) );
		$registry->register( 'term', array( $this, 'term_name' ) );
		$registry->register( 'term_description', array( $this, 'term_description' ) );
		$registry->register( 'count', array( $this, 'term_count' ) );
		$registry->register( 'author', array( $this, 'author' ) );
		$registry->register( 'author_role', array( $this, 'author_role' ) );
		$registry->register( 'focus_keyword', array( $this, 'focus_keyword' ) );
		$registry->register( 'page', array( $this, 'page' ) );
		$registry->register( 'year', static fn(): string => wp_date( 'Y' ) );
		$registry->register( 'date', fn( Context $context ): string => $this->post_date( $context, 'post_date' ) );
		$registry->register( 'modified', fn( Context $context ): string => $this->post_date( $context, 'post_modified' ) );

		$registry->register_prefix( 'cf_', array( $this, 'custom_field' ) );
		$registry->register_prefix( 'tax_', fn( string $taxonomy, Context $context ): string => $this->terms( $taxonomy, $context, self::MAX_TERMS ) );
	}

	/**
	 * Название объекта страницы.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function title( Context $context ): string {
		$override = $context->override( 'title' );
		if ( is_string( $override ) ) {
			return self::plain( $override );
		}

		switch ( $context->type ) {
			case Context::POST:
				return self::plain( (string) get_post_field( 'post_title', $context->object_id ) );

			case Context::TERM:
				return $this->term_name( $context );

			case Context::AUTHOR:
				return self::plain( (string) get_the_author_meta( 'display_name', $context->object_id ) );

			case Context::POST_TYPE_ARCHIVE:
				$post_type = get_post_type_object( $context->subtype );

				return $post_type ? self::plain( (string) $post_type->labels->name ) : '';

			case Context::SEARCH:
				/* translators: %s: поисковый запрос. */
				return sprintf( __( 'Поиск: %s', 'indexpilot' ), self::plain( get_search_query( false ) ) );

			case Context::NOT_FOUND:
				return __( 'Страница не найдена', 'indexpilot' );

			default:
				return self::plain( get_bloginfo( 'name' ) );
		}
	}

	/**
	 * Отрывок записи, заданный вручную.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function excerpt( Context $context ): string {
		$override = $context->override( 'excerpt' );
		if ( is_string( $override ) ) {
			return self::plain( $override );
		}

		if ( Context::POST !== $context->type ) {
			return '';
		}

		return self::plain( (string) get_post_field( 'post_excerpt', $context->object_id ) );
	}

	/**
	 * Название термина.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function term_name( Context $context ): string {
		$term = $this->term( $context );

		return $term ? self::plain( $term->name ) : '';
	}

	/**
	 * Описание термина без разметки.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function term_description( Context $context ): string {
		$term = $this->term( $context );

		return $term ? self::plain( $term->description ) : '';
	}

	/**
	 * Число записей термина.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function term_count( Context $context ): string {
		$term = $this->term( $context );

		return $term ? (string) $term->count : '';
	}

	/**
	 * Имя автора записи или страницы автора.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function author( Context $context ): string {
		$author_id = Context::AUTHOR === $context->type
			? $context->object_id
			: (int) get_post_field( 'post_author', $context->object_id );

		return $author_id ? self::plain( (string) get_the_author_meta( 'display_name', $author_id ) ) : '';
	}

	/**
	 * Роль автора из его профиля: стример, игрок, редактор.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function author_role( Context $context ): string {
		$author_id = Context::AUTHOR === $context->type
			? $context->object_id
			: (int) get_post_field( 'post_author', $context->object_id );

		return $author_id ? self::plain( (string) get_user_meta( $author_id, \IndexPilot\Authors\AuthorProfile::ROLE, true ) ) : '';
	}

	/**
	 * Основное ключевое слово.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function focus_keyword( Context $context ): string {
		$override = $context->override( 'focus_keyword' );
		if ( is_string( $override ) ) {
			return self::plain( $override );
		}

		return self::plain( (string) $this->meta( $context, MetaKeys::FOCUS_KEYWORD ) );
	}

	/**
	 * «страница N» для второй и следующих страниц, иначе пустая строка.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function page( Context $context ): string {
		if ( $context->page < 2 ) {
			return '';
		}

		/* translators: %d: номер страницы. */
		return sprintf( __( 'страница %d', 'indexpilot' ), $context->page );
	}

	/**
	 * Произвольное поле записи или термина: %cf_КЛЮЧ%.
	 *
	 * @param string  $key     Ключ меты.
	 * @param Context $context Контекст страницы.
	 */
	public function custom_field( string $key, Context $context ): string {
		if ( ! self::field_allowed( $key ) ) {
			return '';
		}

		$overrides = $context->override( 'cf' );
		if ( is_array( $overrides ) && isset( $overrides[ $key ] ) && is_scalar( $overrides[ $key ] ) ) {
			return self::plain( (string) $overrides[ $key ] );
		}

		$value = $this->meta( $context, $key );

		return is_scalar( $value ) ? self::without_placeholder( self::plain( (string) $value ) ) : '';
	}

	/**
	 * Можно ли выводить поле в заголовке и описании: обычные поля — да, служебные — только из списка.
	 *
	 * @param string $key Ключ меты.
	 */
	public static function field_allowed( string $key ): bool {
		return '' !== $key && ( ! str_starts_with( $key, '_' ) || in_array( $key, self::ALLOWED_PRIVATE_FIELDS, true ) );
	}

	/**
	 * Дата записи в формате сайта.
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $field   Поле записи: post_date или post_modified.
	 */
	private function post_date( Context $context, string $field ): string {
		if ( Context::POST !== $context->type ) {
			return '';
		}

		$date = (string) get_post_field( $field, $context->object_id );

		// Дата изменения не раньше даты публикации — та же, что в тегах и разметке (см. PostDates).
		if ( 'post_modified' === $field && '' !== $date ) {
			return PostDates::modified( (string) get_option( 'date_format' ), false, $context->object_id, true );
		}

		return '' === $date ? '' : (string) mysql2date( get_option( 'date_format' ), $date );
	}

	/**
	 * Названия терминов записи через запятую.
	 *
	 * @param string  $taxonomy Таксономия.
	 * @param Context $context  Контекст страницы.
	 * @param int     $limit    Наибольшее число терминов.
	 */
	private function terms( string $taxonomy, Context $context, int $limit ): string {
		if ( Context::POST !== $context->type || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		// У рубрик первой идёт основная, выбранная в панели записи.
		$terms = 'category' === $taxonomy ? PrimaryCategory::terms( $context->object_id ) : get_the_terms( $context->object_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return '';
		}

		$names = array();
		foreach ( $terms as $term ) {
			$name = self::without_placeholder( self::plain( $term->name ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return implode( ', ', array_slice( $names, 0, $limit ) );
	}

	/**
	 * Термин контекста.
	 *
	 * @param Context $context Контекст страницы.
	 */
	private function term( Context $context ): ?\WP_Term {
		if ( Context::TERM !== $context->type ) {
			return null;
		}

		$term = get_term( $context->object_id, $context->subtype );

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Мета записи или термина.
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $key     Ключ меты.
	 * @return mixed
	 */
	private function meta( Context $context, string $key ) {
		if ( Context::POST === $context->type ) {
			return get_post_meta( $context->object_id, $key, true );
		}

		if ( Context::TERM === $context->type ) {
			return get_term_meta( $context->object_id, $key, true );
		}

		return '';
	}

	/**
	 * Возвращает пустую строку вместо заглушки вроде «Unknown».
	 *
	 * @param string $value Значение поля или название термина.
	 */
	private static function without_placeholder( string $value ): string {
		return in_array( mb_strtolower( $value ), self::PLACEHOLDERS, true ) ? '' : $value;
	}

	/**
	 * Убирает разметку и лишние пробелы.
	 *
	 * @param string $text Исходный текст.
	 */
	private static function plain( string $text ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true ) );
	}
}
