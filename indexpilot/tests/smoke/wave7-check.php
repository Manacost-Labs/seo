<?php
/**
 * Проверка волны 7 внутри тестового WordPress: мета-теги article:tag и rel prev/next, «Проверить страницу»,
 * платное содержимое (обёртка замка, isAccessibleForFree, закрытый текст не попадает в описание, llms.txt и .md),
 * сводка автора и knowsAbout, «Проверил» с версией текста, страницы стримеров, счётчик ассистентов,
 * содержимое без JavaScript, QR-код, nosniff у llms.txt и .md.
 * Отдаёт JSON, каждое значение должно быть true. Создаёт свои записи и удаляет их за собой. Файл не входит в сборку.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use IndexPilot\Admin\HeadInspector;
use IndexPilot\Ai\VisitCounter;
use IndexPilot\Ai\Visits;
use IndexPilot\Audit\RenderedPage;
use IndexPilot\Authors\AuthorPages;
use IndexPilot\Authors\AuthorSummary;
use IndexPilot\Authors\Review;
use IndexPilot\Admin\ReviewBox;
use IndexPilot\Game\StreamerPage;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Schema\IdFactory;
use IndexPilot\ShortLinks\QrCode;

wp_set_current_user( 1 );
// Запись со скриптом в тексте нужна для проверки «без JavaScript».
kses_remove_filters();

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$suffix  = (string) wp_rand( 1000, 9999 );
$created = array();
$terms   = array();
$keep    = isset( $_GET['keep'] );

register_shutdown_function(
	static function () use ( &$created, &$terms, $keep ): void {
		if ( $keep ) {
			return;
		}

		foreach ( $created as $id ) {
			wp_delete_post( $id, true );
		}

		foreach ( $terms as $term ) {
			wp_delete_term( $term[0], $term[1] );
		}
	}
);

$get = static function ( string $url, array $headers = array() ): array {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 90,
			'redirection' => 0,
			'sslverify'   => false,
			'headers'     => $headers,
		)
	);

	return is_wp_error( $response ) ? array( 0, '', array() ) : array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $response );
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

$make_post = static function ( array $args ) use ( &$created ): int {
	$id        = (int) wp_insert_post(
		$args + array(
			'post_status' => 'publish',
			'post_author' => 1,
		)
	);
	$created[] = $id;

	return $id;
};

$guides = get_term_by( 'slug', 'gajdy', 'category' );

// --- 6.12: article:tag, rel prev/next, «Проверить страницу». -------------------------------------------------------

$tagged = $make_post(
	array(
		'post_title'    => 'Гайд с метками ' . $suffix,
		'post_content'  => '<p>Первая часть гайда о колоде жреца.</p><!--nextpage--><p>Вторая часть.</p><!--nextpage--><p>Третья часть.</p>',
		'post_category' => array( (int) $guides->term_id ),
		'tags_input'    => array( 'Жрец' . $suffix, 'Контроль' . $suffix ),
	)
);
update_post_meta( $tagged, MetaKeys::SCHEMA_TYPE, 'Article' );

list( , $tagged_html ) = $get( (string) get_permalink( $tagged ) );
list( , $page_two )    = $get( trailingslashit( (string) get_permalink( $tagged ) ) . '2/' );
$inspected             = HeadInspector::inspect( $tagged_html );
$own_keys              = array_column( array_filter( $inspected['tags'], static fn( array $tag ): bool => 'IndexPilot' === $tag['source'] ), 'key' );

// --- 7.15: закрытая часть. ------------------------------------------------------------------------------------------

$secret = 'СЕКРЕТНЫЙ-РАЗБОР-' . $suffix;
$locked = $make_post(
	array(
		'post_title'    => 'Гайд с замком ' . $suffix,
		'post_content'  => '<p>Открытое вступление: колода сильна против агрессии в текущем патче, играть её просто.</p>[sociallocker]<p>' . $secret . ' муллиган и ходы.</p>[/sociallocker]<p>Итог: берите в ладдер.</p>',
		'post_category' => array( (int) $guides->term_id ),
	)
);
update_post_meta( $locked, MetaKeys::SCHEMA_TYPE, 'Article' );
update_post_meta( $locked, MetaKeys::CORNERSTONE, '1' );
clean_post_cache( $locked );

list( , $locked_html )            = $get( (string) get_permalink( $locked ) );
list( , $locked_md, $md_response ) = $get( untrailingslashit( (string) get_permalink( $locked ) ) . '.md' );
list( , $llms, $llms_response )    = $get( home_url( '/llms.txt' ) );
$locked_article                   = $node_of( $graph_of( $locked_html ), 'Article' );
preg_match( '#<meta name="description" content="([^"]*)"#', $locked_html, $locked_description );

// --- 6.30 и 7.34: сводка автора, «Проверил». ------------------------------------------------------------------------

$reviewer = wp_insert_user(
	array(
		'user_login'   => 'reviewer' . $suffix,
		'user_pass'    => wp_generate_password(),
		'role'         => 'editor',
		'display_name' => 'Проверяющий ' . $suffix,
	)
);

$priest_one = $make_post(
	array(
		'post_title'    => 'Колода жреца на контроль ' . $suffix,
		'post_content'  => '<p>Разбор.</p><h2>Выводы</h2><p>Колода сильна против агрессии.</p>',
		'post_category' => array( (int) $guides->term_id ),
	)
);
$make_post(
	array(
		'post_title'    => 'Жрец: муллиган ' . $suffix,
		'post_content'  => '<p>Разбор муллигана.</p>',
		'post_category' => array( (int) $guides->term_id ),
	)
);
update_post_meta( $priest_one, MetaKeys::SCHEMA_TYPE, 'Article' );

$summary = ( new AuthorPages( new \IndexPilot\Authors\AuthorProfile(), \IndexPilot\Plugin::instance()->lexicon() ) )->refresh( 1 );

list( , $author_html ) = $get( get_author_posts_url( 1 ) );
$author_person         = $node_of( $graph_of( $author_html ), 'Person' );

// Проверяющий подтверждает текущую версию (так сохраняет панель «Проверил»), затем запись сохраняется.
update_post_meta( $priest_one, Review::REVIEWER, (int) $reviewer );
update_post_meta( $priest_one, Review::VERSION, ReviewBox::CONFIRM );
wp_update_post(
	array(
		'ID'           => $priest_one,
		'post_excerpt' => 'Короткое описание.',
	)
);

$version_after_confirm      = (string) get_post_meta( $priest_one, Review::VERSION, true );
list( , $reviewed_html )    = $get( (string) get_permalink( $priest_one ) );
$reviewed_page              = $node_of( $graph_of( $reviewed_html ), 'WebPage' );
$reviewer_id                = IdFactory::person( home_url( '/' ), get_author_posts_url( (int) $reviewer ) );

// Опечатка в середине текста отметку не снимает, смена выводов — снимает.
wp_update_post(
	array(
		'ID'           => $priest_one,
		'post_content' => '<p>Разбор!</p><h2>Выводы</h2><p>Колода сильна против агрессии.</p>',
	)
);
$kept_after_typo = $version_after_confirm === (string) get_post_meta( $priest_one, Review::VERSION, true );

wp_update_post(
	array(
		'ID'           => $priest_one,
		'post_content' => '<p>Разбор!</p><h2>Выводы</h2><p>Колода слаба против агрессии.</p>',
	)
);
$stale                 = '' === (string) get_post_meta( $priest_one, Review::VERSION, true ) && (int) $reviewer === (int) get_post_meta( $priest_one, Review::REVIEWER, true );
list( , $stale_html )  = $get( (string) get_permalink( $priest_one ) );
$stale_page            = $node_of( $graph_of( $stale_html ), 'WebPage' );

// --- 6.31: страница стримера. ---------------------------------------------------------------------------------------

$streamer_name = 'PizzaHS' . $suffix;
$streamer      = wp_insert_term( $streamer_name, StreamerPage::TAXONOMY, array( 'slug' => 'pizzahs-' . $suffix ) );
$streamer      = (int) $streamer['term_id'];
$terms[]       = array( $streamer, StreamerPage::TAXONOMY );
update_term_meta( $streamer, StreamerPage::TWITCH, 'https://www.twitch.tv/pizzahs' );
update_term_meta( $streamer, StreamerPage::ABOUT, 'Играет на легенде с 2016 года.' );

$deck = $make_post(
	array(
		'post_type'    => 'hs_deck',
		'post_title'   => 'Маг стримера ' . $suffix,
		'post_content' => '<p>Колода.</p>',
	)
);
update_post_meta( $deck, '_deck_player', $streamer_name );
wp_set_object_terms( $deck, array( $streamer ), StreamerPage::TAXONOMY );
$archived = $make_post(
	array(
		'post_type'    => 'hs_deck',
		'post_title'   => 'Старая колода стримера ' . $suffix,
		'post_content' => '<p>Колода.</p>',
	)
);
update_post_meta( $archived, '_hs_deck_archived', '1' );
wp_set_object_terms( $archived, array( $streamer ), StreamerPage::TAXONOMY );

list( , $streamer_html ) = $get( (string) get_term_link( $streamer, StreamerPage::TAXONOMY ) );
list( , $deck_html )     = $get( (string) get_permalink( $deck ) );
$streamer_graph          = $graph_of( $streamer_html );
$profile_page            = $node_of( $streamer_graph, 'ProfilePage' );
$streamer_person         = $node_of( $streamer_graph, 'Person' );
$deck_person             = $node_of( $graph_of( $deck_html ), 'Person' );
preg_match( '#<title>(.*?)</title>#s', $streamer_html, $streamer_title );

// --- 6.23: заходы ассистентов. --------------------------------------------------------------------------------------

$gajd = (string) get_permalink( get_page_by_path( 'gajd-4', OBJECT, 'post' ) );
$path = Visits::path( (string) wp_parse_url( $gajd, PHP_URL_PATH ) );

for ( $i = 0; $i < 3; $i++ ) {
	$get( $gajd, array( 'User-Agent' => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)' ) );
}

$get( $gajd, array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36', 'Referer' => 'https://chatgpt.com/' ) );
// Обычный посетитель и робот поиска не засчитываются.
$get( $gajd, array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36' ) );

$counter = new VisitCounter( $GLOBALS['wpdb'] );
wp_cache_delete( VisitCounter::BUFFER_OPTION, 'options' );
wp_cache_delete( 'notoptions', 'options' );
$buffered = Visits::rows( (array) get_option( VisitCounter::BUFFER_OPTION, array() ) );
$counter->flush();

$bot_hits = 0;
foreach ( $counter->top( Visits::BOT ) as $row ) {
	$bot_hits += $path === $row['path'] && 'OAI-SearchBot' === $row['agent'] ? $row['hits'] : 0;
}

$ref_hits = 0;
foreach ( $counter->top( Visits::REFERRAL ) as $row ) {
	$ref_hits += $path === $row['path'] && 'ChatGPT' === $row['agent'] ? $row['hits'] : 0;
}

// --- 7.4: содержимое без JavaScript. --------------------------------------------------------------------------------

$scripted = $make_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Колода скриптом ' . $suffix,
		'post_content' => '<div id="deck-' . $suffix . '"></div><script>document.getElementById("deck-' . $suffix . '").textContent = "2 Огненный шар, 2 Пиромант";</script>',
	)
);

list( , $scripted_html ) = $get( (string) get_permalink( $scripted ) );
list( , $gajd_html )     = $get( $gajd );

// --- 7.24: QR-код короткой ссылки. ----------------------------------------------------------------------------------

$qr = QrCode::encode( home_url( '/go/pzz' ) )->svg();

echo wp_json_encode(
	array(
		'meta_tags'     => array(
			'article_tags'           => 2 <= substr_count( $tagged_html, '<meta property="article:tag" content="' ),
			'rel_next_on_first_page' => str_contains( $tagged_html, '<link rel="next" href="' . trailingslashit( (string) get_permalink( $tagged ) ) . '2/">' ),
			'no_rel_prev_on_first'   => ! str_contains( $tagged_html, '<link rel="prev"' ),
			'rel_prev_on_second'     => str_contains( $page_two, '<link rel="prev" href="' . (string) get_permalink( $tagged ) . '">' ),
			'inspector_no_dupes'     => array() === $inspected['duplicates'],
			'inspector_sees_ours'    => in_array( 'description', $own_keys, true ) && in_array( 'canonical', $own_keys, true ),
			'inspector_sees_schema'  => in_array( 'ld+json', array_column( $inspected['tags'], 'key' ), true ),
		),
		'paywall'       => array(
			'wrapped'              => str_contains( $locked_html, '<div class="indexpilot-locked">' ),
			'not_free'             => false === ( $locked_article['isAccessibleForFree'] ?? null ),
			'has_part_selector'    => '.indexpilot-locked' === ( $locked_article['hasPart']['cssSelector'] ?? '' ),
			'description_clean'    => isset( $locked_description[1] ) && ! str_contains( $locked_description[1], $secret ) && str_contains( $locked_description[1], 'Открытое вступление' ),
			'markdown_clean'       => '' !== $locked_md && ! str_contains( $locked_md, $secret ) && str_contains( $locked_md, 'Открытое вступление' ),
			'llms_clean'           => '' !== $llms && ! str_contains( $llms, $secret ),
			'markdown_nosniff'     => 'nosniff' === wp_remote_retrieve_header( $md_response, 'x-content-type-options' ),
			'llms_nosniff'         => 'nosniff' === wp_remote_retrieve_header( $llms_response, 'x-content-type-options' ),
			'ordinary_post_free'   => ! array_key_exists( 'isAccessibleForFree', $node_of( $graph_of( $tagged_html ), 'Article' ) ),
		),
		'authors'       => array(
			'summary_counts'       => $summary['count'] >= 2 && '' !== $summary['updated'],
			'summary_knows_priest' => in_array( 'Жрец', $summary['knows_about'], true ),
			'summary_stored'       => is_array( get_user_meta( 1, AuthorSummary::META, true ) ),
			'knows_about_markup'   => in_array( 'Жрец', (array) ( $author_person['knowsAbout'] ?? array() ), true ),
			'confirm_becomes_hash' => 32 === strlen( $version_after_confirm ),
			'reviewed_by_markup'   => $reviewer_id === ( $reviewed_page['reviewedBy']['@id'] ?? '' ) && '' !== ( $reviewed_page['lastReviewed'] ?? '' ),
			'typo_keeps_review'    => $kept_after_typo,
			'new_verdict_unmarks'  => $stale,
			'stale_not_in_markup'  => array() !== $stale_page && ! isset( $stale_page['reviewedBy'] ),
		),
		'streamer'      => array(
			'title_template'      => isset( $streamer_title[1] ) && str_contains( html_entity_decode( $streamer_title[1] ), 'Колоды ' . $streamer_name . ' для Hearthstone' ),
			'profile_page'        => array() !== $profile_page,
			'person_same_id'      => '' !== ( $streamer_person['@id'] ?? '' ) && ( $streamer_person['@id'] ?? '' ) === ( $deck_person['@id'] ?? 'x' ) && ( $profile_page['mainEntity']['@id'] ?? '' ) === $streamer_person['@id'],
			'person_twitch'       => array( 'https://www.twitch.tv/pizzahs' ) === ( $streamer_person['sameAs'] ?? array() ),
			'deck_person_url'     => (string) get_term_link( $streamer, StreamerPage::TAXONOMY ) === ( $deck_person['url'] ?? '' ),
			'current_decks_shown' => str_contains( $streamer_html, 'indexpilot-streamer' ) && str_contains( $streamer_html, 'Маг стримера ' . $suffix ) && ! str_contains( (string) strstr( $streamer_html, 'indexpilot-streamer' ), 'Старая колода стримера ' . $suffix . '</a></li>' ),
		),
		'ai'            => array(
			'buffered_not_written' => 0 < count( $buffered ),
			'bot_one_row_three'    => 3 === $bot_hits,
			'referral_counted'     => 1 === $ref_hits,
		),
		'no_js'         => array(
			'script_page_flagged'  => RenderedPage::analyze( $scripted_html )['hidden'],
			'text_page_ok'         => ! RenderedPage::analyze( $gajd_html )['hidden'],
		),
		'qr'            => array(
			'svg' => str_starts_with( $qr, '<svg xmlns="http://www.w3.org/2000/svg"' ) && str_contains( $qr, '<path fill="#000" d="M' ),
		),
	),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
