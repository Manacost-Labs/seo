<?php
/**
 * Проверка замера нагрузки внутри тестового WordPress: сколько запросов к базе плагин добавляет
 * на страницу записи, рубрики и на экран админки. Отдаёт JSON.
 * Страницы открываются настоящими запросами сайта к самому себе — тем же путём, что и команда «wp indexpilot doctor».
 * Файл не входит в сборку плагина.
 */

// Список запросов этого запроса нужен для проверки экрана «Обзор».
define( 'SAVEQUERIES', true );

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\OverviewPage;
use IndexPilot\Doctor\Checkup;
use IndexPilot\Doctor\QueryLog;
use IndexPilot\Overview\Facts;
use IndexPilot\Plugin;

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$checkup  = new Checkup();
$targets  = $checkup->targets();
$category = get_term_by( 'slug', 'gajdy', 'category' );

// Те же страницы, что проверяет check-head.php: запись без своих полей и рубрика с записями.
$pages = array(
	'post'     => $checkup->page( (string) get_permalink( get_page_by_path( 'gajd-4', OBJECT, 'post' ) ) ),
	'category' => $checkup->page( (string) get_term_link( $category ) ),
	'admin'    => $checkup->admin_page( admin_url( 'index.php' ), 1 ),
);

// Первое открытие админки на свежем сайте бывает долгим (WordPress проверяет обновления): вторая попытка быстрая.
if ( isset( $pages['admin']['error'] ) ) {
	$pages['admin'] = $checkup->admin_page( admin_url( 'index.php' ), 1 );
}

// Запрос с поддельным параметром ничего не включает и не оставляет следов: страница отдаётся как обычно.
$plain    = wp_remote_get( add_query_arg( 'indexpilot_doctor', 'abcdef0123456789.9999999999.' . str_repeat( 'a', 64 ), home_url( '/robots.txt' ) ), array( 'timeout' => 30 ) );
$leftover = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'indexpilot_doctor_probe'" );

$measured = static fn( array $page ): bool => ! isset( $page['error'] ) && 200 === ( $page['status'] ?? 0 ) && ( $page['total'] ?? 0 ) > 0;
$callers  = static fn( array $page ): array => array_map( static fn( array $query ): string => $query['caller'] . ' — ' . $query['sql'], $page['queries'] ?? array() );

// Экран «Обзор»: открывается без обхода записей — только подсчёты и сохранённые состояния.
global $wpdb;

$plugin   = Plugin::instance();
$facts    = new Facts( $wpdb, $plugin->audit(), $plugin->link_scanner(), $plugin->redirects() );
$overview = new OverviewPage( $facts );
$render   = static function () use ( $overview ): string {
	ob_start();
	$overview->render();

	return (string) ob_get_clean();
};

$anonymous_html = $render();
wp_set_current_user( 1 );

$before        = count( $wpdb->queries );
$overview_html = $render();
$made          = array_slice( $wpdb->queries, $before );
$walks         = array();

foreach ( $made as $query ) {
	$sql = trim( (string) preg_replace( '/\s+/', ' ', (string) $query[0] ) );

	// Обход записей — это выборка самих записей; подсчёт строк и чтение настроек обходом не считаются.
	if ( 1 === preg_match( '/\b' . preg_quote( $wpdb->posts, '/' ) . '\b|\b' . preg_quote( $wpdb->postmeta, '/' ) . '\b/', $sql ) && 0 !== stripos( $sql, 'SELECT COUNT(' ) ) {
		$walks[] = $sql;
	}
}

$counts = $facts->counts();
wp_set_current_user( 0 );

$out = array(
	'targets_found'           => isset( $targets['post'], $targets['category'], $targets['admin'] ),
	'post_measured'           => $measured( $pages['post'] ),
	'category_measured'       => $measured( $pages['category'] ),
	'admin_measured'          => $measured( $pages['admin'] ) && ! empty( $pages['admin']['admin'] ),
	'post_within_limit'       => $measured( $pages['post'] ) && QueryLog::within_limit( (int) $pages['post']['plugin'] ),
	'category_within_limit'   => $measured( $pages['category'] ) && QueryLog::within_limit( (int) $pages['category']['plugin'] ),
	'forged_parameter_is_ignored' => ! is_wp_error( $plain ) && 200 === wp_remote_retrieve_response_code( $plain ) && str_contains( wp_remote_retrieve_body( $plain ), 'Sitemap:' ) && 0 === $leftover,
	'overview_hidden_from_anonymous' => '' === $anonymous_html,
	'overview_renders'        => str_contains( $overview_html, 'data-indexpilot-overview' ),
	'overview_lists_up_to_five_tasks' => substr_count( $overview_html, 'data-indexpilot-task=' ) >= 1 && substr_count( $overview_html, 'data-indexpilot-task=' ) <= 5,
	'overview_links_to_reports' => 1 === preg_match( '#<a href="[^"]*admin\.php\?page=indexpilot-(audit|links|redirects)#', $overview_html ),
	'overview_does_not_walk_posts' => array() === $walks,
	'overview_few_queries'    => count( $made ) <= 20,
	'overview_counts_are_numbers' => array() !== $counts && array() === array_filter( $counts, static fn( $count ): bool => ! is_int( $count ) || $count < 0 ),
	'overview_queries'        => count( $made ),
	'overview_counts'         => $counts,
	'overview_walks'          => $walks,
	'limit'                   => QueryLog::LIMIT,
	'plugin_queries'          => array(
		'post'     => $pages['post']['plugin'] ?? null,
		'category' => $pages['category']['plugin'] ?? null,
		'admin'    => $pages['admin']['plugin'] ?? null,
	),
	'plugin_ms'               => array(
		'post'     => $pages['post']['plugin_ms'] ?? null,
		'category' => $pages['category']['plugin_ms'] ?? null,
		'admin'    => $pages['admin']['plugin_ms'] ?? null,
	),
	'total_queries'           => array(
		'post'     => $pages['post']['total'] ?? null,
		'category' => $pages['category']['total'] ?? null,
		'admin'    => $pages['admin']['total'] ?? null,
	),
	'errors'                  => array_filter( array_map( static fn( array $page ): string => (string) ( $page['error'] ?? '' ), $pages ) ),
	'queries'                 => array_map( $callers, $pages ),
);

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
