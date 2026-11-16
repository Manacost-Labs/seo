<?php
/**
 * Проверка настоящего запроса к целям редиректов (нужен выход в сеть из тестового WordPress).
 * Запускается после redirects-check.php. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

wp_set_current_user( 1 );

$service = IndexPilot\Plugin::instance()->redirects();
$before  = $service->pending();
$batch   = $service->check_batch( 2 );
$results = $service->targets()->results();

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'pending_before' => $before,
		'batch'          => $batch,
		'checked'        => array_intersect_key( $results, array_flip( array_slice( $before, 0, 2 ) ) ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
