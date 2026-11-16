<?php
/**
 * Вход администратором на локальном тестовом сайте Playground, чтобы посмотреть экраны плагина.
 * Работает только на адресе 127.0.0.1; в пакет плагина каталог tests не входит.
 *
 * Пример: /wp-content/plugins/indexpilot/tests/smoke/login.php?to=admin.php%3Fpage%3Dindexpilot-overview
 *
 * @package IndexPilot
 */

require dirname( __DIR__, 5 ) . '/wp-load.php';

if ( '127.0.0.1' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	status_header( 403 );
	exit;
}

wp_set_auth_cookie( 1, true );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- локальная тестовая площадка.
$indexpilot_to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : 'admin.php?page=indexpilot-overview';

wp_safe_redirect( admin_url( $indexpilot_to ) );
exit;
