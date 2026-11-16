<?php
/**
 * Проверка данных поиска внутри тестового WordPress: подключение Google Search Console и Яндекс Вебмастера,
 * загрузка в таблицу, вкладка «Запросы», запросы рубрики, отчёты «Запросы», «Карта тем», «Без жизни»,
 * проверка индексации, мост между сайтами и дела в «Обзоре». Отдаёт JSON.
 * Сеть подменена фильтром pre_http_request: ответы Google и Яндекса берутся из файлов-образцов
 * tests/php/SearchData/fixtures, ключ служебной учётной записи создаётся на месте и никуда не уходит.
 * Файл не входит в сборку плагина.
 */

declare( strict_types=1 );

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\ClassicMetabox;
use IndexPilot\Admin\QueriesPanel;
use IndexPilot\Admin\Reports\QueriesReport;
use IndexPilot\Admin\Reports\StaleReport;
use IndexPilot\Admin\Reports\TopicsReport;
use IndexPilot\Admin\SearchDataPage;
use IndexPilot\CrossSite\BridgeSync;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Overview\Facts;
use IndexPilot\Plugin;
use IndexPilot\Promo\Bridge;
use IndexPilot\SearchData\Module;
use IndexPilot\SearchData\WpQueryStore;
use IndexPilot\Settings\Settings;

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

$plugin   = Plugin::instance();
$home     = untrailingslashit( home_url() );
$fixtures = dirname( __DIR__ ) . '/php/SearchData/fixtures/';
$calls    = array();
$partner  = 'https://partner.example';

// Подменённая сеть: Google, Яндекс и «второй сайт» отвечают из образцов, остальное не трогаем.
add_filter(
	'pre_http_request',
	static function ( $result, $args, $url ) use ( $fixtures, $home, $partner, &$calls ) {
		$calls[] = $url;
		$body    = (string) ( $args['body'] ?? '' );

		if ( str_starts_with( $url, $partner ) ) {
			if ( str_contains( $url, 'down' ) ) {
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 500, 'message' => 'Error' ), 'cookies' => array(), 'filename' => null );
			}

			$answer = array(
				'keyword'   => array( array( 'title' => 'Полный разбор колоды жреца', 'url' => $partner . '/razbor-zhreca/' ) ),
				'archetype' => null,
			);

			return array( 'headers' => array(), 'body' => wp_json_encode( $answer ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}

		$file = null;

		if ( str_contains( $url, 'oauth2.googleapis.com' ) ) {
			$file = 'google-token.json';
		} elseif ( str_contains( $url, 'urlInspection' ) ) {
			$file = str_contains( $body, 'staraya' ) ? 'inspection-not-indexed.json' : 'inspection-indexed.json';
		} elseif ( str_contains( $url, 'searchAnalytics' ) ) {
			$file = str_contains( $body, '"query"' ) ? 'google-queries.json' : 'google-pages.json';
		} elseif ( str_contains( $url, 'api.webmaster.yandex.net' ) ) {
			$file = str_contains( $url, 'search-queries/popular' ) ? 'yandex-popular.json' : ( str_ends_with( (string) strtok( $url, '?' ), '/hosts' ) ? 'yandex-hosts.json' : 'yandex-user.json' );
		}

		if ( null === $file ) {
			return $result;
		}

		return array(
			'headers'  => array(),
			'body'     => str_replace( '{HOME}', $home, (string) file_get_contents( $fixtures . $file ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Записи под адреса из образцов.
$guides = get_term_by( 'slug', 'gajdy', 'category' );
$make   = static function ( string $slug, string $title, string $date = '' ) use ( $guides ): int {
	$found = get_page_by_path( $slug, OBJECT, 'post' );

	if ( $found instanceof WP_Post ) {
		return (int) $found->ID;
	}

	$args = array(
		'post_title'    => $title,
		'post_name'     => $slug,
		'post_status'   => 'publish',
		'post_content'  => '<p>' . $title . '. Текст записи для проверки данных поиска.</p>',
		'post_category' => array( (int) $guides->term_id ),
	);

	if ( '' !== $date ) {
		$args['post_date']     = $date;
		$args['post_date_gmt'] = $date;
	}

	return (int) wp_insert_post( $args );
};

$guide = $make( 'gajd-zhreca', 'Гайд на жреца' );
$deck  = $make( 'koloda-zhreca', 'Колода жреца' );
$old   = $make( 'staraya-novost', 'Старая новость о турнире', gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) );
update_post_meta( $old, MetaKeys::CORNERSTONE, '1' );
// Повторный запуск на том же сайте начинает с чистого листа.
delete_post_meta( $old, \IndexPilot\SearchData\WpSite::KEEP_META );
delete_option( Module::INSPECTIONS );

// Доступы: ключ служебной учётной записи создаётся здесь же.
$config = array( 'config' => dirname( __DIR__ ) . '/php/SearchData/openssl.cnf' );
$pkey   = openssl_pkey_new( $config + array( 'private_key_bits' => 2048 ) );
openssl_pkey_export( $pkey, $pem, null, $config );
$key_json = (string) wp_json_encode(
	array(
		'type'         => 'service_account',
		'client_email' => 'robot@test-project.iam.gserviceaccount.com',
		'private_key'  => $pem,
	)
);

$module      = new Module( $plugin );
$credentials = $module->credentials();
$bad_key     = '';

try {
	$credentials->save_google_key( '{"type":"authorized_user"}' );
} catch ( \IndexPilot\SearchData\SearchDataException $error ) {
	$bad_key = $error->getMessage();
}

$email = $credentials->save_google_key( $key_json );
$credentials->set_google_site( home_url( '/' ) );
$credentials->set_yandex_token( 'test-token-123' );
$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", \IndexPilot\SearchData\Credentials::OPTION ) );

$module->schedule();
$check = $module->check();
$state = $module->start_sync( 10 );
$store = $module->store();
$rows  = $store->report_rows();

$resync       = $module->start_sync( 10 );
$resync_count = $store->count();

$matched = static function ( string $query, string $type ) use ( $rows ): array {
	return array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['object_id'], array_filter( $rows, static fn( array $row ): bool => $row['query'] === $query && $row['object_type'] === $type && 'current' === $row['period'] ) ) ) );
};

// Вкладка «Запросы» в настоящей панели записи.
$panel = new QueriesPanel( $module );
$panel->register();
$metabox = new ClassicMetabox( $plugin->resolver(), $plugin->settings(), $plugin->variables() );
ob_start();
$metabox->render( get_post( $guide ) );
$metabox_html = (string) ob_get_clean();
$tabs_order   = array();
preg_match_all( '/data-tab="([a-z]+)"/', $metabox_html, $tabs_order );

// Форма рубрики.
ob_start();
$panel->render_term( $guides, 'category' );
$term_html = (string) ob_get_clean();

// Отчёты.
$render = static function ( object $page ): string {
	ob_start();
	$page->render();

	return (string) ob_get_clean();
};

$inspected    = $module->inspect_batch( 10 );
$inspections  = $module->inspections();
$report_html  = $render( new QueriesReport( $module ) );
$topics_html  = $render( new TopicsReport( $module ) );
$stale_page   = new StaleReport( $module );
$stale_before = $render( $stale_page );

$_POST['indexpilot_stale_action'] = 'keep';
$_POST['post_id']                 = (string) $old;
$_POST['_wpnonce']                = wp_create_nonce( 'indexpilot_stale' );
$_REQUEST['_wpnonce']             = $_POST['_wpnonce'];
$stale_page->handle();
$stale_after = $render( $stale_page );
unset( $_POST['indexpilot_stale_action'], $_POST['post_id'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

$settings_html = $render( new SearchDataPage( $module ) );
$counts        = ( new Facts( $wpdb, $plugin->audit(), $plugin->link_scanner(), $plugin->redirects() ) )->counts();

// Мост между сайтами: второй сайт настроен, ответы записей запрашиваются пачкой.
$saved_settings = get_option( Settings::OPTION, array() );
$with_partner   = is_array( $saved_settings ) ? $saved_settings : array();
$with_partner['general']['partner_site_url'] = $partner;
$with_partner['general']['partner_site_key'] = 'test-shared-key-0123456789abcdef';
update_option( Settings::OPTION, $with_partner );
( new ReflectionProperty( Plugin::class, 'settings' ) )->setValue( $plugin, null );

update_post_meta( $guide, MetaKeys::FOCUS_KEYWORD, 'колода жреца' );
update_option( BridgeSync::CURSOR, $guide - 1, false );
$bridge = new BridgeSync( $plugin );
$batch  = $bridge->refresh_batch( 1 );
$meta   = get_post_meta( $guide, Bridge::META, true );

// Блок на странице записи: только из меты, без запросов к базе и сети.
$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $guide ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();
$calls_before   = count( $calls );
$queries_before = $wpdb->num_queries;
$started        = microtime( true );
$content        = $bridge->append( '<p>Текст</p>' );
$bridge_ms      = ( microtime( true ) - $started ) * 1000;
$bridge_queries = $wpdb->num_queries - $queries_before;
$bridge_calls   = count( $calls ) - $calls_before;

// Второй сайт недоступен: блок не выводится.
$with_partner['general']['partner_site_url'] = $partner . '/down';
update_option( Settings::OPTION, $with_partner );
( new ReflectionProperty( Plugin::class, 'settings' ) )->setValue( $plugin, null );
update_option( BridgeSync::CURSOR, $guide - 1, false );
$down_batch = $bridge->refresh_batch( 1 );
clean_post_cache( $guide );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $guide ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();
$down_content = $bridge->append( '<p>Текст</p>' );
wp_reset_postdata();

// Возвращаем сайт как было: настройки, без доступов и без меты моста.
update_option( Settings::OPTION, $saved_settings );
( new ReflectionProperty( Plugin::class, 'settings' ) )->setValue( $plugin, null );
delete_post_meta( $guide, Bridge::META );
delete_post_meta( $guide, MetaKeys::FOCUS_KEYWORD );
delete_option( BridgeSync::CURSOR );
$credentials->remove_google_key();
$credentials->set_yandex_token( '' );
$off_html = '';
ob_start();
( new QueriesPanel( $module ) )->render_post( get_post( $deck ) );
$off_html = (string) ob_get_clean();

$out = array(
	'bad_key_explained'          => str_contains( $bad_key, 'служебной учётной записи' ),
	'key_saved_without_autoload' => 'robot@test-project.iam.gserviceaccount.com' === $email && in_array( $autoload, array( 'no', 'off' ), true ),
	'check_google_ok'            => ! empty( $check['google']['ok'] ),
	'check_yandex_ok'            => ! empty( $check['yandex']['ok'] ),
	'sync_done'                  => ! empty( $state['done'] ) && array() === $state['errors'],
	'sync_rows'                  => 18 === $store->count(),
	'post_matched'               => array( $guide, $deck ) === $matched( 'колода жреца', 'post' ) || array( $deck, $guide ) === $matched( 'колода жреца', 'post' ),
	'term_matched'               => array( (int) $guides->term_id ) === $matched( 'гайды hearthstone', 'term' ),
	'home_matched'               => array( 0 ) === $matched( 'hs manacost', 'home' ),
	'resync_does_not_double'     => ! empty( $resync['done'] ) && 18 === $resync_count,
	'queries_tab_between'        => array_search( 'queries', $tabs_order[1], true ) === array_search( 'analysis', $tabs_order[1], true ) + 1 && array_search( 'social', $tabs_order[1], true ) === array_search( 'queries', $tabs_order[1], true ) + 1,
	'queries_tab_rows'           => str_contains( $metabox_html, 'data-indexpilot-query-row' ) && str_contains( $metabox_html, 'data-indexpilot-use-query="колода жреца"' ),
	'queries_tab_hint'           => str_contains( $metabox_html, 'data-indexpilot-query-hint="missing_in_title"' ),
	'queries_tab_help'           => 1 === preg_match( '/data-indexpilot-queries>.*?indexpilot-help/s', $metabox_html ),
	'term_queries'               => str_contains( $term_html, 'гайды hearthstone' ) && str_contains( $term_html, 'data-indexpilot-use-query=' ),
	'report_near_top'            => str_contains( $report_html, 'data-indexpilot-row="near"' ),
	'report_cannibal_pair'       => 1 === preg_match( '#data-indexpilot-row="cannibal"><td>колода жреца</td>#u', $report_html ),
	'report_without_page'        => 1 === preg_match( '#data-indexpilot-row="topic"><td>муллиган жреца</td>#u', $report_html ),
	'inspection_checked'         => $inspected['checked'] >= 1 && '' === $inspected['error'],
	'inspection_reason'          => str_contains( (string) ( $inspections[ (string) get_permalink( $old ) ]['reason'] ?? '' ), 'просканировал, но не добавил' ),
	'report_not_indexed'         => str_contains( $report_html, 'data-indexpilot-row="not-indexed"' ),
	'topics_mulligan_gap'        => str_contains( $topics_html, 'data-indexpilot-gap="mulligan"' ),
	'stale_listed'               => str_contains( $stale_before, 'data-indexpilot-stale-button="keep"' ) && str_contains( $stale_before, 'Старая новость о турнире' ),
	'stale_keep_removes'         => ! str_contains( $stale_after, 'Старая новость о турнире' ),
	'settings_hide_secrets'      => str_contains( $settings_html, 'robot@test-project.iam.gserviceaccount.com' ) && ! str_contains( $settings_html, 'PRIVATE KEY' ) && ! str_contains( $settings_html, 'test-token-123' ) && str_contains( $settings_html, 'type="password"' ),
	'overview_counts'            => ( $counts['queries_near_top'] ?? 0 ) >= 1 && ( $counts['queries_cannibal'] ?? 0 ) >= 1,
	'summary_autoloaded'         => in_array( (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WpQueryStore::SUMMARY ) ), array( 'yes', 'on', 'auto-on', 'auto' ), true ),
	'bridge_saved'               => 1 === $batch['checked'] && is_array( $meta ) && false === $meta['failed'] && 1 === count( $meta['items'] ),
	'bridge_block'               => str_contains( $content, 'class="indexpilot-bridge"' ) && str_contains( $content, 'Подробный разбор на partner.example' ),
	'bridge_no_queries_no_net'   => 0 === $bridge_queries && 0 === $bridge_calls && $bridge_ms < 50,
	'bridge_down_no_block'       => 1 === $down_batch['failed'] && '<p>Текст</p>' === $down_content,
	'not_connected_message'      => str_contains( $off_html, 'data-indexpilot-queries-off' ),
	'debug'                      => array(
		'state'   => $state,
		'check'   => $check,
		'tabs'    => $tabs_order[1],
		'counts'  => array_intersect_key( $counts, array_flip( array( 'queries_near_top', 'queries_cannibal' ) ) ),
		'bridge'  => array( $bridge_queries, $bridge_calls, round( $bridge_ms, 2 ) ),
		'inspect' => $inspected,
		'resync'  => array( $resync, $resync_count ),
	),
);

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
