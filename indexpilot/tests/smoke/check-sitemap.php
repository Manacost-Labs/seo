<?php
/**
 * Проверяет карту сайта запущенного тестового WordPress.
 * Запуск: php tests/smoke/check-sitemap.php — в конце полного прогона (см. run-all.sh):
 * к этому моменту на сайте есть записи с noindex, под паролем и под редиректом.
 */

declare( strict_types=1 );

$urls = json_decode( (string) file_get_contents( __DIR__ . '/urls.json' ), true );
if ( ! is_array( $urls ) ) {
	fwrite( STDERR, "urls.json not found: run the site blueprint first\n" );
	exit( 2 );
}

$home = rtrim( $urls['home'], '/' );

/**
 * Загружает адрес.
 *
 * @return array{status: int, headers: string, body: string}
 */
function indexpilot_fetch( string $url ): array {
	$context = stream_context_create(
		array(
			'http' => array(
				'ignore_errors'   => true,
				'timeout'         => 60,
				'follow_location' => 0,
				// Проверка ходит как посетитель с браузером: запросы без строки браузера плагин считает роботами.
				'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
			),
		)
	);
	$body    = (string) file_get_contents( $url, false, $context );
	$headers = implode( "\n", $http_response_header ?? array() );
	$status  = preg_match( '/^HTTP\/\S+\s(\d{3})/', $headers, $m ) ? (int) $m[1] : 0;

	return array(
		'status'  => $status,
		'headers' => strtolower( $headers ),
		'body'    => $body,
	);
}

/**
 * Адреса из XML карты.
 *
 * @return list<string>
 */
function indexpilot_locs( string $xml ): array {
	$document = @simplexml_load_string( $xml );
	$locs     = array();

	if ( false !== $document ) {
		foreach ( $document->children() as $node ) {
			$locs[] = rawurldecode( (string) $node->loc );
		}
	}

	return $locs;
}

/**
 * Даты изменения из XML карты: адрес => дата.
 *
 * @return array<string, string>
 */
function indexpilot_lastmods( string $xml ): array {
	$document = @simplexml_load_string( $xml );
	$dates    = array();

	if ( false !== $document ) {
		foreach ( $document->children() as $node ) {
			$dates[ rawurldecode( (string) $node->loc ) ] = (string) $node->lastmod;
		}
	}

	return $dates;
}

$checks = array();
// Сам /sitemap.xml в Playground проверить нельзя: служебный модуль этой среды перенаправляет его
// на /wp-sitemap.xml ещё до загрузки плагинов. Список файлов проверяется по второму адресу.
$index  = indexpilot_fetch( $home . '/sitemap_index.xml' );
$files  = indexpilot_locs( $index['body'] );

$checks['index: status 200']             = 200 === $index['status'];
$checks['index: xml content type']       = str_contains( $index['headers'], 'content-type: application/xml' );
$checks['index: not indexed itself']     = str_contains( $index['headers'], 'x-robots-tag: noindex' );
$checks['index: lists posts']            = in_array( $home . '/sitemap-pt-post-1.xml', $files, true );
$checks['index: lists pages']            = in_array( $home . '/sitemap-pt-page-1.xml', $files, true );
$checks['index: lists decks']            = in_array( $home . '/sitemap-pt-hs_deck-1.xml', $files, true );
$checks['index: lists categories']       = in_array( $home . '/sitemap-tax-category-1.xml', $files, true );
$checks['index: lists archetypes']       = in_array( $home . '/sitemap-tax-deck_archetype-1.xml', $files, true );
$checks['index: no attachments']         = ! in_array( $home . '/sitemap-pt-attachment-1.xml', $files, true );

$posts = indexpilot_fetch( $home . '/sitemap-pt-post-1.xml' );
$locs  = indexpilot_locs( $posts['body'] );

$checks['posts: status 200']             = 200 === $posts['status'];
$checks['posts: ordinary post is in']    = in_array( rawurldecode( $urls['post_auto'] ), $locs, true );
$checks['posts: custom post is in']      = in_array( rawurldecode( $urls['post_custom'] ), $locs, true );
$checks['posts: noindex post is out']    = ! in_array( rawurldecode( $urls['post_noindex'] ), $locs, true );
$checks['posts: protected post is out']  = ! in_array( rawurldecode( $urls['post_protected'] ), $locs, true );
$checks['posts: redirected post is out'] = ! in_array( $home . '/gajd-3/', $locs, true );
$checks['posts: has lastmod']            = 1 === preg_match( '#<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00</lastmod>#', $posts['body'] );
$checks['posts: video namespace']        = str_contains( $posts['body'], 'xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"' );

// Ролик в карте — только с полными данными: у каждого video:video есть превью, название, описание и адрес плеера.
$video_ok = false !== @simplexml_load_string( $posts['body'] );
if ( $video_ok ) {
	$video_doc = simplexml_load_string( $posts['body'] );
	$video_doc->registerXPathNamespace( 'video', 'http://www.google.com/schemas/sitemap-video/1.1' );
	foreach ( (array) $video_doc->xpath( '//video:video' ) as $video_node ) {
		$video_ok = $video_ok && 4 === count( $video_node->children( 'http://www.google.com/schemas/sitemap-video/1.1' ) ) && '' !== trim( (string) $video_node->children( 'http://www.google.com/schemas/sitemap-video/1.1' )->player_loc );
	}
}
$checks['posts: videos are complete']    = $video_ok;

$pages = indexpilot_locs( indexpilot_fetch( $home . '/sitemap-pt-page-1.xml' )['body'] );
$decks = indexpilot_locs( indexpilot_fetch( $home . '/sitemap-pt-hs_deck-1.xml' )['body'] );
$cats  = indexpilot_locs( indexpilot_fetch( $home . '/sitemap-tax-category-1.xml' )['body'] );
$tags  = indexpilot_fetch( $home . '/sitemap-tax-post_tag-1.xml' );

$checks['pages: home is first']          = ( $pages[0] ?? '' ) === $home . '/';
$checks['decks: deck is in']             = in_array( rawurldecode( $urls['deck'] ), $decks, true );
$checks['categories: category is in']    = in_array( rtrim( rawurldecode( $urls['category'] ), '/' ) . '/', $cats, true );
$checks['tags: thin tag is out']         = ! in_array( rawurldecode( $urls['thin_tag'] ), indexpilot_locs( $tags['body'] ), true );

$checks['missing page: 404']             = 404 === indexpilot_fetch( $home . '/sitemap-pt-post-2.xml' )['status'];
$checks['unknown type: 404']             = 404 === indexpilot_fetch( $home . '/sitemap-pt-attachment-1.xml' )['status'];
$checks['core sitemap is off']           = 200 !== indexpilot_fetch( $home . '/wp-sitemap.xml' )['status'];

// Пустые файлы: в тестовом сайте единственная метка «тонкая», поэтому карты меток нет ни в списке, ни по прямому адресу.
$listed_ok = array() !== $files;
foreach ( $files as $file ) {
	$response  = indexpilot_fetch( $file );
	$listed_ok = $listed_ok && 200 === $response['status'] && array() !== indexpilot_locs( $response['body'] );
}

$checks['empty file: tag sitemap is not listed']   = ! in_array( $home . '/sitemap-tax-post_tag-1.xml', $files, true );
$checks['empty file: direct request is 404']       = 404 === $tags['status'] && ! str_contains( $tags['body'], '<urlset' );
$checks['empty file: every listed file has urls']  = $listed_ok;

// Дата изменения у архивов терминов и авторов и у их файлов в списке.
$w3c          = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/';
$index_dates  = indexpilot_lastmods( $index['body'] );
$cat_dates    = indexpilot_lastmods( indexpilot_fetch( $home . '/sitemap-tax-category-1.xml' )['body'] );
$author_dates = indexpilot_lastmods( indexpilot_fetch( $home . '/sitemap-authors-1.xml' )['body'] );
$cat_date     = $cat_dates[ rtrim( rawurldecode( $urls['category'] ), '/' ) . '/' ] ?? '';

$checks['lastmod: category archive has a date']   = 1 === preg_match( $w3c, $cat_date );
$checks['lastmod: every category has a date']     = array() !== $cat_dates && ! in_array( '', $cat_dates, true );
$checks['lastmod: author page has a date']        = 1 === preg_match( $w3c, $author_dates[ $home . '/author/admin/' ] ?? '' );
$checks['lastmod: category file in index']        = 1 === preg_match( $w3c, $index_dates[ $home . '/sitemap-tax-category-1.xml' ] ?? '' );
$checks['lastmod: authors file in index']         = 1 === preg_match( $w3c, $index_dates[ $home . '/sitemap-authors-1.xml' ] ?? '' );

// Карта новостей: только записи типа post за последние 48 часов.
$news      = indexpilot_fetch( $home . '/sitemap-news.xml' );
$news_locs = indexpilot_locs( $news['body'] );
$news_xml  = @simplexml_load_string( $news['body'] );
$news_ok   = false !== $news_xml && count( $news_xml->url ) > 0;

if ( false !== $news_xml ) {
	foreach ( $news_xml->url as $node ) {
		$item    = $node->children( 'http://www.google.com/schemas/sitemap-news/0.9' )->news;
		$news_ok = $news_ok
			&& 'Manacost' === (string) $item->publication->name
			&& 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2})?$/', (string) $item->publication->language )
			&& 1 === preg_match( $w3c, (string) $item->publication_date )
			&& '' !== trim( (string) $item->title );
	}
}

$checks['news: status 200']               = 200 === $news['status'] && str_contains( $news['headers'], 'content-type: application/xml' );
$checks['news: listed in index']          = in_array( $home . '/sitemap-news.xml', $files, true );
$checks['news: fresh post is in']         = in_array( $home . '/novost-1/', $news_locs, true );
$checks['news: old post is out']          = ! in_array( rawurldecode( $urls['post_auto'] ), $news_locs, true ) && ! in_array( $home . '/gajd-12/', $news_locs, true );
$checks['news: protected post is out']    = ! in_array( rawurldecode( $urls['post_protected'] ), $news_locs, true );
$checks['news: only posts']               = ! in_array( rawurldecode( $urls['deck'] ), $news_locs, true );
$checks['news: publication, date, title'] = $news_ok;
$checks['news: not more than 1000']       = count( $news_locs ) <= 1000;

// После снятия записи с публикации кэш карты сбрасывается, и записи в карте больше нет.
$last = $home . '/gajd-12/';
$checks['cache: post is in before']      = in_array( $last, $locs, true );
indexpilot_fetch( $home . '/wp-content/plugins/indexpilot/tests/smoke/sitemap-touch.php' );
$checks['cache: post is out after unpublishing'] = ! in_array( $last, indexpilot_locs( indexpilot_fetch( $home . '/sitemap-pt-post-1.xml' )['body'] ), true );

// Авторы: профиль пользователя admin заполнен проверкой authors-check.php.
$author_url  = $home . '/author/admin/';
$author_page = indexpilot_fetch( $author_url )['body'];
$post_page   = indexpilot_fetch( $urls['post_auto'] )['body'];

$graph_of = static function ( string $html ): array {
	preg_match( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $block );

	return json_decode( $block[1] ?? '', true )['@graph'] ?? array();
};
$node_of  = static function ( array $graph, string $type ): array {
	foreach ( $graph as $node ) {
		if ( $type === $node['@type'] ) {
			return $node;
		}
	}

	return array();
};

$author_graph = $graph_of( $author_page );
$person       = $node_of( $author_graph, 'Person' );
$post_person  = $node_of( $graph_of( $post_page ), 'Person' );

$checks['author: page title']              = str_contains( $author_page, '<title>admin, стример — Manacost</title>' );
$checks['author: page is indexable']       = 1 === preg_match( '#<meta name=.robots. content=.index, follow#', $author_page );
$checks['author: profile page markup']     = ( $node_of( $author_graph, 'ProfilePage' )['mainEntity']['@id'] ?? 'none' ) === ( $person['@id'] ?? '' );
$checks['author: person has role']         = 'стример' === ( $person['jobTitle'] ?? '' );
$checks['author: person has platforms']    = array( 'https://www.twitch.tv/manacost', 'https://t.me/manacost' ) === ( $person['sameAs'] ?? array() );
$checks['author: same person in article']  = '' !== ( $person['@id'] ?? '' ) && ( $post_person['@id'] ?? 'none' ) === $person['@id'] && ! empty( $post_person['sameAs'] );
$checks['author: meta author on article']  = str_contains( $post_page, '<meta name="author" content="admin">' );
$checks['author: sitemap file in index']   = in_array( $home . '/sitemap-authors-1.xml', $files, true );
$checks['author: page in authors sitemap'] = in_array( $author_url, indexpilot_locs( indexpilot_fetch( $home . '/sitemap-authors-1.xml' )['body'] ), true );

// Игровой модуль: адреса созданы проверкой game-check.php.
$game = json_decode( (string) @file_get_contents( __DIR__ . '/game-urls.json' ), true );

if ( is_array( $game ) ) {
	$old_page       = indexpilot_fetch( $game['old'] )['body'];
	$current_graph  = $graph_of( indexpilot_fetch( $game['current'] )['body'] );
	$deck_node      = $node_of( $current_graph, 'CreativeWork' );
	$archetype_page = $graph_of( indexpilot_fetch( $game['archetype'] )['body'] );
	$deck_list      = $node_of( $archetype_page, 'ItemList' );
	$listed         = array_column( $deck_list['itemListElement'] ?? array(), 'url' );

	$checks['game: old version canonical points to current'] = str_contains( $old_page, '<link rel="canonical" href="' . $game['current'] . '">' );
	$checks['game: old version is out of sitemap']           = ! in_array( rawurldecode( $game['old'] ), $decks, true );
	$checks['game: current version is in sitemap']           = in_array( rawurldecode( $game['current'] ), $decks, true ) && in_array( rawurldecode( $game['other'] ), $decks, true );
	$checks['game: deck node in markup']                     = ( $deck_node['mainEntityOfPage']['@id'] ?? 'none' ) === ( $node_of( $current_graph, 'WebPage' )['@id'] ?? '' ) && 'Hearthstone' === ( $deck_node['about']['name'] ?? '' );
	$checks['game: archetype lists only current decks']      = array( $game['current'] ) === $listed && ( $node_of( $archetype_page, 'CollectionPage' )['mainEntity']['@id'] ?? 'none' ) === ( $deck_list['@id'] ?? '' );
	$checks['game: deck title is unique by date']            = 1 === preg_match( '#<title>Blood DK — колода от 01\.10\.2026#u', $old_page );

	// Старая версия колоды (каноникал ведёт на актуальную) описана только как страница под своим адресом:
	// ни узла статьи, ни узла колоды, ни одного @id с адресом актуальной версии.
	$old_graph = $graph_of( $old_page );
	$old_types = array_values( array_unique( array_column( $old_graph, '@type' ) ) );

	$checks['game: old version has no article or deck node'] = array() !== $old_graph && array() === array_intersect( array( 'Article', 'CreativeWork', 'Person' ), $old_types ) && in_array( 'BreadcrumbList', $old_types, true );
	$checks['game: old version markup uses its own address'] = $game['old'] === ( $node_of( $old_graph, 'WebPage' )['url'] ?? '' ) && array() === array_filter( array_column( $old_graph, '@id' ), static fn( string $id ): bool => str_starts_with( $id, $game['current'] ) );
	$checks['game: current deck date has a time zone']       = 1 === preg_match( '/T\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string) ( $deck_node['datePublished'] ?? '' ) );
} else {
	$checks['game: game-check.php was run'] = false;
}

// Короткие ссылки: адреса созданы проверкой shortlinks-check.php.
$short = json_decode( (string) @file_get_contents( __DIR__ . '/shortlinks.json' ), true );

if ( is_array( $short ) ) {
	$custom  = indexpilot_fetch( $short['custom'] );
	$by_post = indexpilot_fetch( $short['post'] );
	indexpilot_fetch( $short['custom'] );
	$clicks  = (int) trim( indexpilot_fetch( $home . '/wp-content/plugins/indexpilot/tests/smoke/shortlinks-check.php?clicks=' . $short['custom_slug'] )['body'] );

	$checks['short link: custom link redirects with 302'] = 302 === $custom['status'] && str_contains( $custom['headers'], 'location: ' . strtolower( $short['custom_target'] ) );
	$checks['short link: not indexed']                    = str_contains( $custom['headers'], 'x-robots-tag: noindex, nofollow' );
	$checks['short link: clicks are counted']             = 2 === $clicks;
	$checks['short link: post link redirects with 301']   = 301 === $by_post['status'] && str_contains( $by_post['headers'], 'location: ' . strtolower( $short['post_target'] ) );
	$checks['short link: unknown code is 404']            = 404 === indexpilot_fetch( $short['missing'] )['status'];
	$checks['short link: rel=shortlink on the page']      = 1 === preg_match( '#<link rel=.shortlink. href=.' . preg_quote( $short['post'], '#' ) . '.#', $post_page );
} else {
	$checks['short link: shortlinks-check.php was run'] = false;
}

// Поле REST: те же заголовок и описание, что на странице.
$rest      = json_decode( indexpilot_fetch( $home . '/wp-json/wp/v2/posts?slug=gajd-4' )['body'], true );
$rest_seo  = $rest[0]['indexpilot'] ?? array();
preg_match( '#<title>(.*?)</title>#s', $post_page, $page_title );
preg_match( '#<meta name="description" content="(.*?)">#s', $post_page, $page_description );

$checks['rest field: same title as on page']       = '' !== ( $rest_seo['title'] ?? '' ) && html_entity_decode( $page_title[1] ?? '', ENT_QUOTES | ENT_HTML5 ) === $rest_seo['title'];
$checks['rest field: same description as on page'] = '' !== ( $rest_seo['description'] ?? '' ) && html_entity_decode( $page_description[1] ?? '', ENT_QUOTES | ENT_HTML5 ) === $rest_seo['description'];
$checks['rest field: no internal data in public']  = ! isset( $rest_seo['focus_keyword'] ) && ! isset( $rest_seo['score'] );

// llms.txt: опорная запись (отмечена проверкой restfield-check.php) и рубрики.
$llms = indexpilot_fetch( $home . '/llms.txt' );

$checks['llms: served as text']       = 200 === $llms['status'] && str_contains( $llms['headers'], 'content-type: text/plain' );
$checks['llms: site name']            = str_starts_with( $llms['body'], '# Manacost' );
$checks['llms: cornerstone page']     = str_contains( $llms['body'], '## Опорные материалы' ) && str_contains( $llms['body'], '](' . $urls['post_auto'] . ')' );
$checks['llms: categories']           = str_contains( $llms['body'], '[Гайды](' );
$checks['llms: no closed pages']      = ! str_contains( $llms['body'], rawurldecode( $urls['post_noindex'] ) );

// robots.txt, файл ключа IndexNow и тег подтверждения.
$smoke  = $home . '/wp-content/plugins/indexpilot/tests/smoke';
$robots = indexpilot_fetch( $home . '/robots.txt' )['body'];
$key    = trim( indexpilot_fetch( $smoke . '/indexnow-check.php?key=1' )['body'] );

$checks['robots: sitemap line']               = str_contains( $robots, 'Sitemap: ' . $home . '/sitemap.xml' );
$checks['robots: no core sitemap line']       = ! str_contains( $robots, 'wp-sitemap.xml' );
$checks['robots: training bots open by default'] = ! str_contains( $robots, 'GPTBot' );
$checks['robots: clean-param for yandex']        = str_contains( $robots, 'Clean-param: utm_source&utm_medium' ) && ! str_contains( $robots, 'User-agent: Yandex' );
$checks['indexnow: key file']                 = 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) && trim( indexpilot_fetch( $home . '/' . $key . '.txt' )['body'] ) === $key;
$checks['indexnow: other txt is not served']  = 200 !== indexpilot_fetch( $home . '/' . str_repeat( 'a', 32 ) . '.txt' )['status'];
$checks['verification: absent by default']    = ! str_contains( indexpilot_fetch( $home . '/' )['body'], 'yandex-verification' );

indexpilot_fetch( $smoke . '/indexnow-check.php?options=1' );
$robots = indexpilot_fetch( $home . '/robots.txt' )['body'];

$checks['robots: training bots blocked when enabled'] = 1 === preg_match( '/User-agent: GPTBot
(?:User-agent: [^
]+
)*Disallow: \/
/', $robots );
$checks['robots: search bots untouched']      = ! str_contains( $robots, 'Googlebot' ) && ! str_contains( $robots, 'Yandex' );
$checks['verification: tag on home page']     = str_contains( indexpilot_fetch( $home . '/' )['body'], '<meta name="yandex-verification" content="1a2b3c">' );
$checks['verification: not on other pages']   = ! str_contains( indexpilot_fetch( $urls['post_auto'] )['body'], 'yandex-verification' );
indexpilot_fetch( $smoke . '/admin-check.php?reset_settings=1' );

$failed = 0;
foreach ( $checks as $name => $passed ) {
	if ( ! $passed ) {
		++$failed;
		echo 'FAIL ', $name, "\n";
	}
}

echo 'sitemap checks passed: ', count( $checks ) - $failed, '/', count( $checks ), "\n";
exit( $failed > 0 ? 1 : 0 );
