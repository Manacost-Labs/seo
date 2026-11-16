<?php
/**
 * Проверка поля indexpilot в стандартных REST-ответах WordPress. Отдаёт JSON.
 * Заодно отмечает запись gajd-4 опорной — это использует проверка llms.txt в check-sitemap.php.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Meta\MetaKeys;

set_exception_handler(
	static function ( Throwable $error ): void {
		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

rest_get_server();

$call = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );

	return array( $response->get_status(), (array) $response->get_data() );
};

$post_id  = (int) get_page_by_path( 'novost-1', OBJECT, 'post' )->ID;
$category = (int) get_term_by( 'slug', 'novosti', 'category' )->term_id;
$route    = '/wp/v2/posts/' . $post_id;

wp_set_current_user( 0 );
list( $public_status, $public ) = $call( 'GET', $route );

$reader = (int) wp_insert_user( array( 'user_login' => 'rest' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $reader );
list( $reader_status ) = $call( 'POST', $route, array( 'indexpilot' => array( 'seo_title' => 'Взлом' ) ) );

wp_set_current_user( 1 );
list( , $edit )         = $call( 'GET', $route, array( 'context' => 'edit' ) );
list( $write_status, $written ) = $call(
	'POST',
	$route,
	array(
		'indexpilot' => array(
			'seo_title'       => 'Новость из REST %sep% %sitename% <i>x</i>',
			'seo_description' => 'Описание, записанное через REST вместе с записью.',
			'focus_keyword'   => 'новость hearthstone',
			'keywords'        => array( 'патч', 'Патч', 'обновление' ),
			'noindex'         => true,
			'score'           => '100',
		),
	)
);

$stored = array(
	'title'    => get_post_meta( $post_id, MetaKeys::TITLE, true ),
	'keywords' => get_post_meta( $post_id, MetaKeys::KEYWORDS, true ),
	'noindex'  => get_post_meta( $post_id, MetaKeys::NOINDEX, true ),
	'score'    => get_post_meta( $post_id, MetaKeys::SCORE, true ),
);

// Возврат записи в исходное состояние.
$call( 'POST', $route, array( 'indexpilot' => array( 'seo_title' => '', 'seo_description' => '', 'focus_keyword' => '', 'keywords' => array(), 'noindex' => false ) ) );

list( , $term )            = $call( 'GET', '/wp/v2/categories/' . $category );
list( $term_status )       = $call( 'POST', '/wp/v2/categories/' . $category, array( 'indexpilot' => array( 'seo_description' => 'Новости Hearthstone: патчи и анонсы.' ) ) );
$term_stored               = get_term_meta( $category, MetaKeys::DESCRIPTION, true );
delete_term_meta( $category, MetaKeys::DESCRIPTION );

// Опорная запись для llms.txt.
update_post_meta( (int) get_page_by_path( 'gajd-4', OBJECT, 'post' )->ID, MetaKeys::CORNERSTONE, '1' );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'public_has_final_tags'      => 200 === $public_status && 'Новость 1 — Manacost' === ( $public['indexpilot']['title'] ?? '' ) && isset( $public['indexpilot']['canonical'], $public['indexpilot']['robots'] ),
		'public_hides_internal_data' => ! isset( $public['indexpilot']['focus_keyword'] ) && ! isset( $public['indexpilot']['score'] ) && ! isset( $public['indexpilot']['seo_title'] ),
		'editor_sees_fields'         => array_key_exists( 'focus_keyword', $edit['indexpilot'] ?? array() ) && array_key_exists( 'score', $edit['indexpilot'] ?? array() ),
		'subscriber_cannot_write'    => 403 === $reader_status,
		'write_ok'                   => 200 === $write_status,
		'title_is_cleaned'           => 'Новость из REST %sep% %sitename% x' === $stored['title'],
		'response_has_new_title'     => 'Новость из REST — Manacost x' === ( $written['indexpilot']['title'] ?? '' ),
		'keywords_deduplicated'      => array( 'Патч', 'обновление' ) === $stored['keywords'] || array( 'патч', 'обновление' ) === $stored['keywords'],
		'noindex_applied'            => '1' === $stored['noindex'] && str_starts_with( (string) ( $written['indexpilot']['robots'] ?? '' ), 'noindex' ),
		'score_cannot_be_forged'     => '100' !== (string) $stored['score'],
		'post_restored'              => '' === (string) get_post_meta( $post_id, MetaKeys::TITLE, true ) && '' === (string) get_post_meta( $post_id, MetaKeys::NOINDEX, true ),
		'term_has_final_tags'        => '' !== (string) ( $term['indexpilot']['title'] ?? '' ),
		'term_write_ok'              => 200 === $term_status && 'Новости Hearthstone: патчи и анонсы.' === $term_stored,
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
