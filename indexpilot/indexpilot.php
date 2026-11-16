<?php
/**
 * Plugin Name:       IndexPilot
 * Description:       SEO для Hearthstone-сайтов: мета-теги, предпросмотр сниппета, анализ текста, рубрики, колоды.
 * Version:           1.0.0-rc7
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       indexpilot
 *
 * @package IndexPilot
 */

defined( 'ABSPATH' ) || exit;

define( 'INDEXPILOT_VERSION', '1.0.0-rc7' );
define( 'INDEXPILOT_FILE', __FILE__ );
define( 'INDEXPILOT_DIR', __DIR__ . '/' );

if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'IndexPilot не запущен: нужен PHP 8.1 или новее.', 'indexpilot' );
			echo '</p></div>';
		}
	);
	return;
}

if ( is_readable( INDEXPILOT_DIR . 'vendor/autoload.php' ) ) {
	require_once INDEXPILOT_DIR . 'vendor/autoload.php';
} else {
	// На сайте Composer не нужен: классы плагина грузятся по PSR-4 из src/.
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'IndexPilot\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}
			$file = INDEXPILOT_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

require_once INDEXPILOT_DIR . 'src/functions.php';

add_action( 'plugins_loaded', array( \IndexPilot\Plugin::class, 'boot' ) );
