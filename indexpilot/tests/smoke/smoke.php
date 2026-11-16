<?php
/**
 * Проверка плагина внутри настоящего WordPress (Playground). Результат пишется в result.json рядом.
 */

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;
use IndexPilot\Settings\Defaults;

$checks = array();
$check  = static function ( string $name, $expected, $actual ) use ( &$checks ): void {
	$checks[] = array(
		'name'     => $name,
		'pass'     => $expected === $actual,
		'expected' => $expected,
		'actual'   => $actual,
	);
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$check( 'плагин активен', true, is_plugin_active( 'indexpilot/indexpilot.php' ) );
$check( 'класс Plugin загружен', true, class_exists( Plugin::class ) );

update_option( 'blogname', 'Manacost' );
$cat_id  = (int) wp_insert_term( 'Гайды', 'category' )['term_id'];
$post_id = (int) wp_insert_post(
	array(
		'post_title'    => 'Колода мага',
		'post_excerpt'  => 'Короткий отрывок.',
		'post_content'  => '<p>Текст записи.</p>',
		'post_status'   => 'publish',
		'post_category' => array( $cat_id ),
	)
);

$renderer = Plugin::instance()->renderer();
$post_ctx = new Context( Context::POST, $post_id, 'post' );

$check( 'шаблон записи', 'Колода мага — Гайды — Manacost', $renderer->render( '%title% %sep% %category% %sep% %sitename%', $post_ctx ) );
$check( 'шаблон по умолчанию без номера страницы', 'Колода мага — Manacost', $renderer->render( Defaults::post_type( 'post' )['title'], $post_ctx ) );
$check( 'заголовок рубрики, страница 2', 'Гайды — страница 2 — Manacost', $renderer->render( Defaults::taxonomy( 'category' )['title'], new Context( Context::TERM, $cat_id, 'category', 2 ) ) );
$check( 'несохранённый заголовок из редактора', 'Черновик — Manacost', $renderer->render( '%title% %sep% %sitename%', new Context( Context::POST, $post_id, 'post', 1, array( 'title' => 'Черновик' ) ) ) );

update_post_meta( $post_id, '_dust_cost', '5400' );
$check( 'произвольное поле', 'Пыль: 5400', $renderer->render( 'Пыль: %cf__dust_cost%', $post_ctx ) );
$check( 'термины таксономии', 'Рубрика: Гайды', $renderer->render( 'Рубрика: %tax_category%', $post_ctx ) );

$check( 'мета записи зарегистрирована', true, registered_meta_key_exists( 'post', MetaKeys::TITLE, 'post' ) );
$check( 'мета термина зарегистрирована', true, registered_meta_key_exists( 'term', MetaKeys::INTRO, 'category' ) );

update_post_meta( $post_id, MetaKeys::TITLE, '  <b>Мой</b> заголовок ' );
$check( 'заголовок очищен от разметки', 'Мой заголовок', get_post_meta( $post_id, MetaKeys::TITLE, true ) );

update_post_meta( $post_id, MetaKeys::TITLE, '' );
$check( 'пустое значение не хранится', false, metadata_exists( 'post', $post_id, MetaKeys::TITLE ) );

update_post_meta( $post_id, MetaKeys::KEYWORDS, array( 'маг', 'Маг', '', 'берн', 'a', 'b', 'c' ) );
$check( 'ключевые слова: без дублей и пустых, не больше 4', array( 'Маг', 'берн', 'a', 'b' ), get_post_meta( $post_id, MetaKeys::KEYWORDS, true ) );

update_post_meta( $post_id, MetaKeys::NOINDEX, 'true' );
$check( 'флаг noindex хранится как 1', '1', get_post_meta( $post_id, MetaKeys::NOINDEX, true ) );

update_term_meta( $cat_id, MetaKeys::INTRO, '<p>Вступление</p><script>alert(1)</script>' );
$check( 'вступление рубрики: опасная разметка вырезана', '<p>Вступление</p>alert(1)', get_term_meta( $cat_id, MetaKeys::INTRO, true ) );

file_put_contents(
	__DIR__ . '/result.json',
	wp_json_encode(
		array(
			'wp'     => get_bloginfo( 'version' ),
			'php'    => PHP_VERSION,
			'checks' => $checks,
		),
		JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
	)
);
