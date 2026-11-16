<?php
/**
 * Проверка ответов и актуальности внутри тестового WordPress: упоминания классов и архетипов (разметка about и
 * mentions, подсказка в панели записи), страница-ответ класса (блок лучших колод, фраза-ответ, ItemList, очистка
 * сохранённых данных), llms.txt (классы, архетипы, мета-отчёты, авторы), Markdown-версия записи по адресу «.md»
 * и актуальность после патча (отметка в списке, отчёт, строка в блоке «Коротко»).
 * Отдаёт JSON, каждое значение должно быть true. Проверка создаёт свои записи и термины и удаляет их за собой
 * (с параметром ?keep=1 оставляет). Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\PatchReport;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Game\Hub;
use IndexPilot\Game\MentionIndex;
use IndexPilot\Game\Patch;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;
use IndexPilot\Settings\Settings;

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
$game    = $plugin->game();
$guides  = get_term_by( 'slug', 'gajdy', 'category' );
$news    = get_term_by( 'slug', 'novosti', 'category' );
$suffix  = (string) wp_rand( 1000, 9999 );
$created = array();
$terms   = array();
$saved   = get_option( Settings::OPTION, array() );
$keep    = isset( $_GET['keep'] );

register_shutdown_function(
	static function () use ( &$created, &$terms, $saved, $keep ): void {
		if ( $keep ) {
			return;
		}

		foreach ( $created as $id ) {
			wp_delete_post( $id, true );
		}

		foreach ( $terms as $term ) {
			wp_delete_term( $term[0], $term[1] );
		}

		update_option( Settings::OPTION, $saved );
	}
);

$get = static function ( string $url ): array {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 90,
			'redirection' => 0,
			'sslverify'   => false,
		)
	);

	return is_wp_error( $response ) ? array( 0, '', array() ) : array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $response );
};

$graph_of = static function ( string $html ): array {
	if ( ! preg_match( '#<script type="application/ld\+json" class="indexpilot-schema">(.*?)</script>#s', $html, $found ) ) {
		return array();
	}

	return (array) ( json_decode( $found[1], true )['@graph'] ?? array() );
};

$node_of = static function ( array $graph, string $type ): array {
	foreach ( $graph as $node ) {
		if ( $type === ( $node['@type'] ?? '' ) ) {
			return $node;
		}
	}

	return array();
};

// --- Страница-ответ класса: три колоды жреца, одна из них в архиве. -------------------------------------------------

$priest = wp_insert_term( 'Жрец', 'deck_class', array( 'slug' => 'priest-' . $suffix ) );
$priest = (int) $priest['term_id'];
$terms[] = array( $priest, 'deck_class' );

$scheduled_rebuild = false !== wp_next_scheduled( MentionIndex::REBUILD_HOOK );

$make_deck = static function ( string $title, int $wins, int $losses, bool $archived ) use ( &$created, &$terms, $priest, $suffix ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => 'hs_deck',
			'post_title'  => $title,
			'post_name'   => sanitize_title( $title ) . '-' . $suffix,
			'post_status' => 'publish',
		)
	);

	$created[] = $id;
	wp_set_object_terms( $id, $priest, 'deck_class' );
	wp_set_object_terms( $id, 'Стандарт', 'deck_mode' );
	$archetype = wp_set_object_terms( $id, $title . ' ' . $suffix, 'deck_archetype' );
	$terms[]   = array( (int) get_term_by( 'term_taxonomy_id', (int) $archetype[0] )->term_id, 'deck_archetype' );
	update_post_meta( $id, '_deck_wins', (string) $wins );
	update_post_meta( $id, '_deck_losses', (string) $losses );
	update_post_meta( $id, '_hs_deck_archived', $archived ? '1' : '0' );

	return $id;
};

$best     = $make_deck( 'Control Priest', 168, 144, false );
$lucky    = $make_deck( 'Lucky Priest', 4, 1, false );
$archived = $make_deck( 'Old Priest', 400, 100, true );

// Гайд по жрецу с упоминанием архетипа Blood DK, таблицей, замком и скриптом.
$answer   = 'Жрец на контроле переживает агрессию лечением и выигрывает поздней игрой.';
$content  = "<p>Против Блад ДК жрец играет от контроля.</p>\n\n<h2>Муллиган</h2>\n\n<ul><li>Дешёвое лечение</li><li>Удаление</li></ul>\n\n"
	. "<table><tr><th>Матчап</th><th>Победы</th></tr><tr><td>Blood DK</td><td>55 %</td></tr></table>\n\n"
	. "[sociallocker]Секретный текст для подписчиков[/sociallocker]\n\n<script>alert('x')</script>\n\n<p>Итог: берите жреца.</p>";

// Список архетипов собирается задачей расписания; здесь она выполняется сразу.
$game->mention_index()->rebuild();

$guide = (int) wp_insert_post(
	array(
		'post_title'    => 'Гайд: Жрец на контроле ' . $suffix,
		'post_name'     => 'gajd-zhrec-' . $suffix,
		'post_status'   => 'publish',
		'post_content'  => $content,
		'post_category' => array( (int) $guides->term_id ),
		'post_author'   => 1,
		'meta_input'    => array( MetaKeys::ANSWER => $answer ),
	)
);
$created[] = $guide;

$blood      = get_term_by( 'name', 'Blood DK', 'deck_archetype' );
$blood_url  = (string) get_term_link( $blood );
$class_url  = (string) get_term_link( $priest, 'deck_class' );
$mentions   = (array) ( get_post_meta( $guide, MentionIndex::META, true )['items'] ?? array() );
$saved_urls = array_column( $mentions, 'url' );

ob_start();
$game->mention_hints( get_post( $guide ) );
$hints = (string) ob_get_clean();

list( $guide_status, $guide_html ) = $get( (string) get_permalink( $guide ) );
$article = $node_of( $graph_of( $guide_html ), 'Article' );
$mention_urls = array_column( (array) ( $article['mentions'] ?? array() ), 'url' );

// Страница класса.
list( $class_status, $class_html ) = $get( $class_url );
$hub_saved = get_term_meta( $priest, Hub::META, true );
$item_list = $node_of( $graph_of( $class_html ), 'ItemList' );
$list_urls = array_column( (array) ( $item_list['itemListElement'] ?? array() ), 'url' );
// Сам блок: в списке записей ниже тема показывает все колоды класса, в том числе архивные.
$hub_html  = preg_match( '#<section class="indexpilot-hub".*?</section>#s', $class_html, $hub_found ) ? $hub_found[0] : '';
$best_pos  = strpos( $hub_html, (string) get_permalink( $best ) );
$lucky_pos = strpos( $hub_html, (string) get_permalink( $lucky ) );

// Изменились победы колоды — сохранённые данные класса очищаются.
update_post_meta( $lucky, '_deck_wins', '5' );
$hub_cleared = '' === get_term_meta( $priest, Hub::META, true );

// --- llms.txt ------------------------------------------------------------------------------------------------------

$report = (int) wp_insert_post(
	array(
		'post_title'    => 'Мета-отчёт: неделя ' . $suffix,
		'post_status'   => 'publish',
		'post_content'  => '<p>Обзор меты.</p>',
		'post_category' => array( (int) $guides->term_id ),
		'post_author'   => 1,
	)
);
$created[] = $report;

list( , $llms ) = $get( home_url( '/llms.txt' ) );
$authors_open   = $plugin->resolver()->resolve( new Context( Context::AUTHOR, 1 ) )->is_indexable();

// Архетипы попадают в файл, только если хоть одна их страница открыта (почти пустые закрыты правилом тонкого архива).
$archetypes_open = false;
foreach ( get_terms( array( 'taxonomy' => 'deck_archetype', 'hide_empty' => true ) ) as $archetype_term ) {
	$archetypes_open = $archetypes_open || $plugin->resolver()->resolve( new Context( Context::TERM, (int) $archetype_term->term_id, 'deck_archetype' ) )->is_indexable();
}
$thin_archetype_absent = ! str_contains( $llms, '](' . $blood_url . ')' ) || $plugin->resolver()->resolve( new Context( Context::TERM, (int) $blood->term_id, 'deck_archetype' ) )->is_indexable();

// --- Markdown-версия ------------------------------------------------------------------------------------------------

$noindex = (int) wp_insert_post(
	array(
		'post_title'   => 'Закрытый гайд ' . $suffix,
		'post_name'    => 'zakrytyj-' . $suffix,
		'post_status'  => 'publish',
		'post_content' => '<p>Текст.</p>',
		'meta_input'   => array( MetaKeys::NOINDEX => '1' ),
	)
);
$password = (int) wp_insert_post(
	array(
		'post_title'    => 'Гайд под паролем ' . $suffix,
		'post_name'     => 'parol-' . $suffix,
		'post_status'   => 'publish',
		'post_content'  => '<p>Тайна.</p>',
		'post_password' => 'secret',
	)
);
$created[] = $noindex;
$created[] = $password;

$md_url = untrailingslashit( (string) get_permalink( $guide ) ) . '.md';

list( $md_status, $markdown, $md_response ) = $get( $md_url );
list( $noindex_status, $noindex_body )      = $get( untrailingslashit( (string) get_permalink( $noindex ) ) . '.md' );
list( $password_status, $password_body )    = $get( untrailingslashit( (string) get_permalink( $password ) ) . '.md' );

$md_type  = $md_response ? (string) wp_remote_retrieve_header( $md_response, 'content-type' ) : '';
$md_robot = $md_response ? (string) wp_remote_retrieve_header( $md_response, 'x-robots-tag' ) : '';
$md_link  = $md_response ? (string) wp_remote_retrieve_header( $md_response, 'link' ) : '';

// --- Актуальность после патча ---------------------------------------------------------------------------------------

$today    = current_time( 'Y-m-d' );
$settings = is_array( $saved ) ? $saved : array();

$settings['general']['patch_version'] = '37.0';
$settings['general']['patch_date']    = $today;
update_option( Settings::OPTION, $settings );

// Настройки уже прочитаны этим запросом: читаем заново.
( fn() => $this->settings = null )->call( $plugin );

$old_guide = (int) wp_insert_post(
	array(
		'post_title'    => 'Гайд: старый шаман ' . $suffix,
		'post_status'   => 'publish',
		'post_content'  => '<p>Старый текст.</p>',
		'post_category' => array( (int) $guides->term_id ),
	)
);
$old_news  = (int) wp_insert_post(
	array(
		'post_title'    => 'Новость ' . $suffix,
		'post_status'   => 'publish',
		'post_content'  => '<p>Новость.</p>',
		'post_category' => array( (int) $news->term_id ),
	)
);
$created[] = $old_guide;
$created[] = $old_news;

global $wpdb;

foreach ( array( $old_guide, $old_news ) as $id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- дата изменения ставится в прошлое: wp_update_post всегда пишет текущую.
	$wpdb->update( $wpdb->posts, array( 'post_modified' => '2026-10-01 10:00:00', 'post_modified_gmt' => '2026-10-01 07:00:00', 'post_date' => '2026-10-01 10:00:00', 'post_date_gmt' => '2026-10-01 07:00:00' ), array( 'ID' => $id ) );
	clean_post_cache( $id );
}

ob_start();
$game->patch_flag( get_post( $old_guide ) );
$flag_old = (string) ob_get_clean();
ob_start();
$game->patch_flag( get_post( $guide ) );
$flag_new = (string) ob_get_clean();

ob_start();
( new PatchReport( $game ) )->render();
$report_html = (string) ob_get_clean();

list( , $guide_after_patch ) = $get( (string) get_permalink( $guide ) );

$settings_page = new SettingsPage( $plugin->settings(), $plugin->site_defaults(), $plugin->variables() );
$sanitized     = $settings_page->sanitize( array( 'general' => array( 'patch_date' => '15.11.2026' ) ) );
ob_start();
$settings_page->render();
$settings_html = (string) ob_get_clean();

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'mentions'      => array(
			'term_change_schedules_rebuild' => $scheduled_rebuild,
			'saved_on_save'                 => in_array( $blood_url, $saved_urls, true ) && in_array( $class_url, $saved_urls, true ),
			'article_about_game'            => 'Hearthstone' === ( $article['about']['name'] ?? '' ),
			'article_mentions_archetype'    => in_array( $blood_url, $mention_urls, true ),
			'article_mentions_class'        => in_array( $class_url, $mention_urls, true ),
			'metabox_hint'                  => str_contains( $hints, 'архетип Blood DK — сошлитесь на его страницу' ) && str_contains( $hints, $blood_url ),
			'guide_page_ok'                 => 200 === $guide_status,
		),
		'hub'           => array(
			'page_ok'              => 200 === $class_status,
			'block_shown'          => str_contains( $class_html, 'class="indexpilot-hub"' ),
			'answer_phrase'        => str_contains( $class_html, 'Лучшая колода жреца на' ) && str_contains( $class_html, '54 % побед по 312 играм' ),
			'best_first'           => false !== $best_pos && false !== $lucky_pos && $best_pos < $lucky_pos,
			'archived_hidden'      => '' !== $hub_html && ! str_contains( $hub_html, (string) get_permalink( $archived ) ),
			'guide_linked'         => str_contains( $class_html, (string) get_permalink( $guide ) ),
			'item_list'            => array() !== $list_urls && (string) get_permalink( $best ) === $list_urls[0],
			'data_saved'           => is_array( $hub_saved ) && isset( $hub_saved['decks'] ),
			'cleared_on_deck_edit' => $hub_cleared,
			'shortcode'            => shortcode_exists( Hub::SHORTCODE ),
			'no_assets'            => ! str_contains( $class_html, 'plugins/indexpilot/assets' ),
		),
		'llms'          => array(
			'classes'      => str_contains( $llms, '## Классы' ) && str_contains( $llms, '[Жрец](' . $class_url . ')' ),
			'archetypes'   => $archetypes_open === str_contains( $llms, '## Архетипы' ) && $thin_archetype_absent,
			'meta_reports' => str_contains( $llms, '## Свежие мета-отчёты' ) && str_contains( $llms, (string) get_permalink( $report ) ),
			'authors'      => $authors_open === str_contains( $llms, '## Авторы' ),
		),
		'markdown'      => array(
			'status'          => 200 === $md_status,
			'content_type'    => str_starts_with( strtolower( $md_type ), 'text/markdown' ),
			'noindex'         => str_contains( strtolower( $md_robot ), 'noindex' ),
			'canonical'       => str_contains( $md_link, '<' . get_permalink( $guide ) . '>; rel="canonical"' ),
			'title_answer'    => str_starts_with( $markdown, '# Гайд: Жрец на контроле' ) && str_contains( $markdown, '> ' . $answer ),
			'headings_lists'  => str_contains( $markdown, '## Муллиган' ) && str_contains( $markdown, '- Дешёвое лечение' ),
			'table'           => str_contains( $markdown, '| Матчап | Победы |' ),
			'locker_hidden'   => ! str_contains( $markdown, 'Секретный' ) && ! str_contains( $markdown, 'sociallocker' ),
			'no_html_scripts' => ! str_contains( $markdown, 'alert' ) && ! str_contains( $markdown, '<' ),
			'head_alternate'  => str_contains( $guide_html, '<link rel="alternate" type="text/markdown" href="' . $md_url . '">' ),
			'noindex_closed'  => 200 !== $noindex_status || ! str_contains( $noindex_body, '# Закрытый гайд' ),
			'password_closed' => 200 !== $password_status || ! str_contains( $password_body, 'Тайна' ),
		),
		'patch'         => array(
			'old_guide_outdated'  => $game->outdated( get_post( $old_guide ) ),
			'new_guide_current'   => ! $game->outdated( get_post( $guide ) ),
			'news_never_outdated' => ! $game->outdated( get_post( $old_news ) ),
			'list_flag'           => str_contains( $flag_old, 'устарело после патча 37.0' ) && '' === $flag_new,
			'report_lists_old'    => str_contains( $report_html, 'Гайд: старый шаман ' . $suffix ) && ! str_contains( $report_html, 'Гайд: Жрец на контроле' ) && ! str_contains( $report_html, 'Новость ' . $suffix ),
			'answer_block_line'   => str_contains( $guide_after_patch, 'Актуально для патча 37.0, обновлено' ),
			'date_normalized'     => '2026-11-15' === ( $sanitized['general']['patch_date'] ?? '' ),
			'settings_fields'     => str_contains( $settings_html, '[general][patch_version]' ) && str_contains( $settings_html, '[general][patch_date]' ) && str_contains( $settings_html, '[general][hub_auto]' ) && str_contains( $settings_html, '[general][llms_markdown]' ),
			'settings_help_tips'  => substr_count( $settings_html, 'class="indexpilot-help"' ) >= 4,
			'patch_is_active'     => ( new Patch( '37.0', $today ) )->active(),
		),
		'markdown_text' => $keep ? $markdown : null,
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
