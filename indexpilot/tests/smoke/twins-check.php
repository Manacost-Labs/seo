<?php
/**
 * Проверка внутри тестового WordPress: черновик с адресом опубликованной записи не превращает её страницу в 404.
 * Отдаёт JSON, каждое значение должно быть true. Записи удаляются за собой. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

$suffix    = (string) wp_rand( 1000, 9999 );
$name      = 'dvojnik-kolody-' . $suffix;
$published = wp_insert_post(
	array(
		'post_title'   => 'Опубликованная колода ' . $suffix,
		'post_name'    => $name,
		'post_status'  => 'publish',
		'post_content' => 'Текст опубликованной колоды.',
		'post_date'    => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
	)
);
$draft     = wp_insert_post(
	array(
		'post_title'   => 'Черновик колоды ' . $suffix,
		'post_name'    => $name,
		'post_status'  => 'draft',
		'post_content' => 'Текст черновика.',
	)
);

$url      = (string) get_permalink( $published );
$response = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0 ) );
$body     = (string) wp_remote_retrieve_body( $response );

$result = array(
	'draft_shares_address' => get_post_field( 'post_name', $draft ) === $name,
	'published_page_200'   => 200 === (int) wp_remote_retrieve_response_code( $response ),
	'shows_published_post' => str_contains( $body, 'Опубликованная колода ' . $suffix ),
	'draft_not_shown'      => ! str_contains( $body, 'Черновик колоды ' . $suffix ),
);

wp_delete_post( $published, true );
wp_delete_post( $draft, true );

echo wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
