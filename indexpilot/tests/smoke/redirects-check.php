<?php
/**
 * Проверка контроля редиректов внутри тестового WordPress.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/redirects-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина. Создаёт таблицу плагина Redirection с тестовыми редиректами.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\PostListColumns;
use IndexPilot\Admin\Reports\RedirectsReport;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Redirects\TargetChecker;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

global $wpdb;

$plugin = Plugin::instance();
$out    = array();

// Та же структура, что у плагина Redirection (нужные столбцы).
$table = $wpdb->prefix . 'redirection_items';
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} ( id int(11) unsigned NOT NULL AUTO_INCREMENT, url mediumtext NOT NULL, match_url varchar(2000) DEFAULT NULL, regex int(11) unsigned NOT NULL DEFAULT 0, last_count int(10) unsigned NOT NULL DEFAULT 0, status varchar(10) NOT NULL DEFAULT 'enabled', action_type varchar(20) NOT NULL, action_code int(11) unsigned NOT NULL, action_data mediumtext, match_type varchar(20) NOT NULL, PRIMARY KEY (id) )" );
$wpdb->query( "DELETE FROM {$table}" );

$rows = array(
	// Опубликованная запись под редиректом, цель — другой домен, который сам перенаправляет дальше.
	array( 'url' => '/gajd-3/', 'action_data' => 'https://kolodahearthstone.ru/guide-3/', 'last_count' => 900 ),
	// Цепочка внутри сайта: старый слаг → новый слаг → другой домен.
	array( 'url' => '/staryj-slag/', 'action_data' => '/gajd-3/', 'last_count' => 50 ),
	// Прямой редирект — не проблема.
	array( 'url' => '/pryamoj/', 'action_data' => 'https://kolodahearthstone.com/direct/', 'last_count' => 10 ),
	// Петля.
	array( 'url' => '/petlya-a/', 'action_data' => '/petlya-b/', 'last_count' => 1 ),
	array( 'url' => '/petlya-b/', 'action_data' => '/petlya-a/', 'last_count' => 1 ),
	// Цель не открывается.
	array( 'url' => '/mertvaya-cel/', 'action_data' => 'https://kolodahearthstone.com/gone/', 'last_count' => 5 ),
	// Не учитываются: регулярное выражение, выключенный, ответ без перехода.
	array( 'url' => '^/re/(.*)', 'action_data' => '/x/$1', 'regex' => 1 ),
	array( 'url' => '/vyklyuchen/', 'action_data' => '/gajd-4/', 'status' => 'disabled' ),
	array( 'url' => '/oshibka/', 'action_data' => '', 'action_type' => 'error', 'action_code' => 410 ),
);
foreach ( $rows as $row ) {
	$wpdb->insert( $table, $row + array( 'regex' => 0, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'url', 'last_count' => 0 ) );
}

// Результаты проверки целей подставляются напрямую: внешних запросов в тесте нет.
delete_option( RedirectedPosts::OPTION );
update_option(
	TargetChecker::OPTION,
	array(
		'https://kolodahearthstone.ru/guide-3/' => array( 'status' => 301, 'location' => 'https://kolodahearthstone.com/guide-3/', 'checked' => time() ),
		'https://kolodahearthstone.com/guide-3/' => array( 'status' => 200, 'location' => '', 'checked' => time() ),
		'https://kolodahearthstone.com/gone/'   => array( 'status' => 404, 'location' => '', 'checked' => time() ),
	),
	false
);

$service = $plugin->redirects();
$posts   = $plugin->redirected_posts();
$report  = new RedirectsReport( $service, $posts );
$data    = $report->data();

$post_id = static fn( string $slug ): int => (int) get_page_by_path( $slug, OBJECT, 'post' )->ID;

$chains = array();
foreach ( $data['chains'] as $chain ) {
	$chains[ $chain['source'] ] = array( $chain['hops'], $chain['loop'], $chain['final'] );
}

$out['adapter'] = array(
	'available'   => $service->available(),
	'six_counted' => 6 === $data['total'],
	'two_skipped' => 2 === $data['skipped'],
);

$out['report'] = array(
	'chain_through_other_domain' => array( 2, false, 'https://kolodahearthstone.com/guide-3/' ) === ( $chains['/gajd-3/'] ?? null ),
	'chain_of_three_hops'    => array( 3, false, 'https://kolodahearthstone.com/guide-3/' ) === ( $chains['/staryj-slag/'] ?? null ),
	'loops_found'            => true === ( $chains['/petlya-a/'][1] ?? null ) && true === ( $chains['/petlya-b/'][1] ?? null ),
	'only_four_problems'     => 4 === count( $chains ),
	'direct_is_not_a_chain'  => ! isset( $chains['/pryamoj/'] ),
	'sorted_by_hits'         => '/gajd-3/' === ( $data['chains'][0]['source'] ?? '' ),
	'dead_target_found'      => array( '/mertvaya-cel/' => 404 ) === array_column( $data['dead'], 'status', 'source' ),
	'redirected_post_found'  => array( 'Гайд номер 3' => 'https://kolodahearthstone.com/guide-3/' ) === array_column( $data['posts'], 'final', 'title' ),
	'pending_addresses'      => $service->pending(),
);

ob_start();
$report->render();
$html = (string) ob_get_clean();

$out['report_page'] = array(
	'has_button'       => str_contains( $html, 'data-indexpilot-check' ),
	'suggests_final'   => str_contains( $html, 'Заменить цель на https://kolodahearthstone.com/guide-3/' ),
	'mentions_loop'    => str_contains( $html, 'Петля' ),
	'lists_dead'       => str_contains( $html, '/mertvaya-cel/' ) && str_contains( $html, '404' ),
	'lists_post'       => str_contains( $html, 'Гайд номер 3' ),
);

// Отметка в списке записей.
ob_start();
( new PostListColumns( $plugin->resolver(), $posts ) )->render( 'indexpilot', $post_id( 'gajd-3' ) );
$cell = (string) ob_get_clean();
ob_start();
( new PostListColumns( $plugin->resolver(), $posts ) )->render( 'indexpilot', $post_id( 'gajd-4' ) );
$other = (string) ob_get_clean();

$out['list_column'] = array(
	'flag_shown'       => str_contains( $cell, 'data-flag="redirect"' ) && str_contains( $cell, 'редирект → kolodahearthstone.com' ),
	'no_flag_for_other' => ! str_contains( $other, 'data-flag="redirect"' ),
);

// Анализ: ссылка на адрес с редиректом и запись под редиректом.
wp_update_post(
	array(
		'ID'           => $post_id( 'gajd-6' ),
		'post_content' => '<p>Смотрите <a href="/staryj-slag/">старый гайд</a> и <a href="/gajd-4/">обычный гайд</a>.</p>',
	)
);
wp_update_post( array( 'ID' => $post_id( 'gajd-3' ) ) );

$checks = static function ( int $id ): array {
	$analysis = json_decode( (string) get_post_meta( $id, MetaKeys::ANALYSIS, true ), true );
	$found    = array();

	foreach ( (array) ( $analysis['results'] ?? array() ) as $result ) {
		if ( in_array( $result['id'], array( 'redirected_links', 'redirected_page' ), true ) ) {
			$found[ $result['id'] ] = array( $result['status'], $result['message'] );
		}
	}

	return $found;
};

$bad_link   = $checks( $post_id( 'gajd-6' ) );
$redirected = $checks( $post_id( 'gajd-3' ) );

$out['analysis'] = array(
	'link_to_redirect_reported'  => 'bad' === ( $bad_link['redirected_links'][0] ?? '' ) && str_contains( (string) ( $bad_link['redirected_links'][1] ?? '' ), '/staryj-slag/ → https://kolodahearthstone.com/guide-3/' ),
	'ordinary_post_not_flagged'  => ! isset( $bad_link['redirected_page'] ),
	'redirected_page_reported'   => 'bad' === ( $redirected['redirected_page'][0] ?? '' ),
	'clean_links_are_good'       => 'good' === ( $redirected['redirected_links'][0] ?? '' ),
);

// REST: права.
rest_get_server();
wp_set_current_user( 0 );
$anonymous = rest_do_request( new WP_REST_Request( 'POST', '/indexpilot/v1/redirects/check' ) )->get_status();
wp_set_current_user( 1 );

$out['rest'] = array( 'anonymous_rejected' => 401 === $anonymous );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
