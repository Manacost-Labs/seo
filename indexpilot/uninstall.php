<?php
/**
 * Удаление данных плагина — только если это явно включено в настройках.
 *
 * @package IndexPilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$indexpilot_settings = get_option( 'indexpilot_settings', array() );

if ( empty( $indexpilot_settings['general']['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Meta/MetaKeys.php';

foreach ( \IndexPilot\Meta\MetaKeys::all() as $indexpilot_meta_key ) {
	delete_post_meta_by_key( $indexpilot_meta_key );
	delete_metadata( 'term', 0, $indexpilot_meta_key, '', true );
	delete_metadata( 'user', 0, $indexpilot_meta_key, '', true );
}

foreach ( array( '_indexpilot_author_role', '_indexpilot_author_links', '_indexpilot_author_image_id' ) as $indexpilot_meta_key ) {
	delete_metadata( 'user', 0, $indexpilot_meta_key, '', true );
}

delete_option( 'indexpilot_settings' );
delete_option( 'indexpilot_import_state' );
delete_option( 'indexpilot_redirect_targets' );
delete_option( 'indexpilot_redirected_posts' );
delete_option( 'indexpilot_sitemap_keys' );
delete_option( 'indexpilot_links_scan' );
delete_option( 'indexpilot_link_index' );
delete_option( 'indexpilot_audit_state' );
delete_option( 'indexpilot_deck_versions' );
delete_option( 'indexpilot_doctor_probe' );
// Упоминания классов и архетипов и блоки страниц-ответов.
delete_option( 'indexpilot_mention_index' );
wp_clear_scheduled_hook( 'indexpilot_mention_index_rebuild' );
delete_post_meta_by_key( '_indexpilot_mentions' );
delete_metadata( 'term', 0, '_indexpilot_hub', '', true );
wp_clear_scheduled_hook( 'indexpilot_deck_versions_rebuild' );
// Игра записей (раздел World of Warcraft).
delete_post_meta_by_key( '_indexpilot_game' );
wp_clear_scheduled_hook( 'indexpilot_games_rebuild' );
delete_option( 'indexpilot_audit_db_version' );
delete_option( 'indexpilot_shortlinks_db_version' );
delete_option( 'indexpilot_db_version' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственной таблицы плагина по явной настройке.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'indexpilot_links' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственной таблицы плагина по явной настройке.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'indexpilot_audit' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственной таблицы плагина по явной настройке.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'indexpilot_shortlinks' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственной таблицы плагина по явной настройке.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'indexpilot_shortlink_clicks' ) );
// Переходы: площадки, правила блоков, счётчик и выключатель блоков у записей.
delete_option( 'indexpilot_promo_destinations' );
delete_option( 'indexpilot_promo_rules' );
delete_option( 'indexpilot_promo_db' );
delete_post_meta_by_key( '_indexpilot_promo_off' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственной таблицы плагина по явной настройке.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'indexpilot_promo_clicks' ) );
delete_option( 'indexpilot_indexnow_queue' );
delete_option( 'indexpilot_indexnow_key' );
delete_option( 'indexpilot_indexnow_log' );
wp_clear_scheduled_hook( 'indexpilot_indexnow_send' );
// Отметка оплаченного размещения, ролики записей и глубина записей от главной.
delete_post_meta_by_key( '_indexpilot_sponsored' );
delete_post_meta_by_key( '_indexpilot_videos' );
delete_option( 'indexpilot_link_depth' );
// Свои редиректы, журнал 404, самопроверка и проверка внешних адресов.
delete_option( 'indexpilot_redirects_db_version' );
delete_option( 'indexpilot_redirect_index' );
delete_option( 'indexpilot_health_db_version' );
delete_option( 'indexpilot_health_self_check' );
delete_option( 'indexpilot_health_self_check_urls' );
delete_option( 'indexpilot_health_outbound_collect' );
delete_metadata( 'user', 0, '_indexpilot_deleted_posts', '', true );
wp_clear_scheduled_hook( 'indexpilot_health_weekly' );
wp_clear_scheduled_hook( 'indexpilot_health_batch' );
wp_clear_scheduled_hook( 'indexpilot_outbound_daily' );
wp_clear_scheduled_hook( 'indexpilot_outbound_batch' );
foreach ( array( 'indexpilot_redirects', 'indexpilot_404', 'indexpilot_health' ) as $indexpilot_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственной таблицы плагина по явной настройке.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $indexpilot_table ) );
}
