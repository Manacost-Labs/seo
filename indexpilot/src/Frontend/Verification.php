<?php
/**
 * Теги подтверждения прав на сайт.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

use IndexPilot\Settings\Settings;

/**
 * Печатает на главной странице теги для Google Search Console, Яндекс Вебмастера и Bing Webmaster.
 */
final class Verification {

	/**
	 * Имя тега => имя настройки с кодом.
	 */
	private const TAGS = array(
		'google-site-verification' => 'verify_google',
		'yandex-verification'      => 'verify_yandex',
		'msvalidate.01'            => 'verify_bing',
	);

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Подключает вывод.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 1 );
	}

	/**
	 * Печатает теги на главной странице.
	 */
	public function render(): void {
		if ( ! is_front_page() && ! is_home() ) {
			return;
		}

		foreach ( self::TAGS as $name => $setting ) {
			$code = self::code( (string) $this->settings->general( $setting ) );

			if ( '' !== $code ) {
				printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $code ) );
			}
		}
	}

	/**
	 * Код подтверждения из того, что вставили в настройку: сам код или весь тег meta целиком.
	 *
	 * @param string $input Значение настройки.
	 */
	public static function code( string $input ): string {
		$input = trim( $input );

		if ( 1 === preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $input, $matches ) ) {
			$input = trim( $matches[1] );
		}

		return 1 === preg_match( '/^[A-Za-z0-9_\-=]+$/', $input ) ? $input : '';
	}
}
