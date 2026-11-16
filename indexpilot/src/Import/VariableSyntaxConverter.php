<?php
/**
 * Перевод переменных других SEO-плагинов в переменные IndexPilot.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Известные переменные заменяются на наши; известные, но без аналога — убираются; остальной текст не меняется.
 */
final class VariableSyntaxConverter {

	/**
	 * Теги All in One SEO: имя без решётки => переменная IndexPilot или пустая строка, если аналога нет.
	 */
	private const AIOSEO = array(
		'post_title'           => '%title%',
		'site_title'           => '%sitename%',
		'blog_title'           => '%sitename%',
		'separator_sa'         => '%sep%',
		'tagline'              => '%tagline%',
		'post_excerpt'         => '%excerpt%',
		'post_excerpt_only'    => '%excerpt%',
		'post_content'         => '',
		'post_date'            => '%date%',
		'post_day'             => '',
		'post_month'           => '',
		'post_year'            => '',
		'current_date'         => '',
		'current_day'          => '',
		'current_month'        => '',
		'current_year'         => '%year%',
		'author_name'          => '%author%',
		'author_first_name'    => '',
		'author_last_name'     => '',
		'author_bio'           => '',
		'author_link'          => '',
		'categories'           => '%category%',
		'category_title'       => '%term%',
		'taxonomy_title'       => '%term%',
		'taxonomy_description' => '%term_description%',
		'archive_title'        => '%term%',
		'archive_date'         => '',
		'page_number'          => '%page%',
		'focus_keyphrase'      => '%focus_keyword%',
		'parent_title'         => '',
		'permalink'            => '',
		'post_link'            => '',
		'site_link'            => '',
		'search_term'          => '',
	);

	/**
	 * Теги All in One SEO с параметром после дефиса: имя => префикс переменной IndexPilot.
	 */
	private const AIOSEO_PREFIXED = array(
		'custom_field' => 'cf_',
		'tax_name'     => 'tax_',
	);

	/**
	 * Переменные Rank Math: имя без знаков процента => имя переменной IndexPilot.
	 */
	private const RANK_MATH = array(
		'title'            => 'title',
		'sitename'         => 'sitename',
		'sitedesc'         => 'tagline',
		'sep'              => 'sep',
		'excerpt'          => 'excerpt',
		'excerpt_only'     => 'excerpt',
		'category'         => 'category',
		'categories'       => 'category',
		'term'             => 'term',
		'term_description' => 'term_description',
		'name'             => 'author',
		'post_author'      => 'author',
		'focuskw'          => 'focus_keyword',
		'focus_keyword'    => 'focus_keyword',
		'page'             => 'page',
		'currentyear'      => 'year',
		'date'             => 'date',
		'modified'         => 'modified',
	);

	/**
	 * Переменные Rank Math с параметром в скобках: имя => префикс переменной IndexPilot.
	 */
	private const RANK_MATH_PREFIXED = array(
		'customfield' => 'cf_',
		'customterm'  => 'tax_',
	);

	/**
	 * Шаблон All in One SEO: «#post_title #separator_sa #site_title».
	 *
	 * @param string $template Шаблон в синтаксисе All in One SEO.
	 */
	public static function from_aioseo( string $template ): string {
		$converted = preg_replace_callback(
			'/#([a-z_]+)(?:-([A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*))?/',
			static function ( array $matches ): string {
				$name = $matches[1];
				$arg  = $matches[2] ?? '';

				if ( '' !== $arg && isset( self::AIOSEO_PREFIXED[ $name ] ) ) {
					return '%' . self::AIOSEO_PREFIXED[ $name ] . $arg . '%';
				}

				if ( ! isset( self::AIOSEO[ $name ] ) ) {
					// Не тег плагина, а обычный текст с решёткой.
					return $matches[0];
				}

				return self::AIOSEO[ $name ] . ( '' !== $arg ? '-' . $arg : '' );
			},
			$template
		);

		return self::squeeze( (string) $converted );
	}

	/**
	 * Шаблон Rank Math: «%title% %sep% %sitename%». Неизвестные переменные убираются.
	 *
	 * @param string $template Шаблон в синтаксисе Rank Math.
	 */
	public static function from_rank_math( string $template ): string {
		$converted = preg_replace_callback(
			'/%([a-z_]+)(?:\(([^)%]*)\))?%/',
			static function ( array $matches ): string {
				$name = $matches[1];
				$arg  = trim( $matches[2] ?? '' );

				if ( '' !== $arg && isset( self::RANK_MATH_PREFIXED[ $name ] ) ) {
					return '%' . self::RANK_MATH_PREFIXED[ $name ] . $arg . '%';
				}

				return isset( self::RANK_MATH[ $name ] ) ? '%' . self::RANK_MATH[ $name ] . '%' : '';
			},
			$template
		);

		return self::squeeze( (string) $converted );
	}

	/**
	 * Убирает лишние пробелы, оставшиеся после удалённых переменных.
	 *
	 * @param string $text Текст.
	 */
	private static function squeeze( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
