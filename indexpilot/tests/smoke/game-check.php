<?php
/**
 * Проверка игрового модуля внутри тестового WordPress: данные колоды, переменные, версии, ссылки.
 * Отдаёт JSON и пишет адреса созданных страниц в game-urls.json — их использует check-sitemap.php.
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Game\Module;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$plugin  = Plugin::instance();
$game    = $plugin->game();
$current = (int) get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID;

$make_deck = static function ( string $slug, string $date, string $archetype, bool $archived ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'    => 'hs_deck',
			'post_title'   => $archetype,
			'post_name'    => $slug,
			'post_content' => '',
			'post_status'  => 'publish',
			'post_date'    => $date,
		)
	);

	wp_set_object_terms( $id, 'Рыцарь Смерти', 'deck_class' );
	wp_set_object_terms( $id, 'Стандарт', 'deck_mode' );
	wp_set_object_terms( $id, $archetype, 'deck_archetype' );
	update_post_meta( $id, '_hs_archetype_key', mb_strtolower( $archetype ) );
	update_post_meta( $id, '_hs_deck_archived', $archived ? '1' : '0' );
	update_post_meta( $id, '_deck_player', 'PizzaHS' );

	return $id;
};

update_post_meta( $current, '_hs_archetype_key', 'blood dk' );
update_post_meta( $current, '_hs_deck_archived', '0' );

$old      = $make_deck( 'blood-dk-old', '2026-10-01 10:00:00', 'Blood DK', false );
// Архивная версия новее актуальной, но актуальной не становится.
$archived = $make_deck( 'blood-dk-archived', '2026-11-15 12:00:00', 'Blood DK', true );
$other    = $make_deck( 'frost-dk', '2026-11-12 10:00:00', 'Frost DK', false );

$guide = (int) wp_insert_post(
	array(
		'post_title'   => 'Гайд: Blood DK в новом сезоне',
		'post_name'    => 'gajd-blood-dk',
		'post_content' => '<p>Разбор колоды.</p>',
		'post_status'  => 'publish',
		'post_author'  => 1,
	)
);

$scheduled = false !== wp_next_scheduled( Module::REBUILD_HOOK );
$map       = $game->rebuild_versions();

$deck     = $game->decks()->deck( $current );
$old_deck = $game->decks()->deck( $old );
$resolver = $plugin->resolver();
$context  = static fn( int $id ): Context => new Context( Context::POST, $id, 'hs_deck' );

$variables = $plugin->renderer()->render( '%deck_class%|%deck_mode%|%deck_archetype%|%deck_dust%{{|игрок %deck_player%}}', $context( $current ) );
$content   = (string) apply_filters( 'indexpilot_analysis_content', '', $context( $old ) );
$links     = $game->links_html( $old );

// Каноникал, заданный вручную, важнее правила версий.
update_post_meta( $archived, '_indexpilot_canonical', 'https://example.com/manual/' );

$archetype = get_term_by( 'name', 'Blood DK', 'deck_archetype' );

file_put_contents(
	__DIR__ . '/game-urls.json',
	wp_json_encode(
		array(
			'current'   => get_permalink( $current ),
			'old'       => get_permalink( $old ),
			'other'     => get_permalink( $other ),
			'archetype' => get_term_link( $archetype ),
		),
		JSON_UNESCAPED_SLASHES
	)
);

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'deck_fields'                => 'Рыцарь Смерти' === $deck->hero_class && 'Стандарт' === $deck->mode && 9400 === $deck->dust && '' === $deck->player && 'blood dk|рыцарь смерти|стандарт' === $deck->group,
		'player_is_read'             => 'PizzaHS' === $old_deck->player,
		'variables'                  => $variables,
		'variables_work'             => 'Рыцарь Смерти|Стандарт|Blood DK|9400' === $variables,
		'rebuild_scheduled_on_change' => $scheduled,
		'newest_live_is_current'     => $current === $map[ $current ] && $current === $map[ $old ] && $current === $map[ $archived ],
		'other_archetype_is_separate' => $other === $map[ $other ],
		'old_version_points_to_current' => get_permalink( $current ) === $resolver->resolve( $context( $old ) )->canonical,
		'current_keeps_own_canonical' => get_permalink( $current ) === $resolver->resolve( $context( $current ) )->canonical,
		'manual_canonical_wins'      => 'https://example.com/manual/' === $resolver->resolve( $context( $archived ) )->canonical,
		'old_version_stays_indexable_by_robots' => $resolver->resolve( $context( $old ) )->is_indexable(),
		'analysis_gets_deck_text'    => str_contains( $content, 'Колода «Blood DK» для Hearthstone' ) && str_contains( $content, 'PizzaHS' ),
		'links_have_archetype'       => str_contains( $links, 'indexpilot-deck-archetype' ) && str_contains( $links, (string) get_term_link( $archetype ) ),
		'links_have_guide'           => str_contains( $links, (string) get_permalink( $guide ) ),
		'links_have_current_deck'    => str_contains( $links, (string) get_permalink( $current ) ),
		'links_skip_itself_and_old'  => ! str_contains( $links, 'href="' . get_permalink( $old ) . '"' ) && ! str_contains( $links, (string) get_permalink( $archived ) ),
		'links_skip_other_archetype' => ! str_contains( $links, (string) get_permalink( $other ) ),
		'shortcode_and_function'     => shortcode_exists( 'indexpilot_deck_links' ) && function_exists( 'indexpilot_deck_links' ),
		'no_links_for_ordinary_post' => '' === $game->links_html( $guide ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
