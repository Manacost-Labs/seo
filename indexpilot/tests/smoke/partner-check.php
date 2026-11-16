<?php
/**
 * Проверка связи между двумя сайтами внутри тестового WordPress: маршрут ключей и запрос ко «второму сайту».
 * Запросы наружу не уходят: «второй сайт» — этот же сайт, запрос перехватывается и выполняется на месте.
 * Отдаёт JSON. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\CrossSite\KeywordAccess;
use IndexPilot\CrossSite\PartnerClient;
use IndexPilot\Plugin;
use IndexPilot\Rest\KeywordsController;
use IndexPilot\Settings\Settings;

wp_set_current_user( 0 );

set_exception_handler(
	static function ( Throwable $error ): void {
		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$plugin   = Plugin::instance();
$key      = 'test-shared-key-0123456789abcdef';
$settings = new Settings(
	array(
		'general' => array(
			// Адрес уникален для каждого запуска: ответы второго сайта запоминаются, и повторный запуск иначе читал бы старые.
			'partner_site_url' => 'https://partner.example/run-' . wp_rand(),
			'partner_site_key' => $key,
		),
	)
);

// Маршрут с настроенным ключом (по умолчанию ключ пуст и маршрут закрыт для всех).
add_action(
	'rest_api_init',
	static function () use ( $settings, $plugin ): void {
		register_rest_route( 'indexpilot-test/v1', '/noop', array( 'methods' => 'GET', 'callback' => '__return_true', 'permission_callback' => '__return_true' ) );
	}
);
rest_get_server();

$default_route = static function ( string $given ) {
	$request = new WP_REST_Request( 'GET', '/indexpilot/v1/keywords' );
	$request->set_param( 'keyword', 'колода мага' );
	$request->set_header( KeywordAccess::HEADER, $given );

	return rest_do_request( $request )->get_status();
};

$controller = new KeywordsController( $settings, $plugin->analyzer()->matcher() );
$find       = static function ( string $keyword ) use ( $controller ): array {
	$request = new WP_REST_Request( 'GET', '/indexpilot/v1/keywords' );
	$request->set_param( 'keyword', $keyword );

	return (array) $controller->find( $request )->get_data();
};

// У записи gajd-1 основной ключ «колода мага» (задан при создании сайта); тот же ключ может стоять
// и у записи из проверки панели, поэтому проверяется наличие gajd-1 среди найденных.
$has_first = static fn( array $rows ): bool => array() !== array_filter( $rows, static fn( array $row ): bool => str_contains( (string) $row['url'], '/gajd-1/' ) );
$same_form  = $find( 'колода мага' );
$other_form = $find( 'Колоды мага' );
$other      = $find( 'колода жреца' );

// «Второй сайт»: запрос перехватывается и обслуживается тем же контроллером.
$requests = 0;
$online   = true;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests, &$online, $find, $key ) {
		if ( ! str_starts_with( (string) $url, 'https://partner.example/' ) ) {
			return $pre;
		}

		++$requests;

		if ( ! $online ) {
			return new WP_Error( 'http_request_failed', 'offline' );
		}

		parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $query );
		$allowed = KeywordAccess::allows( $key, (string) ( $args['headers'][ KeywordAccess::HEADER ] ?? '' ) );

		return array(
			'headers'  => array(),
			'body'     => $allowed ? wp_json_encode( $find( rawurldecode( (string) $query['keyword'] ) ) ) : '{}',
			'response' => array( 'code' => $allowed ? 200 : 403, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$client = new PartnerClient( $settings );

$not_cached   = $client->lookup( 'колоды мага', false );
$found        = $client->lookup( 'колоды мага', true );
$from_cache   = $client->lookup( 'колоды мага', false );
$after_first  = $requests;
$client->lookup( 'колоды мага', true );
$free         = $client->lookup( 'колода паладина', true );

$online  = false;
$offline = $client->lookup( 'колода шамана', true );

$unconfigured = ( new PartnerClient( new Settings() ) )->lookup( 'колода мага', true );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'route_closed_without_key'      => 403 === $default_route( '' ) || 401 === $default_route( '' ),
		'route_closed_when_not_configured' => 200 !== $default_route( $key ),
		'exact_keyword_found'           => $has_first( $same_form ),
		'other_word_form_found'         => $has_first( $other_form ) && count( $other_form ) === count( $same_form ),
		'other_keyword_not_found'       => array() === $other,
		'no_request_from_live_preview'  => null === $not_cached,
		'partner_page_returned'         => is_array( $found ) && $has_first( $found ),
		'answer_is_cached'              => $found === $from_cache && 1 === $after_first && 1 === ( $requests - 2 ),
		'free_keyword_gives_empty_list' => array() === $free,
		'offline_partner_gives_null'    => null === $offline,
		'unconfigured_gives_null'       => null === $unconfigured,
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
