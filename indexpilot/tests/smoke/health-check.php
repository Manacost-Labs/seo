<?php
/**
 * Проверка самопроверки сайта, мёртвых внешних адресов и удобства коротких ссылок внутри тестового WordPress.
 * Отдаёт JSON. Запросов во внешнюю сеть нет: страницы запрашиваются у самого тестового сайта,
 * а мёртвая цель короткой ссылки — внутренний адрес, к которому проверка не допускается.
 * Файл не входит в сборку плагина.
 */

declare( strict_types=1 );

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\HealthPage;
use IndexPilot\Admin\ShortLinkActions;
use IndexPilot\Admin\ShortLinksPage;
use IndexPilot\Health\OutboundCheck;
use IndexPilot\Health\WpStore as HealthStore;
use IndexPilot\Overview\Facts;
use IndexPilot\Plugin;
use IndexPilot\ShortLinks\WpClickLog;
use IndexPilot\ShortLinks\WpStore as ShortLinkStore;

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

global $wpdb;

wp_set_current_user( 1 );

$plugin = Plugin::instance();
$health = $plugin->health();
HealthStore::maybe_install( $wpdb );
$store = $health->store();
$out   = array();

// Самопроверка: запись, рубрика и несуществующий адрес — настоящими запросами к сайту.
$post_url = (string) get_permalink( get_page_by_path( 'gajd-4', OBJECT, 'post' ) );
$cat_url  = (string) get_term_link( 'gajdy', 'category' );
$missing  = home_url( '/smoke-health-net/' );
$check    = $health->self_check();

$check->start(
	array(
		array( 'url' => $post_url ),
		array(
			'url'     => $cat_url,
			'archive' => true,
		),
		array( 'url' => $missing ),
	)
);

$started = microtime( true );
$first   = $check->run_batch( 2 );
$second  = $health->self_check()->run_batch( 10 );
$elapsed = microtime( true ) - $started;

$sitemap = $health->sitemap_urls();

$out['self_check'] = array(
	'continues_from_cursor'   => 2 === $first['checked'] && ! $first['done'] && 1 === $second['checked'] && $second['done'],
	'one_request_per_second'  => $elapsed >= 2.0,
	'post_ok'                 => 200 === ( $store->get( 'page', $post_url )['status'] ?? 0 ) && ! str_contains( (string) ( $store->get( 'page', $post_url )['problems'] ?? 'x' ), 'status' ),
	'missing_reported'        => str_contains( (string) ( $store->get( 'page', $missing )['problems'] ?? '' ), 'status' ),
	'archive_checked'         => null !== $store->get( 'page', $cat_url ),
	'sitemap_has_posts_terms' => in_array( $post_url, array_column( $sitemap, 'url' ), true ) && in_array( true, array_column( $sitemap, 'archive' ), true ),
	'archive_details'         => $store->get( 'page', $cat_url ),
);

// Короткая ссылка, цель которой перестала открываться.
$links = new ShortLinkStore( $wpdb );
$links->maybe_install();
$dead_target = 'http://127.0.0.1:9/smoke-dead-target';
$created     = $plugin->shortlinks()->create( $dead_target, 'smoke-dead' );

$health->collect_shortlinks( $store );
$row     = $store->get( 'link', $dead_target );
$outward = $health->outbound();
$now     = time();

for ( $i = 0; $i < OutboundCheck::DEAD_AFTER; $i++ ) {
	$outward->check_rows( array( (array) $store->get( 'link', $dead_target ) ), $now + $i * OutboundCheck::RECHECK_FAILED );
}

$page = new ShortLinksPage( $plugin->shortlinks(), $links, $plugin->shortlink_router(), new WpClickLog( $wpdb ), $store );
ob_start();
$page->render();
$list_html = (string) ob_get_clean();

ob_start();
( new HealthPage( $health ) )->render();
$health_html = (string) ob_get_clean();

$_GET['q'] = 'smoke-dead';
ob_start();
$page->render();
$search_html = (string) ob_get_clean();
unset( $_GET['q'] );

$out['outbound'] = array(
	'shortlink_created'    => is_array( $created ),
	'target_collected'     => null !== $row && 0 === (int) $row['post_id'],
	'dead_after_three'     => OutboundCheck::is_dead( $store->get( 'link', $dead_target ) ),
	'marked_in_list'       => str_contains( $list_html, 'data-indexpilot-dead-target' ),
	'health_page_lists'    => str_contains( $health_html, 'data-indexpilot-dead="link"' ) && str_contains( $health_html, 'data-indexpilot-health-problem' ),
	'search_finds_link'    => str_contains( $search_html, 'smoke-dead' ) && 1 === substr_count( $search_html, 'data-indexpilot-copy=' ),
	'collect_batch_runs'   => is_array( $health->collect_batch( 50 ) ),
);

// Пачка коротких ссылок, кнопка в строке записи, REST.
$bulk = $plugin->shortlinks()->create_many( "https://example.com/smoke-a smoke-bulk-a\nhttps://example.com/smoke-b", 302, 'смоук' );

$actions = ( new ShortLinkActions( $plugin->shortlink_router() ) )->row_action( array(), get_page_by_path( 'gajd-4', OBJECT, 'post' ) );

rest_get_server();
$request = new WP_REST_Request( 'POST', '/indexpilot/v1/shortlinks' );
$request->set_param( 'target', 'https://example.com/smoke-rest' );
$request->set_param( 'slug', 'smoke-rest' );
$created_status = rest_do_request( $request )->get_status();

$list = new WP_REST_Request( 'GET', '/indexpilot/v1/shortlinks' );
$list->set_param( 'search', 'smoke-rest' );
$listed = rest_do_request( $list )->get_data();

wp_set_current_user( 0 );
$anonymous = rest_do_request( new WP_REST_Request( 'GET', '/indexpilot/v1/shortlinks' ) )->get_status();
wp_set_current_user( 1 );

$out['shortlinks'] = array(
	'bulk_created'      => 2 === count( array_filter( array_column( $bulk, 'result' ), 'is_array' ) ),
	'row_action'        => str_contains( (string) ( $actions['indexpilot_shortlink'] ?? '' ), 'data-indexpilot-copy="' . home_url( '/go/' ) ),
	'rest_created'      => 201 === $created_status,
	'rest_search'       => array( 'smoke-rest' ) === array_column( (array) $listed, 'slug' ),
	'rest_anonymous_no' => 401 === $anonymous,
);

// «Обзор»: новые дела считаются дешёвыми подсчётами.
$counts = ( new Facts( $wpdb, $plugin->audit(), $plugin->link_scanner(), $plugin->redirects() ) )->counts();

$out['overview'] = array(
	'dead_outbound_counted'   => ( $counts['dead_outbound'] ?? 0 ) >= 1,
	'health_problems_counted' => ( $counts['health_problems'] ?? 0 ) >= 1,
	'frequent_404_key'        => array_key_exists( 'frequent_404', $counts ),
);

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
