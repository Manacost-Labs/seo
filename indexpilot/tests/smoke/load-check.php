<?php
/**
 * Замер нагрузки на большом сайте, созданном load-setup.php. Отдаёт JSON.
 *
 * Задачи (каждая — отдельный запрос, как их делает админка через REST):
 *   load-check.php?task=import[&restart=1][&batches=K]  — пачки импорта из AIOSEO (по 200 строк, как REST);
 *   load-check.php?task=audit[&restart=1][&batches=K]   — пачки полного аудита;
 *   load-check.php?task=links[&restart=1][&batches=K]   — пачки обхода ссылок;
 *   load-check.php?task=sitemap_files                   — список файлов карты сайта (кэш карты сбрасывается);
 *   load-check.php?task=sitemap_file&file=ИМЯ           — сборка файла карты (sitemap_index.xml, sitemap-pt-post-1.xml…) с пустым кэшем;
 *   load-check.php?task=pages[&offset=N]                — запросы плагина на страницах записи, колоды, рубрики и других;
 *   load-check.php?task=reset                           — убрать следы прошлых замеров (поля IndexPilot, граф ссылок, аудит).
 * Все задачи по порядку прогоняет load-run.sh.
 *
 * В каждом ответе: время пачек, пиковая память запроса и состояние (done — работа закончена).
 * Между пачками одного запроса память освобождается так же, как в командах WP-CLI.
 * Файл не входит в сборку плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

// Запросы к базе нужны замеру страниц.
define( 'SAVEQUERIES', true );

require '/wordpress/wp-load.php';

use IndexPilot\Doctor\Checkup;
use IndexPilot\Import\ImportState;
use IndexPilot\Plugin;
use IndexPilot\Sitemap\Cache as SitemapCache;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification.Recommended -- тестовый стенд.

const INDEXPILOT_LOAD_IMPORT_BATCH = 200;

$task    = sanitize_key( (string) ( $_GET['task'] ?? '' ) );
$restart = ! empty( $_GET['restart'] );
$batches = max( 1, min( 500, (int) ( $_GET['batches'] ?? 1 ) ) );
$plugin  = Plugin::instance();
$start   = microtime( true );
$out     = array( 'task' => $task );

/**
 * Прогоняет до $batches пачек и замеряет каждую.
 *
 * @param int                  $batches Сколько пачек.
 * @param callable(bool): array $run     Пачка; аргумент — начать сначала.
 * @param bool                 $restart Начать сначала.
 * @param Plugin               $plugin  Плагин.
 * @return array<string, mixed>
 */
function indexpilot_load_batches( int $batches, callable $run, bool $restart, Plugin $plugin ): array {
	$times  = array();
	$result = array();

	for ( $i = 0; $i < $batches; $i++ ) {
		$began  = microtime( true );
		$result = $run( $restart && 0 === $i );
		$times[] = microtime( true ) - $began;

		// Так же освобождается память между пачками в командах WP-CLI.
		$plugin->variables()->flush();
		wp_cache_flush_runtime();

		if ( ! empty( $result['done'] ) ) {
			break;
		}
	}

	return array(
		'batches'      => count( $times ),
		'batch_max_s'  => round( max( $times ), 3 ),
		'batch_avg_s'  => round( array_sum( $times ) / count( $times ), 3 ),
		'done'         => ! empty( $result['done'] ),
		'result'       => $result,
	);
}

try {
	switch ( $task ) {
		case 'import':
			$sources  = $plugin->import_sources();
			$source   = $sources['aioseo'];
			$importer = $plugin->importer();
			$state    = new ImportState();

			$out += indexpilot_load_batches(
				$batches,
				static function ( bool $restart ) use ( $source, $importer, $state ): array {
					if ( $restart ) {
						$state->reset( $source->id() );
					}

					$result = $importer->run( $source, $state->get( $source->id() )['last_id'], INDEXPILOT_LOAD_IMPORT_BATCH );
					$state->add( $source->id(), $result );
					unset( $result['samples'] );

					return $result;
				},
				$restart,
				$plugin
			);
			$out['total'] = $source->total();
			break;

		case 'audit':
			$runner = $plugin->audit();
			$out   += indexpilot_load_batches( $batches, static fn( bool $restart ): array => $runner->run_batch( $restart ), $restart, $plugin );
			break;

		case 'links':
			$scanner = $plugin->link_scanner();
			$out    += indexpilot_load_batches( $batches, static fn( bool $restart ): array => $scanner->scan_batch( $restart ), $restart, $plugin );
			break;

		case 'reset':
			// Сайт без следов прошлых замеров: импортированные поля, оценки, граф ссылок и аудит удаляются.
			global $wpdb;

			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_indexpilot\\_%'" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}indexpilot_links" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}indexpilot_audit" );
			foreach ( array( 'indexpilot_import_state', 'indexpilot_audit_state', 'indexpilot_links_scan' ) as $option ) {
				delete_option( $option );
			}
			wp_cache_flush();
			$out['done'] = true;
			break;

		case 'sitemap_files':
			( new SitemapCache() )->flush();
			$out['files'] = wp_list_pluck( $plugin->sitemap()->files(), 'loc' );
			break;

		case 'sitemap_file':
			// Файл разбирается по имени, как при настоящем запросе: список всех файлов заранее не строится.
			$router  = $plugin->sitemap();
			$request = $router::parse( '/' . sanitize_file_name( (string) ( $_GET['file'] ?? '' ) ), '/' );

			if ( null === $request ) {
				throw new RuntimeException( 'Нет такого файла карты.' );
			}

			( new SitemapCache() )->flush();
			memory_reset_peak_usage();
			$queries = count( $GLOBALS['wpdb']->queries );
			$began   = microtime( true );
			$xml     = (string) $router->render( $request );

			$out['file']    = $request;
			$out['build_s'] = round( microtime( true ) - $began, 3 );
			$out['urls']    = substr_count( $xml, '<url>' ) + substr_count( $xml, '<sitemap>' );
			$out['bytes']   = strlen( $xml );
			$out['queries'] = count( $GLOBALS['wpdb']->queries ) - $queries;
			// Доля базы: на SQLite в Playground групповые запросы во много раз медленнее, чем на MySQL.
			$out['db_s'] = round( array_sum( array_column( array_slice( $GLOBALS['wpdb']->queries, $queries ), 1 ) ), 3 );
			break;

		case 'pages':
			global $wpdb;

			$checkup = new Checkup();
			$post    = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name LIKE 'lt-post-%' ORDER BY ID DESC LIMIT 1 OFFSET " . absint( $_GET['offset'] ?? 0 ) );
			$deck    = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'hs_deck' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1 OFFSET " . absint( $_GET['offset'] ?? 0 ) );
			$targets = array(
				'post'      => (string) get_permalink( $post ),
				'deck'      => (string) get_permalink( $deck ),
				'category'  => (string) get_term_link( (int) get_term_by( 'name', 'Рубрика 1', 'category' )->term_id, 'category' ),
				'tag'       => (string) get_term_link( (int) get_term_by( 'name', 'метка 1', 'post_tag' )->term_id, 'post_tag' ),
				'archetype' => (string) get_term_link( (int) get_term_by( 'name', 'Архетип 1', 'deck_archetype' )->term_id, 'deck_archetype' ),
				'home'      => home_url( '/' ),
			);

			$out['pages'] = array();

			foreach ( $targets as $name => $url ) {
				$page = $checkup->page( $url );

				$out['pages'][ $name ] = array(
					'url'       => $url,
					'status'    => $page['status'] ?? null,
					'plugin'    => $page['plugin'] ?? null,
					'total'     => $page['total'] ?? null,
					'plugin_ms' => $page['plugin_ms'] ?? null,
					'page_ms'   => $page['page_ms'] ?? null,
					'error'     => $page['error'] ?? null,
					'queries'   => array_map( static fn( array $query ): string => $query['caller'] . ' — ' . $query['sql'], $page['queries'] ?? array() ),
				);
			}
			break;

		default:
			throw new RuntimeException( 'Неизвестная задача.' );
	}
} catch ( Throwable $error ) {
	$out['error'] = $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine();
}

$out['seconds'] = round( microtime( true ) - $start, 2 );
$out['peak_mb'] = round( memory_get_peak_usage() / 1048576, 1 );
$out['limit']   = ini_get( 'memory_limit' );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
