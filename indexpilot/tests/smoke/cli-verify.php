<?php
/**
 * Проверка результата команд WP-CLI. Результат пишется в cli-result.json рядом.
 * Файл не входит в сборку плагина.
 */

use IndexPilot\Audit\Runner;
use IndexPilot\Links\Scanner;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;

$ids     = json_decode( (string) file_get_contents( __DIR__ . '/cli-ids.json' ), true );
$scored  = 0;
foreach ( $ids as $id ) {
	if ( '' !== (string) get_post_meta( (int) $id, MetaKeys::SCORE, true ) ) {
		++$scored;
	}
}

$scan  = get_option( Scanner::OPTION, array() );
$audit = get_option( Runner::OPTION, array() );

file_put_contents(
	__DIR__ . '/cli-result.json',
	wp_json_encode(
		array(
			'posts_created'          => count( $ids ),
			'all_posts_scored'       => count( $ids ) === $scored,
			'links_scan_finished'    => ! empty( $scan['done'] ) && (int) ( $scan['processed'] ?? 0 ) >= count( $ids ),
			'audit_finished'         => ! empty( $audit['done'] ) && (int) ( $audit['processed'] ?? 0 ) >= count( $ids ),
			'audit_has_summary'      => Plugin::instance()->audit()->summary()['auto_description'] >= count( $ids ),
		),
		JSON_PRETTY_PRINT
	)
);

unlink( __DIR__ . '/cli-ids.json' );
