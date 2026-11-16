<?php
/**
 * Проверка SEO авторов внутри тестового WordPress: поля профиля, страница автора, блок «Об авторе», анализ.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/authors-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина. После проверки у пользователя admin остаётся заполненный профиль —
 * его использует check-sitemap.php.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\AuthorPanel;
use IndexPilot\Authors\AuthorProfile;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$plugin  = Plugin::instance();
$panel   = new AuthorPanel();
$post    = get_page_by_path( 'gajd-4', OBJECT, 'post' );
$context = new Context( Context::AUTHOR, 1 );

$check = static function ( int $post_id, string $id ): string {
	$analysis = json_decode( (string) get_post_meta( $post_id, MetaKeys::ANALYSIS, true ), true );

	foreach ( (array) ( $analysis['results'] ?? array() ) as $result ) {
		if ( $id === $result['id'] ) {
			return (string) $result['status'];
		}
	}

	return '';
};

// До заполнения профиля анализ записи сообщает о пустом профиле автора.
wp_update_post( array( 'ID' => $post->ID ) );
$before = $check( $post->ID, 'author_profile' );

// Сохранение тем же путём, что с экрана профиля.
$_POST = array(
	'indexpilot_author_role'        => 'стример <b>',
	'indexpilot_author_links'       => "https://www.twitch.tv/manacost\njavascript:alert(1)\nhttps://t.me/manacost",
	'indexpilot_author_title'       => '',
	'indexpilot_author_description' => 'Стример и автор гайдов по Hearthstone: колоды, мета и разборы партий.',
	'indexpilot_author_image_id'    => '999999',
);
$panel->save( 1 );
$saved_without_nonce = '' !== (string) get_user_meta( 1, AuthorProfile::ROLE, true );

$_POST['indexpilot_author_nonce'] = wp_create_nonce( 'indexpilot_save_author' );
$panel->save( 1 );
update_user_meta( 1, 'description', 'Играю в Hearthstone с бета-теста и веду стримы каждый вечер.' );

// Пользователь без прав не может править чужой профиль.
$reader = (int) wp_insert_user( array( 'user_login' => 'reader' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'display_name' => 'Читатель' ) );
wp_set_current_user( $reader );
$_POST['indexpilot_author_nonce'] = wp_create_nonce( 'indexpilot_save_author' );
$_POST['indexpilot_author_role']  = 'взломщик';
$panel->save( 1 );
wp_set_current_user( 1 );
$_POST = array();

ob_start();
$panel->render( get_userdata( 1 ) );
$form = (string) ob_get_clean();

$profile  = ( new AuthorProfile() )->get( 1 );
$resolved = $plugin->resolver()->resolve( $context );
$nobody   = $plugin->resolver()->resolve( new Context( Context::AUTHOR, $reader ) );
$box      = $plugin->author_box()->html( (int) $post->ID );

wp_update_post( array( 'ID' => $post->ID ) );
$after = $check( $post->ID, 'author_profile' );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'form'     => array(
			'has_fields' => str_contains( $form, 'name="indexpilot_author_role"' ) && str_contains( $form, 'name="indexpilot_author_links"' ) && str_contains( $form, 'name="indexpilot_author_description"' ),
			'has_nonce'  => str_contains( $form, 'name="indexpilot_author_nonce"' ),
			'shows_links' => str_contains( $form, 'https://www.twitch.tv/manacost' ),
		),
		'saving'   => array(
			'nothing_without_nonce' => ! $saved_without_nonce,
			'role_cleaned'          => 'стример' === $profile['role'],
			'only_valid_links'      => array( 'https://www.twitch.tv/manacost', 'https://t.me/manacost' ) === $profile['same_as'],
			'foreign_image_rejected' => null === $profile['image'],
			'stranger_cannot_edit'  => 'стример' === get_user_meta( 1, AuthorProfile::ROLE, true ),
		),
		'page'     => array(
			'title'               => $resolved->title,
			'title_has_role'      => 'admin, стример — Manacost' === $resolved->title,
			'custom_description'  => 'Стример и автор гайдов по Hearthstone: колоды, мета и разборы партий.' === $resolved->description,
			'author_with_posts_is_indexable' => $resolved->is_indexable(),
			'author_without_posts_is_closed' => ! $nobody->is_indexable(),
		),
		'box'      => array(
			'html'          => $box,
			'links_to_page' => str_contains( $box, 'rel="author">admin</a>' ),
			'shows_role'    => str_contains( $box, '<span class="indexpilot-author-role">стример</span>' ),
			'shows_bio'     => str_contains( $box, 'Играю в Hearthstone с бета-теста' ),
			'names_platforms' => str_contains( $box, '>Twitch</a>' ) && str_contains( $box, '>Telegram</a>' ),
			'shortcode'     => shortcode_exists( 'indexpilot_author' ) && function_exists( 'indexpilot_author_box' ),
		),
		'analysis' => array(
			'empty_profile_is_noticed'  => 'ok' === $before,
			'filled_profile_is_good'    => 'good' === $after,
		),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
