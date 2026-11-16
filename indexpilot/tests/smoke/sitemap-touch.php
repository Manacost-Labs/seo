<?php
/**
 * Снимает с публикации одну тестовую запись: после этого кэш карты сайта должен сброситься.
 * Вызывается из check-sitemap.php. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

wp_set_current_user( 1 );
wp_update_post(
	array(
		'ID'          => (int) get_page_by_path( 'gajd-12', OBJECT, 'post' )->ID,
		'post_status' => 'draft',
	)
);

echo 'ok';
