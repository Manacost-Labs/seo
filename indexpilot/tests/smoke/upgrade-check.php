<?php
/**
 * Проверка обновления и удаления плагина внутри тестового WordPress (Playground). Отдаёт JSON;
 * значение false на любом уровне — провал. Шаги по порядку прогоняет upgrade-run.sh:
 *
 *   upgrade-check.php?step=before            — на сайте со старой версией: данные во всех таблицах, настройки,
 *                                              снимок в upgrade-before.json; версии схем откатываются на прежние,
 *                                              у таблиц убираются столбцы, добавленные в новых схемах;
 *   (файлы плагина заменяются новой версией)
 *   upgrade-check.php?step=after             — схемы обновились, столбцы вернулись, данные и настройки целы, страницы без ошибок;
 *   upgrade-check.php?step=uninstall_keep    — удаление без настройки «удалять данные»: всё осталось;
 *   upgrade-check.php?step=uninstall_delete  — удаление с настройкой: не осталось ни опций, ни меты, ни таблиц, ни задач.
 *
 * Файл не входит в сборку плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification.Recommended -- тестовый стенд.

global $wpdb;

const INDEXPILOT_UPGRADE_SNAPSHOT = __DIR__ . '/upgrade-before.json';

/**
 * Версии схем таблиц: опция → [версия в коде, таблица, столбец, появившийся в последней версии].
 */
function indexpilot_upgrade_schemas(): array {
	global $wpdb;

	return array(
		'indexpilot_db_version'            => array( '1', $wpdb->prefix . 'indexpilot_links', '' ),
		'indexpilot_audit_db_version'      => array( '2', $wpdb->prefix . 'indexpilot_audit', 'fingerprint' ),
		'indexpilot_shortlinks_db_version' => array( '3', $wpdb->prefix . 'indexpilot_shortlinks', 'author' ),
		'indexpilot_promo_db'              => array( '1', $wpdb->prefix . 'indexpilot_promo_clicks', '' ),
	);
}

/**
 * Следы плагина в базе: опции, мета, таблицы, задачи по расписанию.
 *
 * @return array<string, mixed>
 */
function indexpilot_upgrade_traces(): array {
	global $wpdb;

	$cron = array();
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( str_starts_with( (string) $hook, 'indexpilot' ) ) {
				$cron[] = (string) $hook;
			}
		}
	}

	$tables = array();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'indexpilot' ) . '%' ) ) as $table ) {
		$tables[ (string) $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
	ksort( $tables );

	$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'indexpilot%' OR option_name LIKE '\\_transient\\_indexpilot%' OR option_name LIKE '\\_transient\\_timeout\\_indexpilot%' OR option_name LIKE '\\_site\\_transient\\_indexpilot%' ORDER BY option_name" );

	$meta = array();
	foreach ( array( 'post' => $wpdb->postmeta, 'term' => $wpdb->termmeta, 'user' => $wpdb->usermeta ) as $type => $table ) {
		foreach ( (array) $wpdb->get_results( "SELECT meta_key, COUNT(*) AS n FROM {$table} WHERE meta_key LIKE '\\_indexpilot\\_%' GROUP BY meta_key ORDER BY meta_key", ARRAY_A ) as $row ) {
			$meta[ $type . ':' . $row['meta_key'] ] = (int) $row['n'];
		}
	}

	return array(
		'options' => array_values( array_map( 'strval', (array) $options ) ),
		'meta'    => $meta,
		'tables'  => $tables,
		'cron'    => array_values( array_unique( $cron ) ),
	);
}

/**
 * Столбцы таблицы.
 *
 * @param string $table Таблица.
 * @return list<string>
 */
function indexpilot_upgrade_columns( string $table ): array {
	global $wpdb;

	return array_values( array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ) );
}

/**
 * Открывает страницу сайта и проверяет, что она без ошибок PHP и с тегами IndexPilot.
 *
 * @param string $url Адрес.
 */
function indexpilot_upgrade_page_ok( string $url ): bool {
	$response = wp_remote_get( $url, array( 'timeout' => 60 ) );
	$body     = (string) wp_remote_retrieve_body( $response );

	return ! is_wp_error( $response )
		&& 200 === (int) wp_remote_retrieve_response_code( $response )
		&& 0 === preg_match( '/Fatal error|Warning<\/b>|Notice<\/b>|Deprecated<\/b>|critical error/i', $body )
		&& str_contains( $body, 'application/ld+json' )
		&& 1 === preg_match( '/<meta name="description" content="[^"]+"/', $body );
}

/**
 * Запускает uninstall.php так, как это делает WordPress при удалении плагина (файлы не удаляются).
 */
function indexpilot_upgrade_uninstall(): void {
	deactivate_plugins( 'indexpilot/indexpilot.php', true );
	uninstall_plugin( 'indexpilot/indexpilot.php' );
	wp_cache_flush();
}

$step = sanitize_key( (string) ( $_GET['step'] ?? '' ) );
$out  = array( 'step' => $step );

try {
	switch ( $step ) {
		case 'before':
			$plugin = IndexPilot\Plugin::instance();

			// Настройки, отличные от значений по умолчанию.
			$settings                                   = (array) get_option( 'indexpilot_settings', array() );
			$settings['general']['separator']           = '|';
			$settings['general']['patch_version']       = '37.0';
			$settings['general']['organization_name']   = 'Manacost';
			$settings['general']['verify_yandex']       = 'abc123';
			update_option( 'indexpilot_settings', $settings );

			// Данные во всех таблицах плагина.
			$plugin->link_scanner()->scan_batch( true );
			do {
				$audit = $plugin->audit()->run_batch( 0 === ( $audit['processed'] ?? 0 ) );
			} while ( ! $audit['done'] );

			$shortlinks = $wpdb->prefix . 'indexpilot_shortlinks';
			$wpdb->insert( $shortlinks, array( 'slug' => 'upgrade-test', 'target' => home_url( '/' ), 'code' => 302, 'clicks' => 3, 'created' => current_time( 'mysql', true ) ) );
			$wpdb->insert( $wpdb->prefix . 'indexpilot_promo_clicks', array( 'day' => gmdate( 'Y-m-d' ), 'destination' => 'boosty', 'post_id' => 1, 'hits' => 5 ) );

			// Кэш карты сайта (временные значения) — тоже данные плагина.
			$plugin->sitemap()->render( IndexPilot\Sitemap\Router::parse( '/sitemap.xml', '/' ) );

			$post = get_page_by_path( 'gajd-4', OBJECT, 'post' );
			update_post_meta( $post->ID, '_indexpilot_title', 'Заголовок до обновления' );

			$traces = indexpilot_upgrade_traces();

			// Сайт «старой версии»: версии схем на шаг назад, столбцы новых схем убраны.
			$downgraded = array();
			foreach ( indexpilot_upgrade_schemas() as $option => list( $version, $table, $column ) ) {
				update_option( $option, (string) ( (int) $version - 1 ), true );
				if ( '' !== $column && in_array( $column, indexpilot_upgrade_columns( $table ), true ) ) {
					$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
					$downgraded[ $table ] = ! in_array( $column, indexpilot_upgrade_columns( $table ), true );
				}
			}

			file_put_contents(
				INDEXPILOT_UPGRADE_SNAPSHOT,
				wp_json_encode(
					array(
						'version'  => INDEXPILOT_VERSION,
						'traces'   => $traces,
						'settings' => get_option( 'indexpilot_settings' ),
						'post'     => $post->ID,
					),
					JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
				)
			);

			$out['old_version']     = INDEXPILOT_VERSION;
			$out['tables_filled']   = count( array_filter( $traces['tables'] ) ) >= 4;
			$out['columns_dropped'] = $downgraded;
			$out['tables']          = $traces['tables'];
			break;

		case 'after':
			$before = json_decode( (string) file_get_contents( INDEXPILOT_UPGRADE_SNAPSHOT ), true );

			// Первая загрузка сайта новой версией: здесь, в init, таблицы обновляются сами.
			$pages = array(
				'home' => indexpilot_upgrade_page_ok( home_url( '/' ) ),
				'post' => indexpilot_upgrade_page_ok( (string) get_permalink( (int) $before['post'] ) ),
			);

			wp_cache_flush();
			$after = indexpilot_upgrade_traces();

			$schemas = array();
			foreach ( indexpilot_upgrade_schemas() as $option => list( $version, $table, $column ) ) {
				$schemas[ $option ] = $version === (string) get_option( $option ) && ( '' === $column || in_array( $column, indexpilot_upgrade_columns( $table ), true ) );
			}

			// Строки таблиц и мета не потерялись (в таблицах могли добавиться строки — например, переходы при открытии страниц).
			$rows_kept = array();
			foreach ( $before['traces']['tables'] as $table => $count ) {
				$rows_kept[ $table ] = ( $after['tables'][ $table ] ?? -1 ) >= $count;
			}

			$meta_kept = array();
			foreach ( $before['traces']['meta'] as $key => $count ) {
				$meta_kept[ $key ] = ( $after['meta'][ $key ] ?? 0 ) >= $count;
			}

			$out['old_version']      = $before['version'];
			$out['new_version']      = INDEXPILOT_VERSION;
			$out['version_changed']  = INDEXPILOT_VERSION === IndexPilot\Plugin::VERSION && $before['version'] !== INDEXPILOT_VERSION;
			$out['schemas_updated']  = $schemas;
			$out['rows_kept']        = $rows_kept;
			$out['meta_kept']        = $meta_kept;
			$out['settings_kept']    = get_option( 'indexpilot_settings' ) === $before['settings'];
			$out['own_title_kept']   = 'Заголовок до обновления' === get_post_meta( (int) $before['post'], '_indexpilot_title', true );
			$out['pages_without_errors'] = $pages;
			break;

		case 'uninstall_keep':
			$before = indexpilot_upgrade_traces();
			indexpilot_upgrade_uninstall();
			$after = indexpilot_upgrade_traces();

			$out['setting_off']   = empty( get_option( 'indexpilot_settings' )['general']['delete_data_on_uninstall'] );
			$out['options_kept']  = $before['options'] === $after['options'];
			$out['meta_kept']     = $before['meta'] === $after['meta'];
			$out['tables_kept']   = $before['tables'] === $after['tables'];
			$out['counts']        = array( 'options' => count( $after['options'] ), 'meta_keys' => count( $after['meta'] ), 'tables' => count( $after['tables'] ) );

			activate_plugin( 'indexpilot/indexpilot.php' );
			break;

		case 'uninstall_delete':
			$settings = (array) get_option( 'indexpilot_settings', array() );
			$settings['general']['delete_data_on_uninstall'] = true;
			update_option( 'indexpilot_settings', $settings );

			// Сохранение настроек сбрасывает кэш карты сайта: он снова появляется, когда робот открывает карту.
			IndexPilot\Plugin::instance()->sitemap()->render( IndexPilot\Sitemap\Router::parse( '/sitemap.xml', '/' ) );

			$before = indexpilot_upgrade_traces();
			indexpilot_upgrade_uninstall();
			$after = indexpilot_upgrade_traces();

			$out['had_data']        = count( $before['options'] ) > 0 && count( $before['meta'] ) > 0 && count( $before['tables'] ) > 0;
			$out['had_transients']  = array() !== preg_grep( '/^_transient_indexpilot_/', $before['options'] );
			$out['no_options_left'] = array() === $after['options'];
			$out['no_meta_left']    = array() === $after['meta'];
			$out['no_tables_left']  = array() === $after['tables'];
			$out['no_cron_left']    = array() === $after['cron'];
			$out['left']            = $after;
			break;

		default:
			throw new RuntimeException( 'Неизвестный шаг. Шаги: before, after, uninstall_keep, uninstall_delete.' );
	}
} catch ( Throwable $error ) {
	$out['error'] = $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine();
}

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
