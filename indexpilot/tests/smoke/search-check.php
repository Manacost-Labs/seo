<?php
/**
 * Проверка страницы «Поисковики» и ручной работы с IndexNow внутри тестового WordPress. Отдаёт JSON.
 * Запросы к поисковикам перехватываются и наружу не уходят. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\SearchEnginesPage;
use IndexPilot\IndexNow\Key;
use IndexPilot\IndexNow\Log;
use IndexPilot\IndexNow\Notifier;
use IndexPilot\IndexNow\Queue;
use IndexPilot\IndexNow\Sender;
use IndexPilot\Plugin;
use IndexPilot\Rest\IndexNowController;
use IndexPilot\Settings\Settings;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$plugin   = Plugin::instance();
$requests = array();
$answer   = 200;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests, &$answer ) {
		if ( ! str_starts_with( (string) $url, Sender::ENDPOINT ) ) {
			return $pre;
		}

		$requests[] = json_decode( (string) $args['body'], true );

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => $answer, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

delete_option( Queue::OPTION );
delete_option( Log::OPTION );
wp_clear_scheduled_hook( Notifier::HOOK );

$enabled_settings = new Settings(
	array(
		'general' => array(
			'indexnow_enabled' => true,
			'verify_yandex'    => '1a2b3c',
		),
	)
);
$notifier = new Notifier( new Queue(), new Sender(), new Key(), $plugin->resolver(), $enabled_settings );
$notifier->register();

$capture = static function ( callable $callback ): string {
	ob_start();
	$callback();

	return (string) ob_get_clean();
};

// Страница при выключенном и включённом IndexNow.
$page_off = $capture( static fn() => ( new SearchEnginesPage( $plugin->settings(), $plugin->indexnow() ) )->render() );
$page     = new SearchEnginesPage( $enabled_settings, $notifier );

// Маршрут: по умолчанию IndexNow выключен.
rest_get_server();
$call = static function ( array $params ): int {
	$request = new WP_REST_Request( 'POST', '/indexpilot/v1/indexnow' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request )->get_status();
};

$disabled_status = $call( array( 'action' => 'send_queue' ) );
wp_set_current_user( 0 );
$anonymous_status = $call( array( 'action' => 'send_queue' ) );
wp_set_current_user( 1 );

// Тот же контроллер с включённым IndexNow.
$controller = new IndexNowController( $notifier, $plugin->sitemap() );
$run        = static function ( array $params ) use ( $controller ): array {
	$request = new WP_REST_Request( 'POST', '/indexpilot/v1/indexnow' );
	foreach ( $params + array( 'urls' => '', 'step' => 0 ) as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return (array) $controller->run( $request )->get_data();
};

$submitted = $run( array( 'action' => 'submit', 'urls' => home_url( '/gajd-4/' ) . "\nhttps://other.example/x/\n" . home_url( '/gajd-1/' ) ) );
$manual    = $requests[ count( $requests ) - 1 ]['urlList'] ?? array();

$step  = 0;
$total = 0;
$files = 0;
do {
	$result = $run( array( 'action' => 'submit_all', 'step' => $step ) );
	$step   = (int) $result['step'];
	$total += (int) $result['sent'];
	$files  = (int) $result['files'];
} while ( ! $result['done'] && $step < 50 );

$answer  = 403;
$refused = $run( array( 'action' => 'submit', 'urls' => home_url( '/gajd-4/' ) ) );
$answer  = 200;

// Новая запись ставит в очередь себя, главную страницу и архив своей рубрики; правка термина — архив термина.
delete_option( Queue::OPTION );
$category = (int) get_term_by( 'slug', 'gajdy', 'category' )->term_id;
$new_post = (int) wp_insert_post(
	array(
		'post_title'    => 'Запись для очереди',
		'post_name'     => 'zapis-dlya-ocheredi',
		'post_content'  => '<p>Текст.</p>',
		'post_status'   => 'publish',
		'post_author'   => 1,
		'post_category' => array( $category ),
	)
);
$after_publish = array_keys( (array) get_option( Queue::OPTION, array() ) );

delete_option( Queue::OPTION );
wp_update_term( $category, 'category', array( 'description' => 'Все гайды по Hearthstone: колоды, архетипы и разборы матчапов.' ) );
$after_term = array_keys( (array) get_option( Queue::OPTION, array() ) );

$queued = $run( array( 'action' => 'send_queue' ) );

$page_on = $capture( static fn() => $page->render() );
$actions = $page->row_actions( array(), get_post( $new_post ) );
$draft   = $page->row_actions( array(), get_post( (int) wp_insert_post( array( 'post_title' => 'Черновик', 'post_status' => 'draft' ) ) ) );

wp_delete_post( $new_post, true );
delete_option( Queue::OPTION );
wp_clear_scheduled_hook( Notifier::HOOK );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'page_lists_engines'         => str_contains( $page_off, 'data-indexpilot-engine="google"' ) && str_contains( $page_off, 'data-indexpilot-engine="yandex"' ) && str_contains( $page_off, 'data-indexpilot-engine="bing"' ),
		'page_has_cabinet_links'     => str_contains( $page_off, 'https://search.google.com/search-console?resource_id=' ) && str_contains( $page_off, 'https://webmaster.yandex.ru/site/' ) && str_contains( $page_off, '/indexing/reindex/' ),
		'page_has_addresses'         => str_contains( $page_off, home_url( '/sitemap.xml' ) ) && str_contains( $page_off, home_url( '/robots.txt' ) ),
		'off_by_default'             => str_contains( $page_off, 'data-indexpilot-indexnow-state="off"' ) && str_contains( $page_off, 'Код не задан.' ),
		'on_when_enabled'            => str_contains( $page_on, 'data-indexpilot-indexnow-state="on"' ) && str_contains( $page_on, 'Код задан' ),
		'page_shows_log'             => str_contains( $page_on, 'data-indexpilot-log' ) && str_contains( $page_on, 'Принято (200)' ) && str_contains( $page_on, 'Отказ (403)' ),
		'route_refuses_when_off'     => 409 === $disabled_status,
		'anonymous_rejected'         => 401 === $anonymous_status,
		'manual_submit_own_urls_only' => 2 === $submitted['sent'] && true === $submitted['ok'] && array( home_url( '/gajd-4/' ), home_url( '/gajd-1/' ) ) === $manual,
		'submit_all_walks_the_sitemap' => $result['done'] && $files >= 4 && $total >= 10,
		'refusal_is_reported'        => false === $refused['ok'] && 403 === $refused['code'],
		'new_post_queues_itself_home_and_archive' => in_array( home_url( '/zapis-dlya-ocheredi/' ), $after_publish, true ) && in_array( home_url( '/' ), $after_publish, true ) && in_array( get_term_link( $category, 'category' ), $after_publish, true ),
		'edited_term_is_queued'      => array( get_term_link( $category, 'category' ) ) === $after_term,
		'queue_is_sent_on_demand'    => 0 === $queued['queue'],
		'log_is_limited'             => count( ( new Log() )->entries() ) <= Log::MAX_ENTRIES && count( ( new Log() )->entries() ) >= 3,
		'row_links_for_published'    => isset( $actions['indexpilot_google'], $actions['indexpilot_yandex'] ) && str_contains( $actions['indexpilot_yandex'], 'yandex.ru/search/?text=url%3A' ),
		'no_row_links_for_draft'     => array() === $draft,
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
