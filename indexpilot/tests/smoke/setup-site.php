<?php
/**
 * Наполняет тестовый WordPress (Playground) страницами для проверки вывода тегов.
 * Адреса созданных страниц пишет в urls.json рядом.
 */

use IndexPilot\Meta\MetaKeys;

update_option( 'blogname', 'Manacost' );
update_option( 'blogdescription', 'Всё о Hearthstone' );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
update_option( 'posts_per_page', 10 );
update_option( 'date_format', 'd.m.Y' );

// Тип записи hs_deck регистрирует fixture-types.php (подключён как mu-плагин).
$wp_rewrite->flush_rules( false );

$guides = (int) wp_insert_term( 'Гайды', 'category', array( 'slug' => 'gajdy', 'description' => 'Все гайды по Hearthstone: колоды, архетипы и разборы матчапов.' ) )['term_id'];
$news   = (int) wp_insert_term( 'Новости', 'category', array( 'slug' => 'novosti' ) )['term_id'];

$content = '<h2>Вступление</h2><p>Маг на заклинаниях держит темп с первых ходов. Колода наказывает медленные архетипы и закрывает игру к восьмому ходу.</p>'
	. '<p>Третье предложение нужно только для того, чтобы длина текста заметно превысила предел в сто пятьдесят пять символов.</p>';

$posts = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$posts[] = (int) wp_insert_post(
		array(
			'post_title'    => 'Гайд номер ' . $i,
			'post_author'   => 1,
			'post_name'     => 'gajd-' . $i,
			'post_content'  => $content,
			'post_status'   => 'publish',
			'post_category' => array( $guides ),
			'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( "-{$i} days" ) ),
		)
	);
}

$news_ids = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$news_ids[] = (int) wp_insert_post(
		array(
			'post_title'    => 'Новость ' . $i,
			'post_name'     => 'novost-' . $i,
			'post_content'  => '<p>Короткая новость.</p>',
			'post_status'   => 'publish',
			'post_category' => array( $news ),
		)
	);
}

// Запись с ручными полями.
$custom = $posts[0];
update_post_meta( $custom, MetaKeys::TITLE, 'Лучший маг сезона %sep% %sitename%' );
update_post_meta( $custom, MetaKeys::DESCRIPTION, 'Ручное описание гайда на мага.' );
update_post_meta( $custom, MetaKeys::FOCUS_KEYWORD, 'колода мага' );

// Запись, закрытая от индексации.
update_post_meta( $posts[1], MetaKeys::NOINDEX, '1' );

// Метка с одной записью — «тонкий» архив.
wp_set_post_terms( $posts[2], array( 'редкая' ), 'post_tag' );

// Запись под паролем.
$protected = (int) wp_insert_post(
	array(
		'post_title'    => 'Закрытая запись',
		'post_name'     => 'zakrytaya',
		'post_content'  => $content,
		'post_status'   => 'publish',
		'post_password' => 'secret',
	)
);

$deck = (int) wp_insert_post(
	array(
		'post_type'    => 'hs_deck',
		'post_title'   => 'Blood DK',
		'post_name'    => 'blood-dk',
		'post_content' => '',
		'post_status'  => 'publish',
		'post_date'    => '2026-11-15 10:00:00',
	)
);
wp_set_object_terms( $deck, 'Рыцарь Смерти', 'deck_class' );
wp_set_object_terms( $deck, 'Стандарт', 'deck_mode' );
wp_set_object_terms( $deck, 'Blood DK', 'deck_archetype' );
update_post_meta( $deck, '_dust_cost', '9400' );
// На боевом сайте в пустых полях стоит заглушка «Unknown» — в описание она попасть не должна.
update_post_meta( $deck, '_deck_player', 'Unknown' );

// Тексты рубрики сохраняются тем же путём, что и из формы термина: nonce, права, очистка.
wp_set_current_user( 1 );
$_POST = array(
	'indexpilot_term_nonce'    => wp_create_nonce( 'indexpilot_save_term' ),
	'indexpilot_title'         => '',
	'indexpilot_description'   => '',
	'indexpilot_focus_keyword' => 'гайды hearthstone',
	'indexpilot_intro'         => '<p>Вступление рубрики: лучшие гайды сезона.</p><script>alert(1)</script>',
	'indexpilot_outro'         => 'Подробный текст рубрики под списком записей.',
);
( new IndexPilot\Admin\TermPanel( IndexPilot\Plugin::instance()->resolver() ) )->save( $guides, 0, 'category' );
$_POST = array();

$archetype = get_term_by( 'name', 'Blood DK', 'deck_archetype' );

$tag = get_term_by( 'name', 'редкая', 'post_tag' );

file_put_contents(
	__DIR__ . '/urls.json',
	wp_json_encode(
		array(
			'home'       => home_url( '/' ),
			'post_auto'  => get_permalink( $posts[3] ),
			'post_custom' => get_permalink( $custom ),
			'post_noindex' => get_permalink( $posts[1] ),
			'post_protected' => get_permalink( $protected ),
			'category'   => get_term_link( $guides, 'category' ),
			'category_page_2' => trailingslashit( get_term_link( $guides, 'category' ) ) . 'page/2/',
			'category_no_description' => get_term_link( $news, 'category' ),
			'thin_tag'   => get_term_link( $tag ),
			'deck'       => get_permalink( $deck ),
			'archetype'  => get_term_link( $archetype ),
			'search'     => home_url( '/?s=маг' ),
			'not_found'  => home_url( '/net-takoj-stranicy/' ),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
	)
);
