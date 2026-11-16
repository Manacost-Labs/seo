<?php
/**
 * Чужие SEO-теги, которые дублируют вывод IndexPilot.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Некоторые плагины сайтов печатают свои description и теги соцсетей, если не видят «известного» SEO-плагина
 * (Yoast, Rank Math, SEOPress, AIOSEO). IndexPilot они не знают, и на странице оказывается по два description
 * и og:type. Класс снимает такие выводы с wp_head — только когда теги печатает сам IndexPilot.
 * Код чужих плагинов не меняется: на сервере он обновляется из своих репозиториев.
 */
final class ForeignHead {

	/**
	 * Известные выводы: класс → методы на wp_head.
	 */
	public const KNOWN = array(
		// Плагин колод kolodahearthstone.com: description, og:* и twitter:* на одиночных страницах («KolodaHS OG tags»).
		'KolodaHearthstone_Decks' => array( 'output_og_tags' ),
		// Плагин колод hs-manacost.ru: canonical (без AIOSEO), description, og:* и twitter:* на страницах архетипов.
		'HS_Decks_Manager'        => array( 'render_archetype_seo_meta' ),
	);

	/**
	 * Снимать ли вывод.
	 *
	 * @param string                      $class_name Класс объекта обработчика.
	 * @param string                      $method     Метод обработчика.
	 * @param array<string, list<string>> $known      Известные выводы.
	 */
	public static function matches( string $class_name, string $method, array $known = self::KNOWN ): bool {
		return in_array( $method, $known[ $class_name ] ?? array(), true );
	}

	/**
	 * Подключает снятие. Вызывается, только когда IndexPilot печатает теги.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'strip' ), -1000 );
	}

	/**
	 * Снимает известные чужие выводы с wp_head.
	 */
	public function strip(): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_head'] ) || ! $wp_filter['wp_head'] instanceof \WP_Hook ) {
			return;
		}

		/**
		 * Чужие выводы тегов, которые снимаются, пока теги печатает IndexPilot: класс → список методов.
		 *
		 * @param array<string, list<string>> $known Известные выводы.
		 */
		$known = (array) apply_filters( 'indexpilot_foreign_head', self::KNOWN );

		foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( is_array( $function ) && is_object( $function[0] ?? null ) && is_string( $function[1] ?? null ) && self::matches( get_class( $function[0] ), $function[1], $known ) ) {
					remove_action( 'wp_head', $function, (int) $priority );
				}
			}
		}
	}
}
