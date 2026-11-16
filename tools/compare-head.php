<?php
/**
 * Сравнение SEO-тегов сайта «до» и «после» переключения на IndexPilot.
 *
 * Запуск (PHP без WordPress, нужны расширения curl и dom):
 *   php tools/compare-head.php sample <адрес-сайта> <выход.txt> [--per-kind=50] [--delay=1]
 *   php tools/compare-head.php snapshot <файл-адресов> <выход.json> [--delay=1]
 *   php tools/compare-head.php diff <до.json> <после.json>
 *
 * sample   — берёт по 50 адресов каждого вида из карты сайта, добавляет главную, поиск и несуществующий адрес.
 * snapshot — загружает адреса по одному с паузой и записывает теги каждой страницы.
 * diff     — печатает по каждому адресу, что пропало, изменилось и добавилось; код выхода 1,
 *            если хоть один адрес потерял заголовок или описание.
 * COMPARE_HEAD_AUTH — переменная окружения «user:pass» для базовой авторизации закрытой площадки; значение не печатается и в снимки не пишется.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

use IndexPilot\Tools\CompareHead\Fetcher;
use IndexPilot\Tools\CompareHead\HeadParser;
use IndexPilot\Tools\CompareHead\SitemapSampler;
use IndexPilot\Tools\CompareHead\SnapshotDiff;

require_once __DIR__ . '/lib/CompareHead/HeadParser.php';
require_once __DIR__ . '/lib/CompareHead/SnapshotDiff.php';
require_once __DIR__ . '/lib/CompareHead/SitemapSampler.php';
require_once __DIR__ . '/lib/CompareHead/Fetcher.php';

$indexpilot_args    = array();
$indexpilot_options = array(
	'delay'    => '1',
	'per-kind' => '50',
);

foreach ( array_slice( $argv, 1 ) as $indexpilot_arg ) {
	if ( preg_match( '/^--([a-z-]+)=(.*)$/', $indexpilot_arg, $indexpilot_match ) ) {
		$indexpilot_options[ $indexpilot_match[1] ] = $indexpilot_match[2];
	} else {
		$indexpilot_args[] = $indexpilot_arg;
	}
}

$indexpilot_usage = "Использование:\n"
	. "  php tools/compare-head.php sample <адрес-сайта> <выход.txt> [--per-kind=50] [--delay=1]\n"
	. "  php tools/compare-head.php snapshot <файл-адресов> <выход.json> [--delay=1]\n"
	. "  php tools/compare-head.php diff <до.json> <после.json>\n";

if ( count( $indexpilot_args ) !== 3 ) {
	fwrite( STDERR, $indexpilot_usage );
	exit( 2 );
}

list( $indexpilot_mode, $indexpilot_in, $indexpilot_out ) = $indexpilot_args;

$indexpilot_delay = max( 0.0, (float) $indexpilot_options['delay'] );
$indexpilot_auth  = (string) getenv( 'COMPARE_HEAD_AUTH' );

switch ( $indexpilot_mode ) {
	case 'sample':
		$indexpilot_fetcher = new Fetcher( $indexpilot_delay, 20, $indexpilot_auth );
		$indexpilot_sampler = new SitemapSampler(
			static function ( string $url ) use ( $indexpilot_fetcher ): array {
				$response = $indexpilot_fetcher->get( $url );
				fwrite( STDERR, sprintf( "%d %s\n", $response['status'], $url ) );
				return $response;
			},
			max( 1, (int) $indexpilot_options['per-kind'] )
		);

		$indexpilot_sample = $indexpilot_sampler->sample( $indexpilot_in );

		if ( '' === $indexpilot_sample['index'] ) {
			fwrite( STDERR, "Карта сайта не найдена (пробовал: " . implode( ', ', SitemapSampler::INDEXES ) . "). В выборке только главная, поиск и 404.\n" );
		}

		file_put_contents( $indexpilot_out, implode( "\n", $indexpilot_sample['urls'] ) . "\n" );

		printf( "Карта: %s\n", '' === $indexpilot_sample['index'] ? '—' : $indexpilot_sample['index'] );
		foreach ( $indexpilot_sample['kinds'] as $indexpilot_kind => $indexpilot_count ) {
			printf( "  %-20s %d\n", $indexpilot_kind, $indexpilot_count );
		}
		printf( "Адресов в выборке: %d → %s\n", count( $indexpilot_sample['urls'] ), $indexpilot_out );
		exit( 0 );

	case 'snapshot':
		$indexpilot_list = is_readable( $indexpilot_in ) ? file( $indexpilot_in, FILE_IGNORE_NEW_LINES ) : false;

		if ( false === $indexpilot_list ) {
			fwrite( STDERR, "Не прочитан файл адресов: $indexpilot_in\n" );
			exit( 2 );
		}

		$indexpilot_urls = array();
		foreach ( $indexpilot_list as $indexpilot_line ) {
			$indexpilot_line = trim( $indexpilot_line );
			if ( '' !== $indexpilot_line && '#' !== $indexpilot_line[0] ) {
				$indexpilot_urls[] = $indexpilot_line;
			}
		}
		$indexpilot_urls = array_values( array_unique( $indexpilot_urls ) );

		$indexpilot_fetcher  = new Fetcher( $indexpilot_delay, 20, $indexpilot_auth );
		$indexpilot_snapshot = array(
			'created'    => gmdate( 'c' ),
			'user_agent' => Fetcher::USER_AGENT,
			'pages'      => array(),
		);

		foreach ( $indexpilot_urls as $indexpilot_i => $indexpilot_url ) {
			$indexpilot_response = $indexpilot_fetcher->get( $indexpilot_url );
			$indexpilot_page     = array(
				'status'    => $indexpilot_response['status'],
				'final_url' => $indexpilot_response['final_url'],
				'head'      => HeadParser::parse( $indexpilot_response['body'] ),
			);

			if ( '' !== $indexpilot_response['error'] ) {
				$indexpilot_page['error'] = $indexpilot_response['error'];
			}

			$indexpilot_snapshot['pages'][ $indexpilot_url ] = $indexpilot_page;
			fwrite( STDERR, sprintf( "[%d/%d] %d %s\n", $indexpilot_i + 1, count( $indexpilot_urls ), $indexpilot_response['status'], $indexpilot_url ) );
		}

		file_put_contents( $indexpilot_out, json_encode( $indexpilot_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
		printf( "Снимок: адресов %d → %s\n", count( $indexpilot_urls ), $indexpilot_out );
		exit( 0 );

	case 'diff':
		$indexpilot_snapshots = array();

		foreach ( array( $indexpilot_in, $indexpilot_out ) as $indexpilot_file ) {
			$indexpilot_data = is_readable( $indexpilot_file ) ? json_decode( (string) file_get_contents( $indexpilot_file ), true ) : null;

			if ( ! is_array( $indexpilot_data ) ) {
				fwrite( STDERR, "Не прочитан снимок: $indexpilot_file\n" );
				exit( 2 );
			}

			$indexpilot_snapshots[] = $indexpilot_data;
		}

		$indexpilot_report = SnapshotDiff::compare( $indexpilot_snapshots[0], $indexpilot_snapshots[1] );
		echo SnapshotDiff::render( $indexpilot_report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- вывод в консоль.
		exit( $indexpilot_report['summary']['lost_any'] > 0 ? 1 : 0 );

	default:
		fwrite( STDERR, $indexpilot_usage );
		exit( 2 );
}
