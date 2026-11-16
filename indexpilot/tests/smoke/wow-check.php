<?php
/**
 * Проверка раздела World of Warcraft внутри тестового WordPress: раздел «World of Warcraft → Жрец → Тьма» с гайдами,
 * игра записи в её поле, разметка about, хлебные крошки, занятый ключ и подсказки ссылок в пределах игры,
 * замечание «добавьте название игры», упоминания классов, шаблоны и блок-ответ рубрик раздела, llms.txt.
 * Отдаёт JSON, каждое значение должно быть true. Проверка создаёт свои записи и рубрики и удаляет их за собой
 * (с параметром ?keep=1 оставляет). Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\KeywordUsage;
use IndexPilot\Analysis\PaperFactory;
use IndexPilot\Game\GameScope;
use IndexPilot\Game\MentionIndex;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Game\SiteGames;
use IndexPilot\Links\SuggestionIndex;
use IndexPilot\Links\Suggester;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;
use IndexPilot\Settings\Settings;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode(
			array(
				'error' => $error->getMessage(),
				'where' => $error->getFile() . ':' . $error->getLine(),
			),
			JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}
);

global $wpdb;

$plugin  = Plugin::instance();
$suffix  = (string) wp_rand( 1000, 9999 );
$created = array();
$terms   = array();
$saved   = get_option( Settings::OPTION, array() );
$keep    = isset( $_GET['keep'] );

register_shutdown_function(
	static function () use ( &$created, &$terms, $saved, $keep ): void {
		if ( $keep ) {
			return;
		}

		foreach ( $created as $id ) {
			wp_delete_post( $id, true );
		}

		foreach ( array_reverse( $terms ) as $term_id ) {
			wp_delete_term( $term_id, 'category' );
		}

		update_option( Settings::OPTION, $saved );
		delete_option( MentionIndex::OPTION );
		delete_option( SuggestionIndex::OPTION );
	}
);

$get = static function ( string $url ): array {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 90,
			'redirection' => 0,
			'sslverify'   => false,
		)
	);

	return is_wp_error( $response ) ? array( 0, '' ) : array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
};

$graph_of = static function ( string $html ): array {
	if ( ! preg_match( '#<script type="application/ld\+json" class="indexpilot-schema">(.*?)</script>#s', $html, $found ) ) {
		return array();
	}

	return (array) ( json_decode( $found[1], true )['@graph'] ?? array() );
};

$node_of = static function ( array $graph, string $type ): array {
	foreach ( $graph as $node ) {
		if ( $type === ( $node['@type'] ?? '' ) ) {
			return $node;
		}
	}

	return array();
};

// --- Фикстура: раздел «World of Warcraft → Жрец → Тьма» ------------------------------------------------------------

$root   = (int) wp_insert_term( 'World of Warcraft', 'category', array( 'slug' => 'wow-' . $suffix ) )['term_id'];
$priest = (int) wp_insert_term(
	'Жрец',
	'category',
	array(
		'slug'   => 'wow-zhrec-' . $suffix,
		'parent' => $root,
	)
)['term_id'];
$shadow = (int) wp_insert_term(
	'Тьма',
	'category',
	array(
		'slug'   => 'wow-tma-' . $suffix,
		'parent' => $priest,
	)
)['term_id'];
$terms  = array( $root, $priest, $shadow );
$hs_cat = get_term_by( 'slug', 'gajdy', 'category' );

$settings                                 = is_array( $saved ) ? $saved : array();
$settings['general']['wow_root_term']     = (string) $root;
$settings['general']['wow_patch_version'] = '11.2';
$settings['general']['wow_patch_date']    = '2020-01-01';
update_option( Settings::OPTION, $settings );

// Настройки уже прочитаны этим запросом: читаем заново. Игры сайта берут их через плагин и сразу видят раздел.
( fn() => $this->settings = null )->call( $plugin );
$fresh = $plugin->settings();
$games = $plugin->game()->games();

$make_post = static function ( string $title, int $category, string $keyword, string $content ) use ( &$created, $suffix ): int {
	$id = (int) wp_insert_post(
		array(
			'post_title'    => $title,
			'post_name'     => sanitize_title( $title ) . '-' . $suffix,
			'post_status'   => 'publish',
			'post_content'  => $content,
			'post_category' => array( $category ),
			'post_author'   => 1,
			'meta_input'    => '' !== $keyword ? array( MetaKeys::FOCUS_KEYWORD => $keyword ) : array(),
		)
	);

	$created[] = $id;

	return $id;
};

$wow_text = '<p>Жрец Тьмы в рейде наносит урон тьмой.</p><h2>Таланты</h2><p>Текст.</p><h2>Характеристики</h2><p>Текст.</p>';
$guide    = $make_post( 'Гайд на жреца Тьмы', $shadow, 'гайд на жреца', $wow_text );
$second   = $make_post( 'Ротация жреца Тьмы в рейде', $shadow, '', $wow_text );
$third    = $make_post( 'Таланты жреца Тьмы для эпохальных ключей', $shadow, '', $wow_text );
$hs_guide = $make_post( 'Гайд на жреца через контроль', (int) $hs_cat->term_id, 'гайд на жреца', '<p>Колода жреца на контроле.</p>' );
$hs_twin  = $make_post( 'Жрец на контроле: гайд', (int) $hs_cat->term_id, 'гайд на жреца', '<p>Ещё одна колода жреца.</p>' );

// Игра записи запоминается при сохранении.
$saved_on_insert = 'wow' === get_post_meta( $guide, SiteGames::META, true ) && '' === get_post_meta( $hs_guide, SiteGames::META, true );

// Пересчёт игры записей после смены раздела идёт задачей расписания; здесь он выполняется сразу.
$counts = $games->rebuild();

// --- Игра записи ----------------------------------------------------------------------------------------------------

$guide_post = get_post( $guide );

// --- Занятый ключ и название игры в заголовке ------------------------------------------------------------------------

$analyzer = new Analyzer( null, $plugin->analyzer()->matcher(), static fn( string $game ) => $games->matcher( $game ) );
$papers   = new PaperFactory( $plugin->resolver(), $fresh, null, null, null, null, new KeywordUsage( $wpdb, $plugin->analyzer()->matcher() ), $games );
$all      = new PaperFactory( $plugin->resolver(), $fresh, null, null, null, null, new KeywordUsage( $wpdb, $plugin->analyzer()->matcher() ) );
$paper    = $papers->create( new Context( Context::POST, $guide, 'post' ) );
$hs_paper = $papers->create( new Context( Context::POST, $hs_guide, 'post' ) );
$results  = array();

foreach ( $analyzer->analyze( $paper )->results as $result ) {
	$results[ $result->id ] = $result;
}

// --- Подсказки ссылок и упоминания ---------------------------------------------------------------------------------

$suggestions = ( new SuggestionIndex( $wpdb, new Suggester( $plugin->analyzer()->matcher() ), $plugin->redirected_posts(), 'wow-check-' . $suffix, $games ) )->suggest( $guide, 'Этот гайд на жреца через контроль и ротация жреца Тьмы в рейде.' );
$suggested   = array_column( $suggestions, 'url' );

$mentions = new MentionIndex( fn() => $plugin->lexicon(), fn() => $games );
$mentions->rebuild();
$wow_found = $mentions->mentions_for( 'wow' )->find( 'Сегодня жрец в рейде' );
$hs_found  = $mentions->mentions_for( 'hearthstone' )->find( 'Сегодня жрец в рейде' );

// --- Страницы сайта ------------------------------------------------------------------------------------------------

list( $guide_status, $guide_html ) = $get( (string) get_permalink( $guide ) );
$article                           = $node_of( $graph_of( $guide_html ), 'Article' );
$crumbs                            = array_column( (array) ( $node_of( $graph_of( $guide_html ), 'BreadcrumbList' )['itemListElement'] ?? array() ), 'name' );
$trail                             = array_column( $plugin->trail()->for_context( new Context( Context::POST, $guide, 'post' ) ), 'name' );

list( $hs_status, $hs_html ) = $get( (string) get_permalink( $hs_guide ) );
$hs_article                  = $node_of( $graph_of( $hs_html ), 'Article' );

list( $shadow_status, $shadow_html ) = $get( (string) get_term_link( $shadow, 'category' ) );
$shadow_title                        = preg_match( '#<title>(.*?)</title>#s', $shadow_html, $found ) ? html_entity_decode( $found[1], ENT_QUOTES, 'UTF-8' ) : '';
$collection                          = $node_of( $graph_of( $shadow_html ), 'CollectionPage' );

list( , $llms ) = $get( home_url( '/llms.txt' ) );
$wow_part       = preg_match( '/## World of Warcraft\n(.*?)(\n## |\z)/s', $llms, $part ) ? $part[1] : '';
$sections_part  = preg_match( '/## Разделы\n(.*?)(\n## |\z)/s', $llms, $part ) ? $part[1] : '';
$shadow_url     = (string) get_term_link( $shadow, 'category' );

echo wp_json_encode(
	array(
		'game'  => array(
			'saved_on_insert'   => $saved_on_insert,
			'wow_guide_meta'    => 'wow' === get_post_meta( $guide, SiteGames::META, true ),
			'hs_guide_no_meta'  => '' === get_post_meta( $hs_guide, SiteGames::META, true ),
			'rebuild_counted'   => 3 === ( $counts['wow'] ?? 0 ),
			'term_game'         => 'wow' === $games->term_game( $shadow ) && 'hearthstone' === $games->term_game( (int) $hs_cat->term_id ),
			'wow_material_type' => 'Гайд по классу и специализации' === PostMaterial::label( $guide_post ),
			'wow_patch_current' => $games->patch( 'wow' )->current( PostMaterial::detect( $guide_post ), (string) $guide_post->post_modified ),
		),
		'scope' => array(
			'wow_key_not_taken_by_hs'  => array() === $paper->duplicates,
			'hs_key_taken_by_hs_twin'  => in_array( 'Жрец на контроле: гайд', $hs_paper->duplicates, true ) && ! in_array( 'Гайд на жреца Тьмы', $hs_paper->duplicates, true ),
			'without_games_all_taken'  => array() !== $all->create( new Context( Context::POST, $guide, 'post' ) )->duplicates,
			'add_game_name'            => isset( $results['game_in_title'] ) && 'bad' === $results['game_in_title']->status && str_contains( $results['game_in_title']->message, 'World of Warcraft или WoW' ),
			'hs_has_no_game_note'      => array() === $hs_paper->missing_game_names,
			'wow_sections_checked'     => isset( $results['material_sections'] ) && str_contains( $results['material_sections']->message, 'Ротация' ),
			'suggests_same_game'       => in_array( (string) get_permalink( $second ), $suggested, true ),
			'no_suggestion_other_game' => ! in_array( (string) get_permalink( $hs_guide ), $suggested, true ),
			'wow_mention_wow_page'     => (string) get_term_link( $priest, 'category' ) === ( $wow_found[0]['url'] ?? '' ),
			'hs_mention_not_wow_page'  => (string) get_term_link( $priest, 'category' ) !== ( $hs_found[0]['url'] ?? '' ),
			'cross_game_report'        => in_array( 'гайд на жреца', array_column( $plugin->audit()->cross_game_keywords(), 'keyword' ), true ),
			'title_check_pure'         => array( 'World of Warcraft', 'WoW' ) === GameScope::missing_names( 'Гайд на жреца', 'wow', $games->registry() ),
		),
		'site'  => array(
			'guide_200'           => 200 === $guide_status,
			'about_wow'           => 'World of Warcraft' === ( $article['about']['name'] ?? '' ),
			'hs_about_hs'         => 200 === $hs_status && 'Hearthstone' === ( $hs_article['about']['name'] ?? '' ),
			'crumbs_game_section' => array( 'World of Warcraft', 'Жрец', 'Тьма' ) === array_slice( $crumbs, -4, 3 ),
			'trail_game_section'  => in_array( 'World of Warcraft', $trail, true ) && in_array( 'Тьма', $trail, true ),
			'term_200'            => 200 === $shadow_status,
			'term_title_template' => str_starts_with( $shadow_title, 'Жрец Тьма — гайды World of Warcraft' ),
			'term_about_wow'      => 'World of Warcraft' === ( $collection['about']['name'] ?? '' ),
			'hub_from_guides'     => str_contains( $shadow_html, 'Свежий гайд для жреца (Тьма)' ),
			'llms_wow_section'    => str_contains( $wow_part, $shadow_url ),
			'llms_sections_clean' => ! str_contains( $sections_part, $shadow_url ),
		),
		'debug' => array(
			'crumbs' => implode( ' > ', $crumbs ),
			'title'  => $shadow_title,
			'dups'   => $paper->duplicates,
		),
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
