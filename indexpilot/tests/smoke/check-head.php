<?php
/**
 * Проверяет SEO-теги на страницах запущенного тестового WordPress.
 * Запуск: php tests/smoke/check-head.php (после старта сервера Playground с site-blueprint.json).
 */

declare( strict_types=1 );

$urls = json_decode( (string) file_get_contents( __DIR__ . '/urls.json' ), true );
if ( ! is_array( $urls ) ) {
	fwrite( STDERR, "urls.json not found: run the site blueprint first\n" );
	exit( 2 );
}

/**
 * Загружает страницу и разбирает её head.
 *
 * @return array<string, mixed>
 */
function indexpilot_fetch_head( string $url ): array {
	$parts = parse_url( $url );
	if ( isset( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
		$url = strtok( $url, '?' ) . '?' . http_build_query( $query );
	}

	$context = stream_context_create(
		array(
			'http' => array(
				'ignore_errors' => true,
				'timeout'       => 60,
			),
		)
	);
	$html    = (string) file_get_contents( $url, false, $context );
	$status  = isset( $http_response_header[0] ) && preg_match( '/\s(\d{3})\s/', $http_response_header[0], $m ) ? (int) $m[1] : 0;
	$head    = explode( '</head>', $html )[0];

	$all = static function ( string $pattern ) use ( $head ): array {
		preg_match_all( $pattern, $head, $matches );

		return array_map( static fn( string $v ): string => html_entity_decode( trim( $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $matches[1] );
	};

	return array(
		'html'        => $html,
		'status'      => $status,
		'title'       => $all( '#<title[^>]*>(.*?)</title>#is' ),
		'description' => $all( '#<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']#is' ),
		'canonical'   => $all( '#<link\s+rel=["\']canonical["\']\s+href=["\'](.*?)["\']#is' ),
		'robots'      => $all( '#<meta\s+name=["\']robots["\']\s+content=["\'](.*?)["\']#is' ),
		'og:title'    => $all( '#<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']#is' ),
		'og:type'     => $all( '#<meta\s+property=["\']og:type["\']\s+content=["\'](.*?)["\']#is' ),
	);
}

$two_sentences = 'Маг на заклинаниях держит темп с первых ходов. Колода наказывает медленные архетипы и закрывает игру к восьмому ходу.';
$index         = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

// Ожидания: тег => список значений (число элементов = число тегов на странице).
$expectations = array(
	'home'                    => array(
		'status'      => 200,
		'title'       => array( 'Manacost — Всё о Hearthstone' ),
		'description' => array( 'Всё о Hearthstone' ),
		'canonical'   => array( $urls['home'] ),
		'robots'      => array( $index ),
	),
	'post_auto'               => array(
		'status'      => 200,
		'title'       => array( 'Гайд номер 4 — Manacost' ),
		'description' => array( $two_sentences ),
		'canonical'   => array( $urls['post_auto'] ),
		'robots'      => array( $index ),
		'og:title'    => array( 'Гайд номер 4' ),
		'og:type'     => array( 'article' ),
	),
	'post_custom'             => array(
		'title'       => array( 'Лучший маг сезона — Manacost' ),
		'description' => array( 'Ручное описание гайда на мага.' ),
		'robots'      => array( $index ),
	),
	'post_noindex'            => array(
		'robots' => array( 'noindex, follow' ),
	),
	'post_protected'          => array(
		'description' => array(),
		'robots'      => array( 'noindex, follow' ),
	),
	'category'                => array(
		'title'       => array( 'Гайды — Manacost' ),
		'description' => array( 'Все гайды по Hearthstone: колоды, архетипы и разборы матчапов.' ),
		'canonical'   => array( $urls['category'] ),
		'robots'      => array( $index ),
	),
	'category_page_2'         => array(
		'status'      => 200,
		'title'       => array( 'Гайды — страница 2 — Manacost' ),
		'description' => array( 'Все гайды по Hearthstone: колоды, архетипы и разборы матчапов. Страница 2.' ),
		'canonical'   => array( $urls['category_page_2'] ),
		'robots'      => array( $index ),
	),
	'category_no_description' => array(
		'description' => array( 'Новости — все материалы раздела на сайте Manacost.' ),
	),
	'thin_tag'                => array(
		'robots' => array( 'noindex, follow' ),
	),
	'deck'                    => array(
		'status'      => 200,
		'title'       => array( 'Blood DK — колода от 15.11.2026, Стандарт, 9400 пыли' ),
		'description' => array( 'Колода «Blood DK» для Hearthstone, класс Рыцарь Смерти, режим Стандарт: код для копирования и список карт. Стоимость — 9400 пыли. Обновлено 15.11.2026.' ),
		'canonical'   => array( $urls['deck'] ),
		'robots'      => array( $index ),
	),
	'archetype'               => array(
		'status'      => 200,
		'title'       => array( 'Blood DK — колоды Hearthstone — Manacost' ),
		'description' => array( 'Колоды архетипа «Blood DK» для Hearthstone: актуальные версии с кодами и списками карт на сайте Manacost.' ),
		'robots'      => array( 'noindex, follow' ),
	),
	'search'                  => array(
		'title'     => array( 'Поиск: маг — Manacost' ),
		'canonical' => array(),
		'robots'    => array( 'noindex, follow' ),
	),
	'not_found'               => array(
		'status'    => 404,
		'title'     => array( 'Страница не найдена — Manacost' ),
		'canonical' => array(),
		'robots'    => array( 'noindex, follow' ),
	),
);

$total  = 0;
$failed = 0;

foreach ( $expectations as $page => $expected ) {
	$actual = indexpilot_fetch_head( $urls[ $page ] );

	foreach ( $expected as $tag => $value ) {
		++$total;
		if ( $actual[ $tag ] !== $value ) {
			++$failed;
			echo 'FAIL ', $page, ' / ', $tag, "\n  expected: ", json_encode( $value ), "\n  actual:   ", json_encode( $actual[ $tag ] ), "\n";
		}
	}
}

// Тексты рубрики: вступление и нижний текст есть на первой странице и отсутствуют на второй.
$body_checks = array(
	array( 'category', 'Вступление рубрики: лучшие гайды сезона.', true ),
	array( 'category', 'Подробный текст рубрики под списком записей.', true ),
	array( 'category', '<script>alert(1)</script>', false ),
	array( 'category_page_2', 'Вступление рубрики: лучшие гайды сезона.', false ),
	array( 'category_page_2', 'Подробный текст рубрики под списком записей.', false ),
);

foreach ( $body_checks as $body_check ) {
	++$total;
	$page_html = indexpilot_fetch_head( $urls[ $body_check[0] ] )['html'];
	if ( str_contains( $page_html, $body_check[1] ) !== $body_check[2] ) {
		++$failed;
		echo 'FAIL ', $body_check[0], ' / body ', $body_check[2] ? 'must contain: ' : 'must not contain: ', json_encode( $body_check[1] ), "\n";
	}
}

// Разметка JSON-LD: один блок на странице, все ссылки @id ведут на узлы этого же блока.
$schema_expectations = array(
	'home'            => array( 'types' => array( 'CollectionPage', 'Organization', 'WebSite' ), 'crumbs' => null ),
	'post_auto'       => array( 'types' => array( 'Article', 'BreadcrumbList', 'Organization', 'Person', 'WebPage', 'WebSite' ), 'crumbs' => array( 'Главная', 'Гайды', 'Гайд номер 4' ) ),
	'deck'            => array( 'types' => array( 'Article', 'BreadcrumbList', 'CreativeWork', 'Organization', 'WebPage', 'WebSite' ), 'crumbs' => array( 'Главная', 'Колоды', 'Blood DK', 'Blood DK' ) ),
	'category'        => array( 'types' => array( 'BreadcrumbList', 'CollectionPage', 'Organization', 'WebSite' ), 'crumbs' => array( 'Главная', 'Гайды' ) ),
	'category_page_2' => array( 'types' => array( 'BreadcrumbList', 'CollectionPage', 'Organization', 'WebSite' ), 'crumbs' => array( 'Главная', 'Гайды' ) ),
	'not_found'       => array( 'types' => null, 'crumbs' => null ),
);

$references = static function ( array $value ) use ( &$references ): array {
	$ids = array();
	foreach ( $value as $item ) {
		if ( is_array( $item ) ) {
			$ids = array( '@id' ) === array_keys( $item ) ? array_merge( $ids, array( $item['@id'] ) ) : array_merge( $ids, $references( $item ) );
		}
	}

	return $ids;
};

foreach ( $schema_expectations as $page => $expected ) {
	$page_html = indexpilot_fetch_head( $urls[ $page ] )['html'];
	preg_match_all( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $page_html, $blocks );

	$problems = array();

	if ( null === $expected['types'] ) {
		if ( array() !== $blocks[1] ) {
			$problems[] = 'schema block must be absent';
		}
	} elseif ( 1 !== count( $blocks[1] ) ) {
		$problems[] = 'expected exactly one JSON-LD block, found ' . count( $blocks[1] );
	} else {
		$graph = json_decode( $blocks[1][0], true )['@graph'] ?? array();
		$ids   = array_column( $graph, '@id' );
		$types = array_values( array_unique( array_column( $graph, '@type' ) ) );
		sort( $types );

		$expected_types = array_values( array_diff( $expected['types'], array( 'ImageObject' ) ) );
		if ( array_values( array_diff( $types, array( 'ImageObject' ) ) ) !== $expected_types ) {
			$problems[] = 'types: ' . json_encode( $types );
		}

		foreach ( $graph as $node ) {
			foreach ( $references( $node ) as $reference ) {
				if ( ! in_array( $reference, $ids, true ) ) {
					$problems[] = 'dangling @id: ' . $reference;
				}
			}
		}

		if ( null !== $expected['crumbs'] ) {
			$crumbs = array();
			foreach ( $graph as $node ) {
				if ( 'BreadcrumbList' === $node['@type'] ) {
					$crumbs = array_column( $node['itemListElement'], 'name' );
				}
			}

			if ( $crumbs !== $expected['crumbs'] ) {
				$problems[] = 'breadcrumbs: ' . json_encode( $crumbs, JSON_UNESCAPED_UNICODE );
			}
		}
	}

	++$total;
	if ( array() !== $problems ) {
		++$failed;
		echo 'FAIL ', $page, ' / schema: ', implode( '; ', $problems ), "\n";
	}
}

// Правки по сверке с принципами SEO (docs/SEO-PRINCIPLES.md, «Несоответствия»).
$graph_of = static function ( string $html ): array {
	preg_match( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $block );

	return json_decode( $block[1] ?? '', true )['@graph'] ?? array();
};
$node_of  = static function ( array $graph, string $type ): array {
	foreach ( $graph as $node ) {
		if ( $type === ( $node['@type'] ?? '' ) ) {
			return $node;
		}
	}

	return array();
};
$property = static function ( string $html, string $name ): string {
	return preg_match( '#<meta property="' . preg_quote( $name, '#' ) . '" content="([^"]*)"#', $html, $found ) ? $found[1] : '';
};

$embed     = indexpilot_fetch_head( $urls['post_auto'] . 'embed/' );
$search    = indexpilot_fetch_head( $urls['home'] . '?s=a%26b' );
$nobody    = indexpilot_fetch_head( $urls['home'] . 'author/nobody/' );
$home_2    = indexpilot_fetch_head( $urls['home'] . 'page/2/' );
$protected = indexpilot_fetch_head( $urls['post_protected'] );
$post_html = indexpilot_fetch_head( $urls['post_auto'] )['html'];
$deck_node = $node_of( $graph_of( indexpilot_fetch_head( $urls['deck'] )['html'] ), 'CreativeWork' );
$news_page = $graph_of( indexpilot_fetch_head( $urls['home'] . 'novost-1/' )['html'] );

$protected_graph  = $graph_of( $protected['html'] );
$protected_crumbs = array_column( (array) ( $node_of( $protected_graph, 'BreadcrumbList' )['itemListElement'] ?? array() ), 'name' );
$published        = $property( $post_html, 'article:published_time' );
$modified         = $property( $post_html, 'article:modified_time' );

$principles = array(
	// 1. Страница встраивания остаётся закрытой от индексации.
	'embed page is noindex'                        => 200 === $embed['status'] && 1 === count( $embed['robots'] ) && str_contains( $embed['robots'][0], 'noindex' ) && ! preg_match( '/(^|, )index/', $embed['robots'][0] ),
	// 4. Текст title экранирован один раз. Смотрится только <head>: поле поиска в теле страницы выводит блок
	// темы (в WordPress 6.9 он сам экранирует значение дважды), к плагину оно не относится.
	'title is escaped once'                        => str_contains( $search['html'], '<title>Поиск: a&amp;b — Manacost</title>' ) && ! str_contains( strstr( $search['html'], '</head>', true ) ?: $search['html'], '&amp;amp;' ),
	// 5. Время изменения не раньше времени публикации, одно и то же в тегах.
	'modified time is not before published time'   => '' !== $published && strtotime( $modified ) >= strtotime( $published ),
	'og:updated_time equals article:modified_time' => '' !== $modified && $modified === $property( $post_html, 'og:updated_time' ),
	// 8. Архив несуществующего автора закрыт и не имеет канонического адреса.
	'missing author archive is noindex'            => array( 'noindex, follow' ) === $nobody['robots'] && array() === $nobody['canonical'],
	// 9. В описании страницы пагинации есть точка перед «Страница 2».
	'paged description keeps the full stop'        => array( 'Всё о Hearthstone. Страница 2.' ) === $home_2['description'],
	// 10. Дата в узле колоды — с часовым поясом.
	'deck date has a time zone'                    => 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string) ( $deck_node['datePublished'] ?? '' ) ),
	// Выбор «Авто»: запись из рубрики «Новости» получает NewsArticle, гайд остаётся Article.
	'auto type makes a news article'               => array() !== $node_of( $news_page, 'NewsArticle' ) && array() === $node_of( $news_page, 'Article' ) && array() !== $node_of( $graph_of( $post_html ), 'Article' ),
	// 11. Приставки «Protected:» нет ни в разметке, ни в крошках запароленной записи.
	'protected post has a plain name in schema'    => array() !== $protected_graph && 'Закрытая запись' === end( $protected_crumbs ) && 'Закрытая запись' === ( $node_of( $protected_graph, 'Article' )['headline'] ?? '' ) && ! preg_match( '/Protected|Защищено/u', (string) json_encode( $protected_graph, JSON_UNESCAPED_UNICODE ) ),
);

foreach ( $principles as $name => $passed ) {
	++$total;
	if ( ! $passed ) {
		++$failed;
		echo 'FAIL principles / ', $name, "\n";
	}
}

echo 'head checks passed: ', $total - $failed, '/', $total, "\n";
exit( $failed > 0 ? 1 : 0 );
