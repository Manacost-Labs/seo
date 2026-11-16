<?php
/**
 * Проверка своего менеджера редиректов, редиректов «сами собой», журнала 404 и переноса из Redirection
 * внутри тестового WordPress. Отдаёт JSON. Запускается после redirects-check.php: та создаёт таблицу Redirection.
 * Файл не входит в сборку плагина.
 */

declare( strict_types=1 );

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\NotFoundPage;
use IndexPilot\Admin\RedirectsPage;
use IndexPilot\Import\RedirectionImporter;
use IndexPilot\Plugin;
use IndexPilot\Redirects\AutoRedirect;
use IndexPilot\Redirects\Manager;
use IndexPilot\Redirects\Matcher;
use IndexPilot\Redirects\RedirectionAdapter;
use IndexPilot\Redirects\RedirectService;
use IndexPilot\Redirects\Schema;
use IndexPilot\Redirects\TargetChecker;
use IndexPilot\Redirects\WpNotFoundStore;
use IndexPilot\Redirects\WpStore;

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
Schema::maybe_install( $wpdb );

$store   = new WpStore( $wpdb );
$manager = $plugin->redirect_manager();
$out     = array();

$head = static function ( string $path ): array {
	$response = wp_remote_get(
		home_url( $path ),
		array(
			'redirection' => 0,
			'timeout'     => 60,
		)
	);

	return is_wp_error( $response )
		? array( 0, $response->get_error_message() )
		: array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'location' ) );
};

// Менеджер: цепочка схлопывается, петля запрещена.
$first = $manager->save(
	array(
		'source' => '/smoke-old/',
		'target' => '/smoke-mid/',
	)
);
$manager->save(
	array(
		'source' => '/smoke-mid/',
		'target' => '/gajd-4/',
	)
);
$loop = $manager->save(
	array(
		'source' => '/gajd-4/',
		'target' => '/smoke-old/',
	)
);

$out['manager'] = array(
	'saved'               => is_array( $first ),
	'chain_collapsed'     => '/gajd-4/' === ( $store->find( (int) $first['id'] )['target'] ?? '' ),
	'loop_rejected'       => Manager::ERROR_LOOP === $loop,
	'index_in_autoloaded' => 'on' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WpStore::INDEX_OPTION ) ) || 'yes' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WpStore::INDEX_OPTION ) ),
);

$manager->save(
	array(
		'source' => '^/smoke-pattern/(.+?)/?$',
		'regex'  => true,
		'target' => '/$1/',
	)
);
$manager->save(
	array(
		'source' => '/smoke-gone/',
		'code'   => 410,
	)
);

// Настоящие запросы к сайту.
list( $code, $location ) = $head( '/Smoke-Old?utm_source=vk' );
list( $pattern_code, $pattern_location ) = $head( '/smoke-pattern/gajd-4/' );
list( $gone_code ) = $head( '/smoke-gone/' );

$out['http'] = array(
	'case_slash_and_utm_redirect' => 301 === $code && str_ends_with( $location, '/gajd-4/?utm_source=vk' ),
	'pattern_redirect'            => 301 === $pattern_code && str_ends_with( $pattern_location, '/gajd-4/' ),
	'gone_answers_410'            => 410 === $gone_code,
	'hit_counted'                 => (int) ( $store->find( (int) $first['id'] )['hits'] ?? 0 ) >= 1,
	'details'                     => array( $code, $location, $pattern_code, $pattern_location, $gone_code ),
);

// Смена ярлыка рубрики: старый адрес отдаёт 301 на новый.
$term_id = (int) wp_insert_term( 'Смоук рубрика', 'category', array( 'slug' => 'smoke-staraya-rubrika' ) )['term_id'];
$old     = (string) get_term_link( $term_id, 'category' );
wp_update_term( $term_id, 'category', array( 'slug' => 'smoke-novaya-rubrika' ) );
$new = (string) get_term_link( $term_id, 'category' );

list( $term_code, $term_location ) = $head( (string) wp_parse_url( $old, PHP_URL_PATH ) );

// Смена ярлыка записи.
$post_id = wp_insert_post(
	array(
		'post_title'   => 'Смоук запись для смены адреса',
		'post_name'    => 'smoke-staryj-adres',
		'post_status'  => 'publish',
		'post_content' => 'Текст.',
	)
);
wp_update_post(
	array(
		'ID'        => $post_id,
		'post_name' => 'smoke-novyj-adres',
	)
);

list( $post_code, $post_location ) = $head( '/smoke-staryj-adres/' );

$out['auto'] = array(
	'term_slug_change_redirects' => 301 === $term_code && $term_location === $new && $old !== $new,
	'post_slug_change_redirects' => 301 === $post_code && str_ends_with( $post_location, '/smoke-novyj-adres/' ),
);

// Удаление опубликованной записи: уведомление с выбором цели.
$deleted = wp_insert_post(
	array(
		'post_title'    => 'Смоук удаляемая запись',
		'post_status'   => 'publish',
		'post_content'  => 'Текст.',
		'post_category' => array( (int) get_term_by( 'slug', 'gajdy', 'category' )->term_id ),
	)
);
wp_trash_post( $deleted );

$auto = new AutoRedirect( array( $plugin, 'redirect_manager' ) );
ob_start();
$auto->notice();
$notice = (string) ob_get_clean();

$out['deleted_post'] = array(
	'remembered'      => isset( $auto->pending()[ $deleted ] ),
	'notice_shown'    => str_contains( $notice, 'data-indexpilot-deleted-post="' . $deleted . '"' ) && str_contains( $notice, 'value="gone"' ),
	'target_proposed' => str_contains( $notice, '/gajdy/' ),
);
wp_untrash_post( $deleted );
$out['deleted_post']['forgotten_after_restore'] = ! isset( $auto->pending()[ $deleted ] );

// Журнал 404: шум не пишется, повторное обращение — одна строка со счётчиком.
$log = new WpNotFoundStore( $wpdb );
$log->clear();
$head( '/gajd-44/' );
$head( '/gajd-44/' );
$head( '/.env' );
$head( '/wp-login-smoke.php' );

$rows = $log->top( '', 50 );

ob_start();
( new NotFoundPage( $log, $wpdb ) )->render();
$log_html = (string) ob_get_clean();

$out['not_found'] = array(
	'one_row_with_two_hits' => array( '/gajd-44/' => 2 ) === array_column( $rows, 'hits', 'url' ),
	'noise_skipped'         => 1 === count( $rows ),
	'page_has_suggestion'   => str_contains( $log_html, 'data-indexpilot-suggestion' ),
	'page_has_create_link'  => str_contains( $log_html, 'page=' . RedirectsPage::SLUG ),
	'normal_page_not_logged' => ! str_contains( (string) wp_json_encode( $rows ), 'gajd-4/' ),
);

// Перенос из Redirection (таблицу создала redirects-check.php).
$adapter  = new RedirectionAdapter( $wpdb );
$imported = ( new RedirectionImporter( $store, $plugin->redirect_manager() ) )->import( $adapter->rows() );
$again    = ( new RedirectionImporter( $store, $plugin->redirect_manager() ) )->import( $adapter->rows() );
$pattern  = null;
foreach ( $store->all() as $rule ) {
	if ( '^/re/(.*)' === $rule['source'] ) {
		$pattern = $rule;
	}
}

$service = new RedirectService( $adapter, new TargetChecker(), (string) wp_parse_url( home_url(), PHP_URL_HOST ), $store );
$page    = new RedirectsPage( $store, array( $plugin, 'redirect_manager' ), $service, $log );
ob_start();
$page->render();
$rules_html = (string) ob_get_clean();

$out['import'] = array(
	'imported'              => $imported['imported'] >= 8,
	'repeat_adds_nothing'   => 0 === $again['imported'],
	'pattern_kept'          => true === ( $pattern['regex'] ?? null ),
	'disabled_kept'         => false === ( $store->find_by_source( Matcher::key( '/vyklyuchen/' ) )['enabled'] ?? null ),
	'error_became_410'      => 410 === ( $store->find_by_source( Matcher::key( '/oshibka/' ) )['code'] ?? null ),
	'loops_disabled'        => $imported['loops'] >= 2,
	'marked_in_report'      => str_contains( $rules_html, 'data-indexpilot-also-redirection' ),
	'service_reads_both'    => count( $service->all() ) >= count( $service->own() ) && count( $service->own() ) > 0,
	'counts'                => $imported,
);

// Перенесённые правила убираются: дальше проверки сайта рассчитаны на правила только из Redirection.
foreach ( $store->all() as $rule ) {
	if ( RedirectionImporter::NOTE === $rule['note'] || str_starts_with( (string) $rule['note'], 'петля' ) ) {
		$store->delete( (int) $rule['id'] );
	}
}
$store->rebuild_index();

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
