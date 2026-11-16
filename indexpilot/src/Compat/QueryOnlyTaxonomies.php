<?php
/**
 * Таксономии без своих адресов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Некоторые таксономии зарегистрированы без адресов (rewrite = false): их архивы открываются только по адресу
 * с параметром — «/?deck_streamer=jostik_hs». Такие страницы — служебные выборки, а не разделы сайта: поисковику
 * они не нужны, а дублируют разделы с нормальными адресами. Они закрываются от индексации и не попадают в карту.
 */
final class QueryOnlyTaxonomies {

	/**
	 * У таксономии нет своих адресов.
	 *
	 * @param string $taxonomy Таксономия.
	 */
	public static function is( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		return $object instanceof \WP_Taxonomy && false === $object->rewrite;
	}

	/**
	 * Настройки таксономии с запретом индексации для таксономий без адресов.
	 *
	 * @param array<string, mixed> $config   Настройки таксономии.
	 * @param string               $taxonomy Таксономия.
	 * @return array<string, mixed>
	 */
	public static function config( array $config, string $taxonomy ): array {
		if ( self::is( $taxonomy ) ) {
			$config['noindex'] = true;
			$config['sitemap'] = false;
		}

		return $config;
	}

	/**
	 * Подключает правило к страницам терминов.
	 */
	public function register(): void {
		add_filter(
			'indexpilot_term_config',
			static fn( $config, $context ) => is_array( $config ) && $context instanceof \IndexPilot\Resolver\Context ? self::config( $config, $context->subtype ) : $config,
			5,
			2
		);
	}
}
