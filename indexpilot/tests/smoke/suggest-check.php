<?php
/**
 * Проверка подсказок ссылок внутри тестового WordPress. Отдаёт JSON.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Links\SuggestionIndex;
use IndexPilot\Plugin;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$mage = (int) wp_insert_post(
	array(
		'post_title'   => 'Маг на заклинаниях: полный разбор',
		'post_name'    => 'mag-na-zaklinaniyah',
		'post_content' => '<p>Разбор.</p>',
		'post_status'  => 'publish',
		'post_author'  => 1,
	)
);
$closed = (int) wp_insert_post(
	array(
		'post_title'   => 'Жрец на контроле: закрытая запись',
		'post_name'    => 'zhrec-na-kontrole',
		'post_content' => '<p>Разбор.</p>',
		'post_status'  => 'publish',
		'post_author'  => 1,
	)
);
update_post_meta( $closed, '_indexpilot_noindex', '1' );

$source = (int) get_page_by_path( 'gajd-4', OBJECT, 'post' )->ID;
$text   = '<p>В этом сезоне мага на заклинаниях берут чаще, чем Blood DK. Жрец на контроле тоже встречается.</p>';

delete_option( SuggestionIndex::OPTION );
rest_get_server();

$call = static function ( array $params ) {
	$request = new WP_REST_Request( 'POST', '/indexpilot/v1/links/suggest' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );

	return array( $response->get_status(), $response->get_data() );
};

list( $status, $found )    = $call( array( 'post_id' => $source, 'content' => $text ) );
list( , $with_link )       = $call( array( 'post_id' => $source, 'content' => $text . '<a href="/mag-na-zaklinaniyah/">уже есть</a>' ) );
list( , $own )             = $call( array( 'post_id' => $mage, 'content' => $text ) );

wp_set_current_user( 0 );
list( $anonymous )         = $call( array( 'post_id' => $source, 'content' => $text ) );
wp_set_current_user( 1 );

$titles = array_column( (array) $found, 'title' );
$urls   = array_column( (array) $found, 'url' );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'status_ok'                 => 200 === $status,
		'found'                     => $titles,
		'related_post_suggested'    => in_array( 'Маг на заклинаниях: полный разбор', $titles, true ),
		'unrelated_posts_absent'    => 2 === count( $titles ),
		'archetype_page_suggested'  => in_array( 'Blood DK', $titles, true ) && in_array( get_term_link( get_term_by( 'name', 'Blood DK', 'deck_archetype' ) ), $urls, true ),
		'noindex_post_not_suggested' => ! in_array( 'Жрец на контроле: закрытая запись', $titles, true ),
		'deck_itself_not_suggested' => ! in_array( get_permalink( get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' ) ), $urls, true ),
		'already_linked_is_skipped' => ! in_array( 'Маг на заклинаниях: полный разбор', array_column( (array) $with_link, 'title' ), true ),
		'post_is_not_suggested_to_itself' => ! in_array( 'Маг на заклинаниях: полный разбор', array_column( (array) $own, 'title' ), true ),
		'anonymous_rejected'        => 401 === $anonymous,
		'index_is_cached'           => is_array( get_option( SuggestionIndex::OPTION )['index'] ?? null ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

// Тестовые записи убираются, чтобы не влиять на остальные проверки.
wp_delete_post( $mage, true );
wp_delete_post( $closed, true );
delete_option( SuggestionIndex::OPTION );
