<?php
/**
 * Проверка REST-маршрута анализа внутри тестового WordPress.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/rest-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Meta\MetaKeys;

$post = get_page_by_path( 'gajd-1', OBJECT, 'post' );

$call = static function ( array $params ): array {
	$request = new WP_REST_Request( 'POST', '/indexpilot/v1/analyze' );
	$request->set_body_params( $params );
	$response = rest_do_request( $request );

	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
};

$params = array(
	'object_type' => 'post',
	'object_id'   => $post->ID,
);

// 1. Без входа в систему.
wp_set_current_user( 0 );
$anonymous = $call( $params );

// 2. Администратор, сохранённые данные.
wp_set_current_user( 1 );
$saved = $call( $params );

// 3. Администратор, несохранённые значения из редактора.
$draft = $call(
	$params + array(
		'seo_title'       => 'Колода мага на заклинаниях %sep% %sitename%',
		'seo_description' => 'Колода мага на заклинаниях: список карт, муллиган и тактика против популярных архетипов сезона для новичков.',
		'focus_keyword'   => 'колода мага',
		'title_px'        => 412,
	)
);

// 4. Несуществующая запись.
$missing = $call( array( 'object_type' => 'post', 'object_id' => 999999 ) );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'anonymous_status' => $anonymous['status'],
		'saved_status'     => $saved['status'],
		'saved_title'      => $saved['data']['meta']['title'] ?? null,
		'saved_score'      => $saved['data']['analysis']['score'] ?? null,
		'draft_title'      => $draft['data']['meta']['title'] ?? null,
		'draft_checks'     => array_column( $draft['data']['analysis']['results'] ?? array(), 'status', 'id' ),
		'draft_title_msg'  => array_column( $draft['data']['analysis']['results'] ?? array(), 'message', 'id' )['title_width'] ?? null,
		'stored_title'     => get_post_meta( $post->ID, MetaKeys::TITLE, true ),
		'missing_status'   => $missing['status'],
		'stored_score'     => get_post_meta( $post->ID, MetaKeys::SCORE, true ),
		'stored_analysis'  => is_array( json_decode( (string) get_post_meta( $post->ID, MetaKeys::ANALYSIS, true ), true ) ),
		'deck_score'       => get_post_meta( get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID, MetaKeys::SCORE, true ),
		'assert'           => array(
			'anonymous_rejected'        => 401 === $anonymous['status'],
			'saved_ok'                  => 200 === $saved['status'],
			'draft_uses_unsaved_values' => 'Колода мага на заклинаниях — Manacost' === ( $draft['data']['meta']['title'] ?? null ),
			'unsaved_values_not_stored' => 'Лучший маг сезона %sep% %sitename%' === get_post_meta( $post->ID, MetaKeys::TITLE, true ),
			'missing_object_rejected'   => $missing['status'] >= 400,
			'score_stored_at_creation'  => '' !== (string) get_post_meta( get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID, MetaKeys::SCORE, true ),
		),
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
