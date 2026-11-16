<?php
/**
 * Подготовка к проверке команд WP-CLI: записи без оценки, как после массовой загрузки мимо редактора.
 * Файл не входит в сборку плагина.
 */

use IndexPilot\Meta\MetaKeys;

$ids = array();
for ( $i = 1; $i <= 130; $i++ ) {
	$ids[] = (int) wp_insert_post(
		array(
			'post_title'   => 'Запись для командной строки ' . $i,
			'post_content' => '<p>Текст со <a href="/?p=1">ссылкой</a> на первую запись.</p>',
			'post_status'  => 'publish',
			'post_author'  => 1,
		)
	);
}

// Оценка стирается: команда должна проставить её заново всем записям.
foreach ( $ids as $id ) {
	delete_post_meta( $id, MetaKeys::SCORE );
	delete_post_meta( $id, MetaKeys::ANALYSIS );
}

file_put_contents( __DIR__ . '/cli-ids.json', wp_json_encode( $ids ) );
