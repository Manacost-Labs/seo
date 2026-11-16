<?php
/**
 * Проверка коротких ссылок внутри тестового WordPress. Отдаёт JSON и пишет созданные адреса
 * в shortlinks.json — по ним check-sitemap.php проверяет настоящие перенаправления.
 * Режим ?clicks=КОД возвращает счётчик переходов ссылки. Файл не входит в сборку плагина.
 */

declare( strict_types=1 );

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\ClassicMetabox;
use IndexPilot\Admin\ShortLinksPage;
use IndexPilot\Plugin;
use IndexPilot\ShortLinks\Slug;
use IndexPilot\ShortLinks\WpStore;

global $wpdb;

$store = new WpStore( $wpdb );

if ( isset( $_GET['clicks'] ) ) {
	echo (int) ( $store->find( sanitize_key( wp_unslash( $_GET['clicks'] ) ) )['clicks'] ?? -1 );
	exit;
}

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$plugin  = Plugin::instance();
$service = $plugin->shortlinks();
$router  = $plugin->shortlink_router();
$post    = get_page_by_path( 'gajd-4', OBJECT, 'post' );
$slug    = 'twitch' . wp_rand( 100, 999 );

$table_exists = $store->table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table() ) ) );

// Создание через форму страницы: nonce и права проверяет сама страница.
$page = new ShortLinksPage( $service, $store, $router );

$_POST = array(
	'indexpilot_shortlink_action' => 'create',
	'_wpnonce'                    => wp_create_nonce( 'indexpilot_shortlinks' ),
	'target'                      => 'https://www.twitch.tv/manacost?ref=site',
	'slug'                        => strtoupper( $slug ),
	'code'                        => '302',
);
$_REQUEST = $_POST;
$page->handle();

$created = $store->find( $slug );

$_POST['target'] = 'javascript:alert(1)';
$_POST['slug']   = 'bad' . wp_rand( 100, 999 );
$_REQUEST        = $_POST;
$page->handle();
$bad_created = null !== $store->find( $_POST['slug'] );

ob_start();
$page->render();
$html = (string) ob_get_clean();

$_POST    = array();
$_REQUEST = array();

$short = wp_get_shortlink( $post->ID );
$draft = (int) wp_insert_post( array( 'post_title' => 'Черновик без короткой ссылки', 'post_status' => 'draft' ) );

ob_start();
( new ClassicMetabox( $plugin->resolver(), $plugin->settings(), $plugin->variables() ) )->render( $post );
$metabox = (string) ob_get_clean();

file_put_contents(
	__DIR__ . '/shortlinks.json',
	wp_json_encode(
		array(
			'custom'        => $router->url( $slug ),
			'custom_slug'   => $slug,
			'custom_target' => 'https://www.twitch.tv/manacost?ref=site',
			'post'          => $short,
			'post_target'   => get_permalink( $post ),
			'missing'       => $router->url( 'net-takoj-ssylki' ),
		),
		JSON_UNESCAPED_SLASHES
	)
);

$store->maybe_install();
$log = new \IndexPilot\ShortLinks\WpClickLog( $wpdb );
$logged_service = new \IndexPilot\ShortLinks\Service( $store, static fn( int $id ): string => '', $log );
$check_slug = 'smoke-' . wp_rand( 100000, 999999 );
$tagged = $logged_service->create( 'https://example.com/?x=1', $check_slug, 302, array( 'source' => 'newsletter', 'medium' => 'email', 'campaign' => 'launch' ) );
$utm_ok = 'https://example.com/?x=1&utm_source=newsletter&utm_medium=email&utm_campaign=launch' === $store->find( $check_slug )['target'];
$updated = $logged_service->update_target( $tagged['id'], 'https://example.com/new' );
$update_ok = true === $updated && $tagged['id'] === $store->find( $check_slug )['id'] && 'https://example.com/new' === $store->find( $check_slug )['target'];
$expired = $logged_service->create( 'https://example.com/', $check_slug . '-old', 302, array(), 'Smoke', '2000-01-01' );
$expiry_ok = null === $logged_service->resolve( $check_slug . '-old' );
$logged_service->hit( $check_slug, 'Mozilla/5.0', 'https://source.example/private?secret=yes' );
$click_ok = array( gmdate( 'Y-m-d' ) => 1 ) === $log->by_day( $check_slug, 30 ) && array( 'source.example' => 1 ) === $log->by_referrer( $check_slug, 30 );
$logged_service->hit( $check_slug, 'Googlebot', 'https://robot.example/' );
$robot_ok = 1 === $store->find( $check_slug )['clicks'] && array( gmdate( 'Y-m-d' ) => 1 ) === $log->by_day( $check_slug, 30 ) && array( 'source.example' => 1 ) === $log->by_referrer( $check_slug, 30 );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'utm_stored' => $utm_ok,
		'target_updated_slug_kept' => $update_ok,
		'expired_not_resolved' => $expiry_ok,
		'human_logged' => $click_ok,
		'robot_not_logged' => $robot_ok,
		'table_exists'               => $table_exists,
		'created_by_form'            => is_array( $created ) && 'https://www.twitch.tv/manacost?ref=site' === $created['target'] && 302 === $created['code'],
		'dangerous_target_rejected'  => ! $bad_created,
		'page_lists_link'            => str_contains( $html, $router->url( $slug ) ) && str_contains( $html, 'data-indexpilot-copy="' . esc_attr( $router->url( $slug ) ) . '"' ),
		'page_shows_error'           => str_contains( $html, 'notice-error' ),
		'page_has_nonce'             => str_contains( $html, 'name="_wpnonce"' ),
		'post_has_short_address'     => home_url( '/go/' . Slug::for_post( (int) $post->ID ) ) === $short,
		'draft_keeps_core_shortlink' => ! str_contains( (string) wp_get_shortlink( $draft ), '/go/' ),
		'resolves_post'              => get_permalink( $post ) === ( $service->resolve( Slug::for_post( (int) $post->ID ) )['target'] ?? '' ),
		'post_code_cannot_be_taken'  => is_string( $service->create( 'https://example.com/', Slug::for_post( (int) $post->ID ) ) ),
		'editor_shows_short_link'    => str_contains( $metabox, 'data-indexpilot-shortlink' ) && str_contains( $metabox, esc_html( (string) $short ) ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

wp_delete_post( $draft, true );
