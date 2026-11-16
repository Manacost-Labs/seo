<?php
/**
 * Проверка графа ссылок внутри тестового WordPress: таблица, обход, пересчёт при сохранении, отчёты.
 * Запускается после redirects-check.php (использует его редиректы). Отдаёт JSON.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\Reports\LinksReport;
use IndexPilot\Links\Scanner;
use IndexPilot\Links\Schema;
use IndexPilot\Links\WpLinkStore;
use IndexPilot\Plugin;

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

$plugin  = Plugin::instance();
$scanner = $plugin->link_scanner();
$reports = $plugin->link_reports();
$store   = new WpLinkStore( $wpdb );
$table   = Schema::table( $wpdb );
$id      = static fn( string $slug ): int => (int) get_page_by_path( $slug, OBJECT, 'post' )->ID;

$table_exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

// Тексты со ссылками: на обычную запись, на запись, которая станет черновиком, на адрес с редиректом и наружу.
$draft_target = $id( 'gajd-9' );
wp_update_post(
	array(
		'ID'           => $id( 'gajd-8' ),
		'post_content' => '<p><a href="/gajd-4/">четвёртый</a> <a href="/gajd-9/">девятый</a> <a href="/staryj-slag/">старый</a> <a href="https://hsreplay.net/">статистика</a> <a href="/net-takoj/">нет такой</a></p>',
	)
);
wp_update_post(
	array(
		'ID'           => $id( 'gajd-11' ),
		'post_content' => '<p><a href="' . esc_url( home_url( '/gajd-4/?from=11' ) ) . '">четвёртый</a></p>',
	)
);

$rows_after_save = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', $table, $id( 'gajd-8' ) ) );
$incoming        = $store->incoming( array( $id( 'gajd-4' ), $id( 'gajd-9' ), $id( 'gajd-1' ) ) );

// Полный обход пачками с нуля.
delete_option( Scanner::OPTION );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE 1 = %d', $table, 1 ) );

$batches = 0;
do {
	$result = $scanner->scan_batch( 0 === $batches );
	++$batches;
} while ( ! $result['done'] && $batches < 20 );

$incoming_after_scan = $store->incoming( array( $id( 'gajd-4' ), $id( 'gajd-9' ) ) );

// Глубина записей: статическая главная → d1 → d2 → d3 → d4 (главный материал). Ссылки записей попадают в граф при сохранении.
$old_front = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ) );
$chain     = array();
for ( $step = 4; $step >= 1; --$step ) {
	$chain[ $step ] = (int) wp_insert_post(
		array(
			'post_title'   => 'Глубина ' . $step,
			'post_name'    => 'depth-check-' . $step,
			'post_status'  => 'publish',
			'post_content' => isset( $chain[ $step + 1 ] ) ? '<p><a href="' . esc_url( (string) get_permalink( $chain[ $step + 1 ] ) ) . '">дальше по теме</a></p>' : '<p>Конец цепочки.</p>',
		)
	);
}
update_post_meta( $chain[4], '_indexpilot_cornerstone', '1' );
$front_id = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Главная для проверки глубины',
		'post_status'  => 'publish',
		'post_content' => '<p><a href="' . esc_url( (string) get_permalink( $chain[1] ) ) . '">первая запись цепочки</a></p>',
	)
);
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front_id );

register_shutdown_function(
	static function () use ( $chain, $front_id, $old_front ): void {
		update_option( 'show_on_front', $old_front[0] );
		update_option( 'page_on_front', $old_front[1] );
		foreach ( array_merge( $chain, array( $front_id ) ) as $remove ) {
			wp_delete_post( $remove, true );
		}
	}
);

rest_get_server();
$depth_response = rest_do_request( new WP_REST_Request( 'POST', '/indexpilot/v1/links/depth' ) );
$depth          = ( new IndexPilot\Links\DepthMap( $wpdb, $scanner ) )->stored();

// Запись-цель уходит в черновики: ссылка на неё становится проблемой.
wp_update_post( array( 'ID' => $draft_target, 'post_status' => 'draft' ) );

$orphans = array_column( $reports->orphans( 'post' ), 'post_id' );
$broken  = array();
foreach ( $reports->broken() as $row ) {
	$broken[ (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ] = $row['problem'];
}

ob_start();
( new LinksReport( $scanner, $reports ) )->render();
$html = (string) ob_get_clean();

rest_get_server();
wp_set_current_user( 0 );
$anonymous_scan  = rest_do_request( new WP_REST_Request( 'POST', '/indexpilot/v1/links/scan' ) )->get_status();
$anonymous_check = rest_do_request( new WP_REST_Request( 'POST', '/indexpilot/v1/links/check' ) )->get_status();
$anonymous_depth = rest_do_request( new WP_REST_Request( 'POST', '/indexpilot/v1/links/depth' ) )->get_status();
wp_set_current_user( 1 );

// Снятая с публикации запись-источник пропадает из графа.
wp_update_post( array( 'ID' => $id( 'gajd-11' ), 'post_status' => 'draft' ) );
$incoming_after_unpublish = $store->incoming( array( $id( 'gajd-4' ) ) );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'table_exists'                 => $table_exists,
		'five_links_saved_on_save'     => 5 === $rows_after_save,
		// На запись 4 ссылаются записи 8 и 11 отсюда и запись 6 из проверки редиректов.
		'three_sources_link_to_post_4' => 3 === $incoming[ $id( 'gajd-4' ) ],
		'one_source_links_to_post_9'   => 1 === $incoming[ $id( 'gajd-9' ) ],
		'nobody_links_to_post_1'       => 0 === $incoming[ $id( 'gajd-1' ) ],
		'scan_finished'                => $result['done'] && $result['processed'] === $result['total'],
		'scan_gives_the_same_counts'   => 3 === $incoming_after_scan[ $id( 'gajd-4' ) ] && 1 === $incoming_after_scan[ $id( 'gajd-9' ) ],
		'linked_post_is_not_an_orphan' => ! in_array( $id( 'gajd-4' ), $orphans, true ),
		'unlinked_post_is_an_orphan'   => in_array( $id( 'gajd-1' ), $orphans, true ),
		'noindex_post_is_not_listed'   => ! in_array( $id( 'gajd-2' ), $orphans, true ),
		'redirected_post_is_not_listed' => ! in_array( $id( 'gajd-3' ), $orphans, true ),
		'link_to_redirect_reported'    => 'redirect' === ( $broken['/staryj-slag/'] ?? '' ),
		'link_to_draft_reported'       => 'unpublished' === ( $broken['/gajd-9/'] ?? '' ),
		'good_link_not_reported'       => ! isset( $broken['/gajd-4/'] ),
		'unknown_address_waits_check'  => in_array( 'https://127.0.0.1/net-takoj/', $reports->addresses_to_check(), true ),
		'page_has_buttons'             => str_contains( $html, 'data-indexpilot-batch="/indexpilot/v1/links/scan"' ) && str_contains( $html, 'data-indexpilot-batch="/indexpilot/v1/links/check"' ),
		'page_lists_orphan'            => str_contains( $html, 'Гайд номер 1' ) || str_contains( $html, 'Лучший маг' ),
		'page_explains_redirect'       => str_contains( $html, 'Замените ссылку на https://kolodahearthstone.com/guide-3/' ),
		'anonymous_cannot_scan'        => 401 === $anonymous_scan && 401 === $anonymous_check,
		'unpublished_source_leaves_graph' => 2 === $incoming_after_unpublish[ $id( 'gajd-4' ) ],
		'depth_counted_by_button'      => 200 === $depth_response->get_status() && true === ( $depth_response->get_data()['done'] ?? null ) && $depth['updated'] > 0,
		'depth_follows_the_chain'      => 1 === ( $depth['depths'][ $chain[1] ] ?? 0 ) && 3 === ( $depth['depths'][ $chain[3] ] ?? 0 ) && 4 === ( $depth['depths'][ $chain[4] ] ?? 0 ),
		'unlinked_post_is_unreachable' => in_array( $id( 'gajd-1' ), $depth['unreachable'], true ),
		'deep_cornerstone_goes_first'  => $chain[4] === ( IndexPilot\Links\DepthMap::deep( $depth['depths'], $depth['cornerstone'] )[0]['post_id'] ?? 0 ),
		'page_shows_depth'             => str_contains( $html, 'data-indexpilot-batch="/indexpilot/v1/links/depth"' ) && str_contains( $html, 'data-indexpilot-depth-row="' . $chain[4] . '"' ) && str_contains( $html, 'data-indexpilot-unreachable' ),
		'anonymous_cannot_count_depth' => 401 === $anonymous_depth,
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
