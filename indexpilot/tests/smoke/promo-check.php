<?php
/**
 * Проверка модуля «Переходы» внутри тестового WordPress: площадки и правила сохраняются формой, блок появляется
 * в тексте записи, выключатель его убирает, переход человека засчитывается, а робота — нет, отчёт сводит переходы,
 * запросов к базе на странице записи модуль не добавляет. Отдаёт JSON, каждое значение должно быть true.
 * Проверка не зависит от состояния сайта: свои площадки и правила она ставит на время работы и убирает за собой —
 * прежние возвращаются, строки счётчика и мета записи удаляются. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\ClassicMetabox;
use IndexPilot\Admin\PromoPage;
use IndexPilot\Admin\Reports\PromoReport;
use IndexPilot\Doctor\Checkup;
use IndexPilot\Plugin;
use IndexPilot\Promo\Blocks;
use IndexPilot\Promo\Destinations;
use IndexPilot\Promo\PostSwitch;
use IndexPilot\Promo\Report;
use IndexPilot\Promo\Rule;
use IndexPilot\Promo\WpClickStore;
use IndexPilot\Settings\Defaults;

global $wpdb;

wp_set_current_user( 1 );

$module = Plugin::instance()->promo();
$store  = $module->store();
$post   = get_page_by_path( 'gajd-4', OBJECT, 'post' );
$mark   = (string) wp_rand( 1000, 9999 );
$paid   = 'smokepaid' . $mark;
$social = 'smoketg' . $mark;

// Прежнее состояние сайта: возвращается в конце и при любой ошибке.
$old = array(
	Destinations::OPTION => get_option( Destinations::OPTION, array() ),
	Rule::OPTION         => get_option( Rule::OPTION, array() ),
);
$had_switch = '' !== (string) get_post_meta( $post->ID, Blocks::META_OFF, true );

$cleanup = static function () use ( $old, $had_switch, $post, $store, $paid, $social ): void {
	global $wpdb;

	foreach ( $old as $option => $value ) {
		update_option( $option, $value, true );
	}

	if ( $had_switch ) {
		update_post_meta( $post->ID, Blocks::META_OFF, '1' );
	} else {
		delete_post_meta( $post->ID, Blocks::META_OFF );
	}

	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE destination IN ( %s, %s )', $store->table(), $paid, $social ) );
};

register_shutdown_function( $cleanup );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$browser   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
$permalink = (string) get_permalink( $post );
$today     = wp_date( 'Y-m-d' );
$fetch     = static fn( string $url, string $agent ) => wp_remote_get(
	$url,
	array(
		'timeout'     => 60,
		'redirection' => 0,
		'user-agent'  => $agent,
		'sslverify'   => false,
	)
);
$page_html = static fn(): string => (string) wp_remote_retrieve_body( $fetch( $permalink, $browser ) );
$hits      = static function ( int $post_id ) use ( $store, $today, $paid ): int {
	$rows = array_filter( $store->rows( $today, $today ), static fn( array $row ): bool => $paid === $row['destination'] && $post_id === $row['post_id'] );

	return Report::total( array_values( $rows ) );
};

$table_exists  = $store->table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table() ) ) );
$autoloaded    = wp_load_alloptions( true );
$checkup       = new Checkup();
$queries_empty = $checkup->page( $permalink );

// Площадки и правила сохраняются формой страницы: nonce и права проверяет сама страница.
$page    = new PromoPage( $module->destinations(), $module->rules(), $module->router() );
$submit  = static function ( array $fields ) use ( $page ): void {
	$_POST    = $fields + array( '_wpnonce' => wp_create_nonce( 'indexpilot_promo' ) );
	$_REQUEST = $_POST;
	$page->handle();
	$_POST    = array();
	$_REQUEST = array();
};
$variant = static fn( string $text, string $button = '' ): array => compact( 'text', 'button' );

$submit(
	array(
		'indexpilot_promo_action' => 'destinations',
		'destinations'            => array(
			array( 'name' => 'Smoke Boosty', 'url' => 'https://boosty.to/smoke?ref=1#top', 'kind' => 'paid', 'code' => $paid ),
			array( 'name' => 'Smoke Telegram', 'url' => 'https://t.me/smoke', 'kind' => 'social', 'code' => $social, 'utm' => array( 'source' => 'smoke-site', 'medium' => 'social', 'campaign' => '' ) ),
			array( 'name' => 'Без адреса', 'url' => '', 'kind' => 'paid', 'code' => 'smokeempty' ),
			array( 'name' => 'Опасный адрес', 'url' => 'javascript:alert(1)', 'kind' => 'paid', 'code' => 'smokebad' ),
		),
	)
);

$destinations = $module->destinations()->all();
$category     = (int) ( wp_get_post_categories( $post->ID )[0] ?? 0 );

$submit(
	array(
		'indexpilot_promo_action' => 'rules',
		'rules'                   => array(
			// Порядок задаётся полем формы: третье по счёту правило идёт первым.
			array( 'order' => '3', 'enabled' => '1', 'destination' => $paid, 'place' => 'end', 'variants' => array( $variant( 'Smoke C ' . $mark ) ) ),
			array( 'order' => '2', 'enabled' => '1', 'destination' => $paid, 'place' => 'end', 'post_types' => array( 'post' ), 'variants' => array( $variant( 'Smoke B ' . $mark, 'Кнопка <b>B</b>' ) ) ),
			array( 'order' => '1', 'enabled' => '1', 'destination' => $paid, 'place' => 'after_first', 'post_types' => array( 'post' ), 'terms' => array( 'category' => (string) $category ), 'variants' => array( $variant( 'Smoke A1 ' . $mark ), $variant( 'Smoke A2 ' . $mark ), $variant( '' ) ) ),
			array( 'order' => '4', 'enabled' => '1', 'destination' => 'net-takoj', 'place' => 'end', 'variants' => array( $variant( 'Smoke D ' . $mark ) ) ),
			array( 'order' => '5', 'enabled' => '1', 'destination' => $paid, 'place' => 'end', 'variants' => array( $variant( '' ) ) ),
		),
	)
);

$rules  = $module->rules()->all();
$rule_a = $rules[0] ?? array( 'id' => 0, 'variants' => array(), 'terms' => array() );

ob_start();
$page->render();
$form = (string) ob_get_clean();

// Без nonce форма ничего не сохраняет: check_admin_referer() останавливает запрос.
$_POST            = array( 'indexpilot_promo_action' => 'destinations', 'destinations' => array() );
$_REQUEST         = $_POST;
$without_nonce    = false;
$die_handler      = static fn(): callable => static function (): void {
	throw new RuntimeException( 'stopped' );
};
add_filter( 'wp_die_handler', $die_handler );
try {
	$page->handle();
} catch ( RuntimeException $stopped ) {
	$without_nonce = true;
}
remove_filter( 'wp_die_handler', $die_handler );
$_POST    = array();
$_REQUEST = array();
$kept     = 2 === count( $module->destinations()->all() );

// Страница записи: два блока из трёх подходящих правил.
$html = $page_html();
preg_match( '#href="([^"]*/to/' . $paid . '/' . $post->ID . '-' . $rule_a['id'] . '-(\d+))"#', $html, $link );
preg_match_all( '#<aside class="indexpilot-promo".*?</aside>#s', $html, $asides );
$click_url = $link[1] ?? '';
$shown     = (int) ( $link[2] ?? -1 );

$queries_blocks = $checkup->page( $permalink );
$module_queries = array_filter( (array) ( $queries_blocks['queries'] ?? array() ), static fn( array $query ): bool => str_contains( (string) $query['caller'], 'Promo' ) );

// Выключатель у записи живёт в панели записи (вкладка «Дополнительно»): панель печатает флажок и проверяет nonce.
// Сохранение через настоящую отправку формы записи проверяет content-check.php.
$switch  = new PostSwitch();
$plugin  = Plugin::instance();
$metabox = new ClassicMetabox( $plugin->resolver(), $plugin->settings(), $plugin->variables() );

ob_start();
$metabox->render( $post );
$switch_html = (string) ob_get_clean();

$_POST = array( 'indexpilot_promo_off' => '1' );
$metabox->save( $post->ID, $post );
$switch_without_nonce = '' !== (string) get_post_meta( $post->ID, Blocks::META_OFF, true );
$_POST                = array();

$switch->save( $post->ID, true );
$switched_off = '1' === get_post_meta( $post->ID, Blocks::META_OFF, true );
$html_off     = $page_html();

$switch->save( $post->ID, false );
$switched_on = '' === (string) get_post_meta( $post->ID, Blocks::META_OFF, true );

// Переходы: два человека, один робот, один адрес с несуществующей записью, один неизвестный код.
$first   = $fetch( $click_url, $browser );
$second  = $fetch( $click_url, $browser );
$counted = $hits( (int) $post->ID );
$fetch( $click_url, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
$after_bot = $hits( (int) $post->ID );
$forged    = $fetch( $module->router()->url( $paid, 99999999, (int) $rule_a['id'], 0 ), $browser );
$unknown   = $fetch( $module->router()->url( 'smokenone' . $mark ), $browser );
$location  = (string) wp_remote_retrieve_header( $first, 'location' );
$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );

$rows = array_values( array_filter( $store->rows( $today, $today ), static fn( array $row ): bool => $paid === $row['destination'] ) );

ob_start();
( new PromoReport( $store, $module->destinations(), $module->rules() ) )->render();
$report = (string) ob_get_clean();

// Блок «Мы в соцсетях»: только соцсети, ссылки через адрес перехода.
$follow        = $module->follow()->render( (int) $post->ID );
$follow_click  = $fetch( $module->router()->url( $social, (int) $post->ID ), $browser );
$follow_target = (string) wp_remote_retrieve_header( $follow_click, 'location' );
$presets       = (array) apply_filters( 'indexpilot_shortlink_utm_presets', array() );

// Тот же вывод внутри этого запроса: вне цикла записи текст не меняется, в цикле блоки собираются без запросов к базе.
$text         = '<p>Вступление.</p><h2>Первый</h2><p>Текст.</p><h2>Второй</h2><p>Текст.</p>';
$outside_loop = $module->blocks()->filter( $text ) . $module->follow()->append( $text );

$GLOBALS['wp_query']->query( array( 'p' => $post->ID ) );
$GLOBALS['wp_query']->the_post();

$queries_before = $wpdb->num_queries;
$with_blocks    = $module->blocks()->filter( $text );
$with_follow    = $module->follow()->append( $with_blocks );
$queries_made   = $wpdb->num_queries - $queries_before;

$module_follow_twice = $module->follow()->append( $with_follow );

wp_reset_postdata();
$follow_auto = (bool) Plugin::instance()->settings()->general( 'promo_follow_auto' );

$out = array(
	'install'      => array(
		'table_exists'          => $table_exists,
		'version_is_autoloaded' => isset( $autoloaded[ WpClickStore::DB_OPTION ] ),
		'options_are_autoloaded' => isset( $autoloaded[ Destinations::OPTION ], $autoloaded[ Rule::OPTION ] ),
	),
	'admin'        => array(
		'two_destinations_saved'   => array( $paid, $social ) === array_column( $destinations, 'code' ),
		'row_without_url_skipped'  => ! in_array( 'smokeempty', array_column( $destinations, 'code' ), true ) && ! in_array( 'smokebad', array_column( $destinations, 'code' ), true ),
		'rules_saved_in_order'     => array( 'Smoke A1 ' . $mark, 'Smoke B ' . $mark, 'Smoke C ' . $mark ) === array_map( static fn( array $rule ): string => $rule['variants'][0]['text'], $rules ),
		'rule_keeps_fields'        => 'after_first' === ( $rule_a['place'] ?? '' ) && array( 'category' => array( $category ) ) === $rule_a['terms'] && 2 === count( $rule_a['variants'] ),
		'form_has_nonce'           => str_contains( $form, 'name="_wpnonce"' ),
		'form_lists_saved'         => str_contains( $form, 'value="https://boosty.to/smoke?ref=1#top"' ) && str_contains( $form, 'Smoke A2 ' . $mark ),
		'form_suggests_names'      => str_contains( $form, 'value="Boosty"' ) && str_contains( $form, 'value="ВКонтакте"' ) && str_contains( $form, 'value="Discord"' ),
		'nothing_without_nonce'    => $without_nonce && $kept,
		'pages_registered'         => PromoPage::SLUG === 'indexpilot-promo' && PromoReport::SLUG === 'indexpilot-promo-report',
	),
	'blocks'       => array(
		'two_blocks_in_post'       => 2 === count( $asides[0] ),
		'first_rule_shown'         => '' !== $click_url && str_contains( $html, 'Smoke A' . ( $shown + 1 ) . ' ' . $mark ),
		'second_rule_shown'        => str_contains( $html, 'Smoke B ' . $mark ) && str_contains( $html, 'Кнопка B</a>' ),
		'third_block_not_shown'    => ! str_contains( $html, 'Smoke C ' . $mark ),
		'no_scripts_in_blocks'     => array() !== $asides[0] && ! str_contains( implode( '', $asides[0] ), '<script' ) && ! str_contains( implode( '', $asides[0] ), '<link' ),
		'switch_has_nonce'         => str_contains( $switch_html, 'name="indexpilot_post_nonce"' ) && 1 === substr_count( $switch_html, 'name="indexpilot_promo_off"' ),
		'switch_needs_nonce'       => ! $switch_without_nonce,
		'switch_hides_blocks'      => $switched_off && '' !== $html_off && ! str_contains( $html_off, 'indexpilot-promo' ),
		'switch_can_be_cleared'    => $switched_on,
	),
	'clicks'       => array(
		'redirects_with_302'       => 302 === wp_remote_retrieve_response_code( $first ) && 302 === wp_remote_retrieve_response_code( $second ),
		'target_keeps_address'     => str_starts_with( $location, 'https://boosty.to/smoke?ref=1&utm_source=' . $host ) && str_ends_with( $location, '#top' ),
		'target_has_marks'         => str_contains( $location, 'utm_medium=article' ) && str_contains( $location, 'utm_campaign=' . $paid ) && str_contains( $location, 'utm_content=post-' . $post->ID ),
		'not_indexable'            => str_contains( strtolower( (string) wp_remote_retrieve_header( $first, 'x-robots-tag' ) ), 'noindex' ) && str_contains( strtolower( (string) wp_remote_retrieve_header( $first, 'cache-control' ) ), 'no-cache' ),
		'two_clicks_counted'       => 2 === $counted,
		'robot_not_counted'        => 2 === $after_bot,
		'forged_post_is_dropped'   => 302 === wp_remote_retrieve_response_code( $forged ) && 1 === $hits( 0 ) && 0 === $hits( 99999999 ),
		'unknown_code_is_404'      => 404 === wp_remote_retrieve_response_code( $unknown ),
	),
	'report'       => array(
		'one_row_for_post'         => array( array( 'post_id' => (int) $post->ID, 'hits' => 2 ) ) === Report::by_post( $rows ),
		'variant_has_own_counter'  => array( array( 'rule_id' => (int) $rule_a['id'], 'variant' => $shown, 'destination' => $paid, 'hits' => 2 ) ) === Report::by_variant( $rows ),
		'page_renders'             => str_contains( $report, 'data-indexpilot-promo-report' ) && str_contains( $report, 'Smoke Boosty' ) && str_contains( $report, esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) ),
		'page_shows_variant_text'  => str_contains( $report, 'Smoke A' . ( $shown + 1 ) . ' ' . $mark ),
	),
	'follow'       => array(
		'only_social'              => str_contains( $follow, '>Smoke Telegram</a>' ) && ! str_contains( $follow, 'Smoke Boosty' ),
		'through_click_address'    => str_contains( $follow, 'href="' . esc_attr( $module->router()->url( $social, (int) $post->ID ) ) . '"' ) && ! str_contains( $follow, 'https://t.me/smoke' ),
		'share_links'              => str_contains( $follow, 'https://t.me/share/url?url=' . rawurlencode( $permalink ) ) && str_contains( $follow, 'https://vk.com/share.php?url=' . rawurlencode( $permalink ) ),
		'shortcode'                => shortcode_exists( 'indexpilot_follow' ),
		'auto_output_off_by_default' => false === Defaults::general()['promo_follow_auto'],
		'auto_output_follows_setting' => str_contains( $html, 'indexpilot-follow' ) === $follow_auto,
		'appended_once'            => 1 === substr_count( $with_follow, '<aside class="indexpilot-follow"' ) && $with_follow === $module_follow_twice,
		'click_uses_own_marks'     => 'https://t.me/smoke?utm_source=smoke-site&utm_medium=social&utm_campaign=' . $social . '&utm_content=post-' . $post->ID === $follow_target,
		'utm_presets_for_shortlinks' => in_array( array( 'label' => 'Smoke Telegram', 'source' => 'smoke-site', 'medium' => 'social', 'campaign' => '' ), $presets, true ),
	),
	'load'         => array(
		'measured'                 => ! isset( $queries_empty['error'] ) && ! isset( $queries_blocks['error'] ),
		'text_untouched_outside_loop' => $text . $text === $outside_loop,
		'blocks_built_in_loop'     => 2 === substr_count( $with_blocks, '<aside class="indexpilot-promo"' ) && str_contains( $with_blocks, '</aside><h2>Второй</h2>' ) && str_ends_with( $with_blocks, '</aside>' ),
		'no_queries_in_loop'       => 0 === $queries_made,
		'no_module_queries'        => array() === $module_queries,
		'no_added_queries'         => isset( $queries_empty['plugin'], $queries_blocks['plugin'] ) && $queries_blocks['plugin'] <= $queries_empty['plugin'],
	),
	'plugin_queries' => array(
		'before' => $queries_empty['plugin'] ?? null,
		'with_blocks' => $queries_blocks['plugin'] ?? null,
	),
);

// Уборка до ответа: её итог входит в проверку.
$cleanup();
wp_cache_delete( 'alloptions', 'options' );

$out['cleanup'] = array(
	'options_restored' => get_option( Destinations::OPTION, array() ) === $old[ Destinations::OPTION ] && get_option( Rule::OPTION, array() ) === $old[ Rule::OPTION ],
	'clicks_removed'   => 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE destination IN ( %s, %s )', $store->table(), $paid, $social ) ),
	'switch_restored'  => $had_switch === ( '' !== (string) get_post_meta( $post->ID, Blocks::META_OFF, true ) ),
);

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
