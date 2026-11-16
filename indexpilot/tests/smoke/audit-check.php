<?php
/**
 * Проверка аудита внутри тестового WordPress. Отдаёт JSON.
 * Файл не входит в сборку плагина. Созданные для проверки записи удаляются.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\Reports\AuditReport;
use IndexPilot\Audit\Runner;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

global $wpdb;

$runner = Plugin::instance()->audit();
$make   = static fn( string $title, string $content, array $meta = array() ): int => (int) wp_insert_post(
	array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_author'  => 1,
		'meta_input'   => $meta,
	)
);

// Разные предложения: текст из одного повторённого предложения почти не имеет разных фрагментов, и похожие тексты на нём не сравнить.
$long = '<p>' . implode( ' ', array_map( static fn( int $turn ): string => sprintf( 'На ходу %d маг на заклинаниях держит темп и разыгрывает карту номер %d.', $turn, $turn * 7 ), range( 1, 40 ) ) ) . '</p>';

$twin_a  = $make( 'Одинаковое название', $long );
$twin_b  = $make( 'Одинаковое название', $long . '<p>Чуть другой текст.</p>' );
$with_h1 = $make( 'Запись с лишним заголовком', '<h1>Лишний</h1>' . $long );
$thin    = $make( 'Короткая запись', '<p>Пара слов.</p>' );
$closed  = $make( 'Закрытая копия', $long, array( MetaKeys::NOINDEX => '1' ) );
$manual  = $make( 'Запись с описанием', $long, array( MetaKeys::DESCRIPTION => 'Написанное вручную описание записи для поиска.' ) );

delete_option( Runner::OPTION );
rest_get_server();

$run = static function ( bool $restart ) {
	$request = new WP_REST_Request( 'POST', '/indexpilot/v1/audit/run' );
	$request->set_param( 'restart', $restart );
	$response = rest_do_request( $request );

	return array( $response->get_status(), $response->get_data() );
};

wp_set_current_user( 0 );
list( $anonymous ) = $run( true );
wp_set_current_user( 1 );

$batches = 0;
do {
	list( $status, $result ) = $run( 0 === $batches );
	++$batches;
} while ( 200 === $status && ! $result['done'] && $batches < 20 );

$summary = $runner->summary();
$groups  = $runner->duplicate_groups( 'title_hash' );
$pair    = array();
foreach ( $groups as $group ) {
	if ( in_array( $twin_a, $group, true ) ) {
		$pair = $group;
	}
}

ob_start();
$_GET['view'] = 'duplicate_title_hash';
( new AuditReport( $runner ) )->render();
$html = (string) ob_get_clean();
unset( $_GET['view'] );

$h1_found = in_array( $with_h1, $runner->posts_with( 'h1' ), true );

// Похожие тексты: отпечатки в новом столбце таблицы, пары в отчёте. Закрытая копия, короткая запись и колода не сравниваются.
$fingerprint_of = static fn( int $post_id ): string => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT fingerprint FROM %i WHERE post_id = %d', $runner->table(), $post_id ) );
$similar        = $runner->near_duplicates();
$similar_ids    = array();
$twins_similar  = false;
foreach ( $similar as $similar_pair ) {
	$similar_ids[] = $similar_pair['a'];
	$similar_ids[] = $similar_pair['b'];
	$twins_similar = $twins_similar || ( min( $twin_a, $twin_b ) === $similar_pair['a'] && max( $twin_a, $twin_b ) === $similar_pair['b'] );
}

ob_start();
$_GET['view'] = 'near_duplicates';
( new AuditReport( $runner ) )->render();
$near_html = (string) ob_get_clean();
unset( $_GET['view'] );

// Строка записи обновляется при сохранении: после переименования пары больше нет.
wp_update_post( array( 'ID' => $twin_b, 'post_title' => 'Уже другое название' ) );
$pair_after = false;
foreach ( $runner->duplicate_groups( 'title_hash' ) as $group ) {
	$pair_after = $pair_after || in_array( $twin_a, $group, true );
}

wp_update_post( array( 'ID' => $with_h1, 'post_status' => 'draft' ) );
$h1_after_unpublish = in_array( $with_h1, $runner->posts_with( 'h1' ), true );

$out = array(
	'anonymous_rejected'           => 401 === $anonymous,
	'run_finished'                 => 200 === $status && $result['done'] && $result['processed'] === $result['total'],
	'twins_form_a_pair'            => array( $twin_a, $twin_b ) === $pair,
	'duplicates_counted'           => $summary['duplicate_title_hash'] >= 2,
	'closed_copy_is_not_a_duplicate' => ! in_array( $closed, $pair, true ),
	'extra_h1_found'               => $h1_found,
	'thin_post_found'              => in_array( $thin, $runner->posts_with( 'thin' ), true ) && ! in_array( $twin_a, $runner->posts_with( 'thin' ), true ),
	'auto_description_found'       => in_array( $twin_a, $runner->posts_with( 'auto_description' ), true ) && ! in_array( $manual, $runner->posts_with( 'auto_description' ), true ),
	'closed_post_listed'           => in_array( $closed, $runner->posts_with( 'closed' ), true ),
	'deck_has_description'         => ! in_array( (int) get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID, $runner->posts_with( 'no_description' ), true ),
	'page_shows_summary_and_group' => str_contains( $html, 'data-indexpilot-problem="duplicate_title_hash"' ) && str_contains( $html, 'Группа из 2 записей' ),
	'row_updates_on_save'          => ! $pair_after,
	'unpublished_post_leaves_audit' => ! $h1_after_unpublish,
	'audit_table_upgraded'         => Runner::DB_VERSION === get_option( Runner::DB_OPTION ) && 64 === count( explode( ',', $fingerprint_of( $twin_a ) ) ),
	'short_and_deck_not_compared'  => '' === $fingerprint_of( $thin ) && '' === $fingerprint_of( (int) get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID ),
	'similar_texts_form_a_pair'    => $twins_similar,
	'closed_copy_not_similar'      => ! in_array( $closed, $similar_ids, true ),
	'page_lists_similar_pairs'     => str_contains( $html, 'data-indexpilot-problem="near_duplicates"' ) && str_contains( $near_html, 'data-indexpilot-pair="' . min( $twin_a, $twin_b ) . '-' . max( $twin_a, $twin_b ) . '"' ) && str_contains( $near_html, ' %' ),
);

foreach ( array( $twin_a, $twin_b, $with_h1, $thin, $closed, $manual ) as $id ) {
	wp_delete_post( $id, true );
}

$out['deleted_posts_leave_audit'] = 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id IN ( %d, %d )', $runner->table(), $twin_a, $thin ) );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
