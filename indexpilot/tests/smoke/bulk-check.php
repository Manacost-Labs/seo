<?php
/**
 * Проверка массового редактора внутри тестового WordPress. Отдаёт JSON.
 * Файл не входит в сборку плагина. Созданные для проверки записи удаляются.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\BulkEditor;
use IndexPilot\Meta\MetaKeys;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

rest_get_server();

$call = static function ( string $method, array $params ) {
	$request = new WP_REST_Request( $method, '/indexpilot/v1/bulk' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );

	return array( $response->get_status(), $response->get_data() );
};

// Пятьдесят записей для проверки сохранения одной пачкой.
$created = array();
for ( $i = 1; $i <= 50; $i++ ) {
	$created[] = (int) wp_insert_post(
		array(
			'post_title'   => 'Массовая запись ' . $i,
			'post_content' => '<p>Текст записи для проверки массового редактора.</p>',
			'post_status'  => 'publish',
			'post_author'  => 1,
		)
	);
}

list( $list_status, $list ) = $call( 'GET', array( 'kind' => 'post', 'type' => 'post' ) );
list( , $without )          = $call( 'GET', array( 'kind' => 'post', 'type' => 'post', 'filter' => 'no_description' ) );
list( , $searched )         = $call( 'GET', array( 'kind' => 'post', 'type' => 'post', 'search' => 'Массовая запись 17' ) );
list( , $terms )            = $call( 'GET', array( 'kind' => 'term', 'type' => 'category' ) );

$items = array();
foreach ( $created as $index => $id ) {
	$items[] = array(
		'id'              => $id,
		'seo_title'       => 'Заголовок ' . $index . ' %sep% %sitename% <b>x</b>',
		'seo_description' => 'Описание записи номер ' . $index . ' для проверки массового сохранения.',
	);
}

list( $save_status, $saved ) = $call( 'POST', array( 'kind' => 'post', 'type' => 'post', 'items' => $items ) );

$all_saved = 50 === count( array_filter( array_column( $saved['results'] ?? array(), 'saved' ) ) );
$first     = $saved['results'][0]['row'] ?? array();
$stored    = get_post_meta( $created[0], MetaKeys::TITLE, true );

// Строка чужого типа записи и запись термина; дубли и длинные значения.
$deck = (int) get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID;
list( , $mixed ) = $call(
	'POST',
	array(
		'kind'  => 'post',
		'type'  => 'post',
		'items' => array(
			array( 'id' => $deck, 'seo_title' => 'Колода под видом записи' ),
			array( 'id' => $created[0], 'seo_title' => 'Одинаковый заголовок', 'seo_description' => str_repeat( 'Очень длинное описание. ', 12 ) ),
			array( 'id' => $created[1], 'seo_title' => 'Одинаковый заголовок' ),
		),
	)
);

list( , $long )      = $call( 'GET', array( 'kind' => 'post', 'type' => 'post', 'filter' => 'long' ) );
list( , $duplicate ) = $call( 'GET', array( 'kind' => 'post', 'type' => 'post', 'filter' => 'duplicate' ) );

$category = (int) get_term_by( 'slug', 'novosti', 'category' )->term_id;
list( , $term_saved ) = $call( 'POST', array( 'kind' => 'term', 'type' => 'category', 'items' => array( array( 'id' => $category, 'seo_description' => 'Все новости Hearthstone на одной странице.' ) ) ) );

// Права: аноним и подписчик.
wp_set_current_user( 0 );
list( $anonymous ) = $call( 'GET', array( 'kind' => 'post', 'type' => 'post' ) );
$reader = (int) wp_insert_user( array( 'user_login' => 'bulk' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $reader );
list( $subscriber ) = $call( 'POST', array( 'kind' => 'post', 'type' => 'post', 'items' => array( array( 'id' => $created[2], 'seo_title' => 'Взлом' ) ) ) );
wp_set_current_user( 1 );

ob_start();
( new BulkEditor() )->render();
$html = (string) ob_get_clean();

$out = array(
	'list_ok'                    => 200 === $list_status && 50 === count( $list['rows'] ) && $list['total'] > 50 && $list['pages'] >= 2,
	'row_has_resolved_values'    => '' !== ( $list['rows'][0]['resolved_title'] ?? '' ) && isset( $list['rows'][0]['title_px'], $list['rows'][0]['edit_url'] ),
	'filter_no_description'      => $without['total'] > 0 && $without['total'] < $list['total'],
	'search_finds_one'           => 1 === $searched['total'] && 'Массовая запись 17' === ( $searched['rows'][0]['name'] ?? '' ),
	'terms_are_listed'           => in_array( 'Гайды', array_column( $terms['rows'], 'name' ), true ),
	'fifty_rows_saved_at_once'   => 200 === $save_status && $all_saved,
	'saved_value_is_cleaned'     => 'Заголовок 0 %sep% %sitename% x' === $stored,
	'row_returns_new_title'      => 'Заголовок 0 — Manacost x' === ( $first['resolved_title'] ?? '' ),
	'score_is_recalculated'      => '' !== (string) get_post_meta( $created[0], MetaKeys::SCORE, true ),
	'other_type_row_rejected'    => false === ( $mixed['results'][0]['saved'] ?? null ) && '' === (string) get_post_meta( $deck, MetaKeys::TITLE, true ),
	'rest_of_batch_saved'        => true === ( $mixed['results'][1]['saved'] ?? null ) && true === ( $mixed['results'][2]['saved'] ?? null ),
	'filter_long'                => in_array( $created[0], array_column( $long['rows'], 'id' ), true ) && ! in_array( $created[5], array_column( $long['rows'], 'id' ), true ),
	'filter_duplicate'           => 2 === $duplicate['total'],
	'term_saved'                 => true === ( $term_saved['results'][0]['saved'] ?? null ) && 'Все новости Hearthstone на одной странице.' === get_term_meta( $category, MetaKeys::DESCRIPTION, true ),
	'anonymous_rejected'         => 401 === $anonymous,
	'subscriber_rejected'        => 403 === $subscriber && 'Взлом' !== get_post_meta( $created[2], MetaKeys::TITLE, true ),
	'page_has_controls'          => str_contains( $html, 'data-indexpilot-bulk-type' ) && str_contains( $html, 'value="post:hs_deck"' ) && str_contains( $html, 'value="term:category"' ),
);

foreach ( $created as $id ) {
	wp_delete_post( $id, true );
}
delete_term_meta( $category, MetaKeys::DESCRIPTION );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
