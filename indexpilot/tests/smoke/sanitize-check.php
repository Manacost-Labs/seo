<?php
/**
 * Проверка: переменные шаблонов переживают сохранение в мету и в настройки.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/sanitize-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Meta\MetaKeys;

wp_set_current_user( 1 );

$post     = get_page_by_path( 'gajd-6', OBJECT, 'post' );
$template = '%title% %sep% %date% %category% {{%cf__dust_cost% пыли}} 100%';

update_post_meta( $post->ID, MetaKeys::TITLE, wp_slash( $template ) );
update_post_meta( $post->ID, MetaKeys::DESCRIPTION, wp_slash( "Гайд от %date%\nв рубрике %category% <b>жирный</b>" ) );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'template'          => $template,
		'saved_title'       => get_post_meta( $post->ID, MetaKeys::TITLE, true ),
		'title_intact'      => get_post_meta( $post->ID, MetaKeys::TITLE, true ) === $template,
		'saved_description' => get_post_meta( $post->ID, MetaKeys::DESCRIPTION, true ),
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

delete_post_meta( $post->ID, MetaKeys::TITLE );
delete_post_meta( $post->ID, MetaKeys::DESCRIPTION );
