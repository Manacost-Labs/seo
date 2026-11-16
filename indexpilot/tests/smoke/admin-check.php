<?php
/**
 * Проверка админки внутри тестового WordPress: колонка «SEO», настройки, уведомление о конфликте, импорт.
 * Открывается по адресу /wp-content/plugins/indexpilot/tests/smoke/admin-check.php и отдаёт JSON.
 * Файл не входит в сборку плагина. Проверка меняет тестовый сайт и рассчитана на один запуск после его создания.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\ConflictNotice;
use IndexPilot\Admin\ImportPage;
use IndexPilot\Admin\PostListColumns;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Compat\ConflictDetector;
use IndexPilot\Import\ImportState;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Settings\Settings;

wp_set_current_user( 1 );

// Ошибка в проверке должна быть видна в ответе, а не скрыта страницей «критическая ошибка».
set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

// Второй вызов с ?reset_settings=1 убирает настройки, сохранённые первым вызовом.
if ( isset( $_GET['reset_settings'] ) ) {
	delete_option( Settings::OPTION );
	echo 'reset';
	exit;
}

$plugin = Plugin::instance();
$out    = array();

$capture = static function ( callable $callback ): string {
	ob_start();
	$callback();

	return (string) ob_get_clean();
};

$post_id = static fn( string $slug, string $type = 'post' ): int => (int) get_page_by_path( $slug, OBJECT, $type )->ID;

// --- 1.9. Колонка «SEO» -----------------------------------------------------------------------------------------

$columns = new PostListColumns( $plugin->resolver() );
$columns->register();
$deck_filter = false !== has_filter( 'manage_hs_deck_posts_columns' ) && false !== has_action( 'manage_hs_deck_posts_custom_column' );
$list        = $columns->columns( array( 'cb' => '', 'title' => 'Заголовок', 'date' => 'Дата' ) );

$cell_custom  = $capture( static fn() => $columns->render( 'indexpilot', $post_id( 'gajd-1' ) ) );
$cell_auto    = $capture( static fn() => $columns->render( 'indexpilot', $post_id( 'gajd-4' ) ) );
$cell_noindex = $capture( static fn() => $columns->render( 'indexpilot', $post_id( 'gajd-2' ) ) );
$cell_deck    = $capture( static fn() => $columns->render( 'indexpilot', $post_id( 'blood-dk', 'hs_deck' ) ) );

$out['columns'] = array(
	'column_before_date'         => array( 'cb', 'title', 'indexpilot', 'date' ) === array_keys( $list ),
	'filter_registered_for_deck' => $deck_filter,
	'no_filter_for_attachment'   => false === has_filter( 'manage_attachment_posts_columns' ),
	'custom_has_score'           => 1 === preg_match( '/class="indexpilot-score" data-level="(good|ok|bad)"[^>]*>\d+</', $cell_custom ),
	'custom_no_flag'             => ! str_contains( $cell_custom, 'no-description' ),
	'auto_flag_no_description'   => str_contains( $cell_auto, 'data-flag="no-description"' ) && str_contains( $cell_auto, 'нет описания' ),
	'noindex_flag'               => str_contains( $cell_noindex, 'data-flag="noindex"' ),
	'deck_template_no_flag'      => ! str_contains( $cell_deck, 'no-description' ),
	'other_column_is_empty'      => '' === $capture( static fn() => $columns->render( 'title', $post_id( 'gajd-1' ) ) ),
);

// --- 1.11. Настройки --------------------------------------------------------------------------------------------

$page = new SettingsPage( $plugin->settings(), $plugin->site_defaults(), $plugin->variables() );
$page->register_setting();

$html = $capture( static fn() => $page->render() );

$out['settings_form'] = array(
	'has_separator'     => str_contains( $html, 'name="indexpilot_settings[general][separator]"' ),
	'has_post_title'    => str_contains( $html, 'name="indexpilot_settings[post_types][post][title]"' ),
	'has_deck_title'    => str_contains( $html, 'name="indexpilot_settings[post_types][hs_deck][title]"' ),
	'has_category'      => str_contains( $html, 'name="indexpilot_settings[taxonomies][category][title]"' ),
	'has_search'        => str_contains( $html, 'name="indexpilot_settings[special][search][noindex]"' ),
	'has_nonce'         => str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, "name='option_page' value='indexpilot'" ),
	'no_attachment'     => ! str_contains( $html, '[post_types][attachment]' ),
	'deck_default_shown' => str_contains( $html, 'колода от %date%' ),
	'has_custom_synonyms' => 1 === preg_match( '#<textarea[^>]*name="indexpilot_settings\[general\]\[custom_synonyms\]"#', $html ),
);

// Шаблон с неизвестной переменной: в поле остаётся как написан, под полем — предупреждение.
$typo_page = new SettingsPage( new Settings( array( 'post_types' => array( 'post' => array( 'title' => '%title% %sep% %sitedesc% %sep% %sitename%' ) ) ), $plugin->site_defaults() ), $plugin->site_defaults(), $plugin->variables() );
$typo_html = $capture( static fn() => $typo_page->render() );

$out['settings_unknown_variable'] = array(
	'template_is_kept'       => str_contains( $typo_html, 'value="%title% %sep% %sitedesc% %sep% %sitename%"' ),
	'warning_names_it'       => 1 === substr_count( $typo_html, 'Неизвестная переменная: %sitedesc%.' ),
	'no_warning_by_default'  => ! str_contains( $html, 'Неизвестная переменная' ),
);

// Свои синонимы: многострочное поле — переводы строк разделяют группы, разметка вырезается.
$synonyms = (string) ( $page->sanitize( array( 'general' => array( 'custom_synonyms' => "колода = пачка, стопка\r\nагро = аггро <b>x</b>" ) ) )['general']['custom_synonyms'] ?? '' );
$parsed   = IndexPilot\Game\Lexicon::parse_synonyms( $synonyms );

$out['settings_synonyms'] = array(
	'lines_survive'   => array( 'колода', 'агро' ) === array_keys( $parsed ) && array( 'пачка', 'стопка' ) === $parsed['колода'],
	'markup_removed'  => ! str_contains( $synonyms, '<b>' ),
	'lexicon_uses_it' => ( new IndexPilot\Game\Lexicon( $parsed ) )->same( 'пачка приста', 'колода жреца' ),
);

// Сохранение тем же путём, что из формы: update_option вызывает очистку, зарегистрированную страницей.
update_option(
	Settings::OPTION,
	array(
		'general'    => array(
			'separator'              => '|',
			'thin_archive_threshold' => '3',
			'index_paged_archives'   => '1',
		),
		'post_types' => array(
			'post'  => array(
				'title'       => '%title% %sep% гайд от %date% <script>alert(1)</script>',
				'description' => '',
				'noindex'     => '0',
				'min_words'   => '300',
			),
			'bogus' => array( 'title' => 'x' ),
		),
		'special'    => array( 'search' => array( 'noindex' => '1' ) ),
	)
);

// Настройки остаются сохранёнными: заголовок на странице сайта проверяется отдельным запросом снаружи.
$saved = get_option( Settings::OPTION );

$out['settings_save'] = array(
	'stored'               => $saved,
	'only_changes_stored'  => array( 'general', 'post_types' ) === array_keys( $saved ) && array( 'separator' ) === array_keys( $saved['general'] ) && array( 'post' ) === array_keys( $saved['post_types'] ),
	'variables_survive'    => str_contains( (string) ( $saved['post_types']['post']['title'] ?? '' ), '%date%' ),
	'script_removed'       => ! str_contains( (string) ( $saved['post_types']['post']['title'] ?? '' ), '<script' ),
	'check_url'            => get_permalink( $post_id( 'gajd-4' ) ),
);

// --- 1.10. Импорт -----------------------------------------------------------------------------------------------

global $wpdb;

$table = $wpdb->prefix . 'aioseo_posts';
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL, title text, description text, keywords mediumtext, keyphrases longtext, canonical_url text, og_title text, og_description text, og_image_type varchar(20) DEFAULT 'default', og_image_custom_url text, robots_default tinyint(1) NOT NULL DEFAULT 1, robots_noindex tinyint(1) NOT NULL DEFAULT 0, robots_nofollow tinyint(1) NOT NULL DEFAULT 0, pillar_content tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id) )" );
$wpdb->query( "DELETE FROM {$table}" );

$empty_keyphrases = '{"focus":{"keyphrase":"","score":0,"analysis":{"keyphraseInTitle":{"score":0,"maxScore":9,"error":1}}},"additional":[]}';
$rows             = array(
	// Запись без своих полей: переносится всё.
	array( 'post_id' => $post_id( 'gajd-7' ), 'title' => '#post_title #separator_sa #site_title', 'description' => 'Описание из AIOSEO для гайда семь.', 'keywords' => '[{"label":"маг","value":"маг"}]', 'keyphrases' => '{"focus":{"keyphrase":"гайд на мага","score":0},"additional":[]}', 'canonical_url' => '', 'robots_default' => 0, 'robots_noindex' => 1 ),
	// У записи уже есть заголовок и описание IndexPilot: они не заменяются, ключевое слово не трогается.
	array( 'post_id' => $post_id( 'gajd-1' ), 'title' => 'Заголовок из AIOSEO', 'description' => 'Описание из AIOSEO.', 'keywords' => '[]', 'keyphrases' => '{"focus":{"keyphrase":"старый ключ","score":0},"additional":[]}' ),
	// Пустая строка — таких в настоящей таблице большинство; в выборку попасть не должна.
	array( 'post_id' => $post_id( 'gajd-8' ), 'title' => '', 'description' => '', 'keywords' => '[]', 'keyphrases' => $empty_keyphrases ),
	// Запись удалена — строка пропускается.
	array( 'post_id' => 999999, 'title' => 'Удалённая запись', 'description' => '', 'keywords' => '[]', 'keyphrases' => $empty_keyphrases ),
);
foreach ( $rows as $row ) {
	$wpdb->insert( $table, $row );
}

// Rank Math: у записи 9 данных AIOSEO нет — берётся; у записи 7 после AIOSEO заголовок есть — пропускается.
update_post_meta( $post_id( 'gajd-9' ), 'rank_math_title', '%title% %sep% %sitedesc%' );
update_post_meta( $post_id( 'gajd-9' ), 'rank_math_focus_keyword', 'гайд девять, девятый гайд' );
update_post_meta( $post_id( 'gajd-7' ), 'rank_math_title', 'Очень старый заголовок Rank Math' );
update_post_meta( $post_id( 'gajd-7' ), 'rank_math_canonical_url', 'https://example.com/old/' );
update_post_meta( $post_id( 'gajd-10' ), 'rank_math_twitter_use_facebook', 'on' );

// Самодельный модуль kolodahearthstone.com.
update_post_meta( $post_id( 'gajd-11' ), '_svl_seo_desc', 'Описание из модуля svl.' );
update_post_meta( $post_id( 'gajd-11' ), '_svl_seo_keywords', 'мета, поля сражений, тринкеты, аксессуары, нежить, стратегии' );
update_post_meta( $post_id( 'gajd-11' ), '_svl_seo_robots', 'noindex,follow' );

delete_option( ImportState::OPTION );
rest_get_server();

$request = static function ( array $params ) {
	$rest = new WP_REST_Request( 'POST', '/indexpilot/v1/import' );
	foreach ( $params as $key => $value ) {
		$rest->set_param( $key, $value );
	}
	$response = rest_do_request( $rest );

	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
};

$sources = $plugin->import_sources();
$totals  = array();
foreach ( $sources as $id => $source ) {
	$totals[ $id ] = $source->available() ? $source->total() : -1;
}

$score_before = get_post_meta( $post_id( 'gajd-7' ), MetaKeys::SCORE, true );

wp_set_current_user( 0 );
$anonymous = $request( array( 'source' => 'aioseo' ) );
wp_set_current_user( 1 );

$dry       = $request( array( 'source' => 'aioseo', 'dry_run' => true ) );
$after_dry = get_post_meta( $post_id( 'gajd-7' ), MetaKeys::TITLE, true );
$real      = $request( array( 'source' => 'aioseo' ) );
$again     = $request( array( 'source' => 'aioseo' ) );
$svl       = $request( array( 'source' => 'svl_seo' ) );
$rank      = $request( array( 'source' => 'rank_math' ) );
$bad       = $request( array( 'source' => 'nope' ) );

$meta = static fn( string $slug, string $key ) => get_post_meta( $post_id( $slug ), $key, true );

$out['import'] = array(
	'totals'                    => $totals,
	'anonymous_status'          => $anonymous['status'],
	'unknown_source_status'     => $bad['status'],
	'dry_run'                   => array_diff_key( $dry['data'], array( 'samples' => 1 ) ),
	'dry_run_sample_fields'     => array_keys( $dry['data']['samples'][0]['fields'] ?? array() ),
	'dry_run_wrote_nothing'     => '' === $after_dry,
	'real_state'                => $real['data']['state'] ?? $real,
	'second_run_adds_nothing'   => 0 === ( $again['data']['state']['processed'] ?? -1 ) - ( $real['data']['state']['processed'] ?? 0 ),
	'post7'                     => array(
		'title'       => $meta( 'gajd-7', MetaKeys::TITLE ),
		'description' => $meta( 'gajd-7', MetaKeys::DESCRIPTION ),
		'focus'       => $meta( 'gajd-7', MetaKeys::FOCUS_KEYWORD ),
		'keywords'    => $meta( 'gajd-7', MetaKeys::KEYWORDS ),
		'noindex'     => $meta( 'gajd-7', MetaKeys::NOINDEX ),
		'canonical'   => $meta( 'gajd-7', MetaKeys::CANONICAL ),
	),
	'post7_score_before_after'  => array( $score_before, $meta( 'gajd-7', MetaKeys::SCORE ) ),
	'post1_title_kept'          => 'Лучший маг сезона %sep% %sitename%' === $meta( 'gajd-1', MetaKeys::TITLE ),
	'post1_description_kept'    => 'Ручное описание гайда на мага.' === $meta( 'gajd-1', MetaKeys::DESCRIPTION ),
	'post1_focus_kept'          => 'колода мага' === $meta( 'gajd-1', MetaKeys::FOCUS_KEYWORD ),
	'svl_state'                 => $svl['data']['state'] ?? $svl,
	'post11'                    => array(
		'description' => $meta( 'gajd-11', MetaKeys::DESCRIPTION ),
		'keywords'    => $meta( 'gajd-11', MetaKeys::KEYWORDS ),
		'noindex'     => $meta( 'gajd-11', MetaKeys::NOINDEX ),
		'nofollow'    => $meta( 'gajd-11', MetaKeys::NOFOLLOW ),
	),
	'rank_math_state'           => $rank['data']['state'] ?? $rank,
	'post9'                     => array(
		'title'    => $meta( 'gajd-9', MetaKeys::TITLE ),
		'focus'    => $meta( 'gajd-9', MetaKeys::FOCUS_KEYWORD ),
		'keywords' => $meta( 'gajd-9', MetaKeys::KEYWORDS ),
	),
	'source_data_untouched'     => 4 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) && '%title% %sep% %sitedesc%' === get_post_meta( $post_id( 'gajd-9' ), 'rank_math_title', true ) && 'noindex,follow' === get_post_meta( $post_id( 'gajd-11' ), '_svl_seo_robots', true ),
);

$import_html = $capture( static fn() => ( new ImportPage( $sources, new ImportState() ) )->render() );

$out['import_page'] = array(
	'lists_sources'  => str_contains( $import_html, 'data-indexpilot-source="aioseo"' ) && str_contains( $import_html, 'data-indexpilot-source="svl_seo"' ) && str_contains( $import_html, 'data-indexpilot-source="rank_math"' ),
	'shows_finished' => str_contains( $import_html, 'Завершён. Перенесено записей: 1' ),
);

// --- Уведомление о конфликте ------------------------------------------------------------------------------------

set_current_screen( 'dashboard' );
$notice = new ConflictNotice( new ConflictDetector() );
$quiet  = $capture( static fn() => $notice->render() );

add_filter( 'indexpilot_conflicts', static fn( array $found ): array => array_merge( $found, array( 'All in One SEO' ) ) );
$loud = $capture( static fn() => $notice->render() );

set_current_screen( 'edit-post' );
$elsewhere = $capture( static fn() => $notice->render() );

$out['conflict_notice'] = array(
	'silent_without_conflict' => '' === $quiet,
	'shown_with_conflict'     => str_contains( $loud, 'data-indexpilot-conflict' ) && str_contains( $loud, 'All in One SEO' ) && str_contains( $loud, 'page=indexpilot-import' ),
	'not_on_other_screens'    => '' === $elsewhere,
	'output_blocked'          => ! ( new ConflictDetector() )->output_allowed(),
);

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
