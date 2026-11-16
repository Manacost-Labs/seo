<?php
/**
 * Проверка панели записи внутри тестового WordPress: отрисовка формы и сохранение полей.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/metabox-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\ClassicMetabox;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;

wp_set_current_user( 1 );

$plugin  = Plugin::instance();
$metabox = new ClassicMetabox( $plugin->resolver(), $plugin->settings(), $plugin->variables() );
$post    = get_page_by_path( 'gajd-5', OBJECT, 'post' );

ob_start();
$metabox->render( $post );
$html = (string) ob_get_clean();

$before = get_post_meta( $post->ID, MetaKeys::SCORE, true );

// Сохранение тем же путём, что из редактора: wp_update_post вызывает save_post, затем пересчёт оценки.
add_action( 'save_post', array( $metabox, 'save' ), 5, 2 );

$_POST = array(
	'indexpilot_post_nonce'    => wp_create_nonce( 'indexpilot_save_post' ),
	'indexpilot_title'         => 'Колода мага на заклинаниях <b>гайд</b> %sep% %sitename%',
	'indexpilot_description'   => 'Колода мага на заклинаниях: список карт, муллиган и тактика против популярных архетипов сезона — подробный разбор.',
	'indexpilot_focus_keyword' => 'колода мага',
	'indexpilot_keywords'      => 'берн маг, Берн Маг, , муллиган',
	'indexpilot_canonical'     => 'javascript:alert(1)',
	'indexpilot_noindex'       => '1',
);
wp_update_post( array( 'ID' => $post->ID ) );

$saved = array(
	'title'       => get_post_meta( $post->ID, MetaKeys::TITLE, true ),
	'description' => get_post_meta( $post->ID, MetaKeys::DESCRIPTION, true ),
	'keyword'     => get_post_meta( $post->ID, MetaKeys::FOCUS_KEYWORD, true ),
	'keywords'    => get_post_meta( $post->ID, MetaKeys::KEYWORDS, true ),
	'canonical'   => get_post_meta( $post->ID, MetaKeys::CANONICAL, true ),
	'noindex'     => get_post_meta( $post->ID, MetaKeys::NOINDEX, true ),
	'nofollow_absent' => ! metadata_exists( 'post', $post->ID, MetaKeys::NOFOLLOW ),
);
$after = get_post_meta( $post->ID, MetaKeys::SCORE, true );

// Без nonce сохранение не должно пройти.
$_POST = array( 'indexpilot_title' => 'Подмена без nonce' );
wp_update_post( array( 'ID' => $post->ID ) );
$without_nonce = get_post_meta( $post->ID, MetaKeys::TITLE, true );

// Пользователь без прав на запись.
$subscriber = wp_insert_user( array( 'user_login' => 'reader' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( (int) $subscriber );
$_POST = array(
	'indexpilot_post_nonce' => wp_create_nonce( 'indexpilot_save_post' ),
	'indexpilot_title'      => 'Подмена без прав',
);
$metabox->save( $post->ID, get_post( $post->ID ) );
$without_rights = get_post_meta( $post->ID, MetaKeys::TITLE, true );
$_POST          = array();

// Неизвестная переменная в заголовке: в предпросмотре панели остаётся и названа в предупреждении, на сайт не выходит.
wp_set_current_user( 1 );
$kept_title = get_post_meta( $post->ID, MetaKeys::TITLE, true );
update_post_meta( $post->ID, MetaKeys::TITLE, 'Гайд на мага %sep% %sitedesc% %sep% %sitename%' );

ob_start();
$metabox->render( get_post( $post->ID ) );
$unknown_html = (string) ob_get_clean();
$site_title   = $plugin->resolver()->resolve( new IndexPilot\Resolver\Context( IndexPilot\Resolver\Context::POST, (int) $post->ID, 'post' ) )->title;

update_post_meta( $post->ID, MetaKeys::TITLE, $kept_title );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'html_has_fields'   => str_contains( $html, 'name="indexpilot_title"' ) && str_contains( $html, 'name="indexpilot_description"' ) && str_contains( $html, 'name="indexpilot_focus_keyword"' ) && str_contains( $html, 'name="indexpilot_keywords"' ),
		'html_has_preview'  => str_contains( $html, 'data-indexpilot-serp-title' ),
		'help_tips'         => substr_count( $html, 'class="indexpilot-help"' ) >= 15 && str_contains( $html, 'data-tip="Что вписать:' ),
		'plain_labels'      => ! str_contains( $html, 'Focus Keyword' ) && ! str_contains( $html, 'хлебных крошках' ) && str_contains( $html, 'Главный запрос' ),
		'html_has_nonce'    => str_contains( $html, 'name="indexpilot_post_nonce"' ),
		'html_placeholder'  => str_contains( $html, 'placeholder="Гайд номер 5 — Manacost"' ),
		'saved'             => $saved,
		'score_before'      => $before,
		'score_after'       => $after,
		'title_after_no_nonce'  => $without_nonce,
		'title_after_no_rights' => $without_rights,
		'material_type_shown'   => str_contains( $html, 'Тип материала: Гайд по архетипу' ),
		'analysis_groups'       => str_contains( $html, 'data-pane="analysis"' ) && str_contains( $html, 'data-indexpilot-material' ),
		'no_warning_by_default' => 1 === preg_match( '#data-indexpilot-unknown role="alert" hidden>#', $html ),
		'unknown_variable_is_named'      => str_contains( $unknown_html, 'Неизвестная переменная: %sitedesc%.' ) && 1 === preg_match( '#data-indexpilot-unknown role="alert" >#', $unknown_html ),
		'unknown_variable_in_preview'    => 1 === preg_match( '#data-indexpilot-serp-title>[^<]*%sitedesc%#', $unknown_html ),
		'unknown_variable_not_on_site'   => 'Гайд на мага — Manacost' === $site_title,
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
