<?php
/**
 * Поиск других SEO-плагинов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Пока активен другой SEO-плагин, IndexPilot не выводит свои теги: дублей на странице быть не должно.
 */
final class ConflictDetector {

	/**
	 * Признаки активных плагинов: название => константа или класс.
	 */
	private const KNOWN = array(
		'All in One SEO'    => 'AIOSEO_FILE',
		'Yoast SEO'         => 'WPSEO_VERSION',
		'Rank Math'         => 'RANK_MATH_VERSION',
		'SEOPress'          => 'SEOPRESS_VERSION',
		'The SEO Framework' => 'THE_SEO_FRAMEWORK_VERSION',
		'Slim SEO'          => 'SLIM_SEO_VER',
		'SmartCrawl'        => 'SMARTCRAWL_PLUGIN_DIR',
		'Squirrly SEO'      => 'SQ_VERSION',
	);

	/**
	 * Названия активных SEO-плагинов и самодельных модулей, которые сами выводят теги.
	 *
	 * @return list<string>
	 */
	public function detect(): array {
		$found = array();

		foreach ( self::KNOWN as $name => $constant ) {
			if ( defined( $constant ) ) {
				$found[] = $name;
			}
		}

		// Самодельный модуль kolodahearthstone.com: выводит теги, пока включён в своих настройках.
		if ( function_exists( 'svl_seo_emit_main' ) && ( ! function_exists( 'svl_seo_opt' ) || svl_seo_opt( 'svl_seo_enabled' ) ) ) {
			$found[] = 'KolodaHearthstone Locker (svl-seo)';
		}

		/**
		 * Позволяет сайту сообщить о собственном коде, который выводит SEO-теги.
		 *
		 * @param list<string> $found Найденные источники тегов.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'indexpilot_conflicts', $found ) ) ) );
	}

	/**
	 * Можно ли выводить теги IndexPilot на сайте.
	 */
	public function output_allowed(): bool {
		/**
		 * Принудительно включает вывод, даже если найден другой SEO-плагин.
		 *
		 * @param bool $force Включить вывод.
		 */
		return array() === $this->detect() || (bool) apply_filters( 'indexpilot_force_output', false );
	}
}
