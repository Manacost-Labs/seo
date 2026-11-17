<?php
/** Проверка справочника Koloda и обновления ранее сохранённой карты версий. */
require '/wordpress/wp-load.php';

use IndexPilot\Game\HeroCatalog;
use IndexPilot\Game\Module;
use IndexPilot\Plugin;

add_filter( 'indexpilot_site_profile', static fn() => 'koloda' );
$game = new Module( Plugin::instance() );
$saved = get_option( Module::VERSIONS_OPTION );
$scheduled = wp_next_scheduled( Module::REBUILD_HOOK );
$ids = array();
$make = static function ( string $title, string $code ) use ( &$ids ): int {
	$id = wp_insert_post( array( 'post_type' => 'hs_deck', 'post_status' => 'publish', 'post_title' => $title ) );
	$ids[] = $id;
	update_post_meta( $id, '_hs_deck_code', $code );
	return $id;
};
$notice = static function ( int $id ) use ( $game ): string {
	ob_start();
	$game->deck_code_notice( get_post( $id ) );
	return (string) ob_get_clean();
};
try {
	// Заголовки deckstring с героями 56550, 60238, 134896.
	$base = $make( 'Проверка версии ДХ', 'AAECAea5Aw==' );
	$skin = $make( 'Проверка версии ДХ', 'AAECAc7WAw==' );
	$wrong = $make( 'Друид на копиях от ThijsNL', 'AAECAc7WAw==' );
	$multi = $make( 'Чернокнижник Потасовки', 'AAECAfCdCA==' );
	$old = array( 'map' => array( $base => $base, $skin => $skin ) );
	update_option( Module::VERSIONS_OPTION, $old, false );
	wp_clear_scheduled_hook( Module::REBUILD_HOOK );
	$game->schedule_catalog_update();
	$pending = wp_next_scheduled( Module::REBUILD_HOOK );
	$game->schedule_catalog_update();
	$preview = $game->calculate_versions();
	$checks = array(
		'catalog_upgrade_scheduled' => false !== $pending && $pending === wp_next_scheduled( Module::REBUILD_HOOK ),
		'dry_calculation_preserves_map' => $old === get_option( Module::VERSIONS_OPTION ),
		'skin_joins_base_group' => $skin === $preview[ $base ] && $skin === $preview[ $skin ],
		'conflict_names_both_classes' => str_contains( $notice( $wrong ), 'Друид, в коде колоды — Охотник на демонов' ),
		'one_of_multiple_classes_matches' => 'Жрец / Чернокнижник' === $game->decks()->deck( $multi )->hero_class && '' === $notice( $multi ),
		'missing_code' => str_contains( $notice( $make( 'Пустой', '' ) ), 'Код колоды пуст' ),
		'invalid_header' => str_contains( $notice( $make( 'Повреждённый', '!' ) ), 'прочитать заголовок' ),
	);
	$game->rebuild_versions();
	$stored = get_option( Module::VERSIONS_OPTION );
	$checks['new_map_saved'] = HeroCatalog::BUILD === $stored['catalog'] && $skin === $stored['map'][ $base ];
	wp_clear_scheduled_hook( Module::REBUILD_HOOK );
	$game->schedule_catalog_update();
	$checks['no_duplicate_rebuild'] = false === wp_next_scheduled( Module::REBUILD_HOOK );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	wp_clear_scheduled_hook( Module::REBUILD_HOOK );
	if ( false !== $scheduled ) {
		wp_schedule_single_event( $scheduled, Module::REBUILD_HOOK );
	}
	if ( false === $saved ) {
		delete_option( Module::VERSIONS_OPTION );
	} else {
		update_option( Module::VERSIONS_OPTION, $saved, false );
	}
}
header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $checks );
