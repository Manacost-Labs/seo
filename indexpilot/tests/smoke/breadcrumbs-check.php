<?php
/**
 * Проверка хлебных крошек внутри тестового WordPress: шорткод, функция для шаблонов, HTML цепочки.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/breadcrumbs-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;

$output = Plugin::instance()->breadcrumbs();
$post   = get_page_by_path( 'gajd-4', OBJECT, 'post' );
$deck   = get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' );

$post_html = $output->html( new Context( Context::POST, (int) $post->ID, 'post' ) );
$deck_html = $output->html( new Context( Context::POST, (int) $deck->ID, 'hs_deck' ) );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'shortcode_registered' => shortcode_exists( 'indexpilot_breadcrumbs' ),
		'function_exists'      => function_exists( 'indexpilot_breadcrumbs' ),
		'post_html'            => $post_html,
		'post_text'            => wp_strip_all_tags( $post_html ),
		'deck_text'            => wp_strip_all_tags( $deck_html ),
		'last_is_not_a_link'   => str_contains( $post_html, '<span aria-current="page">Гайд номер 4</span>' ),
		'home_is_empty'        => '' === $output->html( new Context( Context::HOME ) ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
