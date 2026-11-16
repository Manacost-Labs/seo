<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Admin;

use IndexPilot\Admin\HeadInspector;
use PHPUnit\Framework\TestCase;

final class HeadInspectorTest extends TestCase {

	private const PAGE = <<<'HTML'
<!doctype html><html><head>
<title>Колода жреца</title>
<meta name='robots' content='index, follow' />
<!-- IndexPilot 0.1.0 -->
<meta name="description" content="Наше описание">
<link rel="canonical" href="https://site.test/deck/">
<meta property="og:title" content="Колода жреца">
<meta property="article:tag" content="Жрец">
<meta property="article:tag" content="Стандарт">
<!-- /IndexPilot -->
<script type="application/ld+json" class="indexpilot-schema">{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"Article"}]}</script>
<!-- This site is optimized with the Yoast SEO plugin v22 -->
<meta name="description" content="Описание от Yoast">
<script type="application/ld+json" class="yoast-schema-graph">{"@graph":[{"@type":"WebPage"}]}</script>
<!-- / Yoast SEO plugin. -->
<meta property="og:title" content="Заголовок от темы">
<meta name="viewport" content="width=device-width">
</head><body><meta name="description" content="в теле не считается"></body></html>
HTML;

	public function test_duplicate_description_is_found_with_its_source(): void {
		$report = HeadInspector::inspect( self::PAGE );

		self::assertSame( array( 'IndexPilot', 'Yoast SEO' ), $report['duplicates']['description'] );
		self::assertSame( array( 'IndexPilot', HeadInspector::OTHER ), $report['duplicates']['og:title'] );
		self::assertSame( array( 'IndexPilot', 'Yoast SEO' ), $report['duplicates']['ld+json'] );
		// Несколько article:tag — норма, не дубль.
		self::assertArrayNotHasKey( 'article:tag', $report['duplicates'] );
		self::assertArrayNotHasKey( 'canonical', $report['duplicates'] );
	}

	public function test_only_own_site_addresses_are_loaded(): void {
		self::assertSame( 'https://site.test/deck/', HeadInspector::own_url( '/deck/', 'https://site.test/' ) );
		self::assertSame( 'https://site.test/a/', HeadInspector::own_url( 'https://site.test/a/', 'https://site.test/' ) );
		self::assertSame( '', HeadInspector::own_url( 'https://evil.test/', 'https://site.test/' ) );
		self::assertSame( '', HeadInspector::own_url( '//evil.test/', 'https://site.test/' ) );
		self::assertSame( '', HeadInspector::own_url( 'https://site.test.evil.test/', 'https://site.test/' ) );
	}

	public function test_tags_are_listed_with_sources_and_foreign_ones_marked(): void {
		$report = HeadInspector::inspect( self::PAGE );
		$keys   = array_column( $report['tags'], 'key' );

		self::assertContains( 'title', $keys );
		self::assertContains( 'robots', $keys );
		self::assertNotContains( 'viewport', $keys );
		// Описание из тела страницы в список не попадает: смотрится только head.
		self::assertCount( 2, array_keys( $keys, 'description', true ) );

		$schema = array_values( array_filter( $report['tags'], static fn( array $tag ): bool => 'ld+json' === $tag['key'] && 'IndexPilot' === $tag['source'] ) );
		self::assertSame( 'WebPage, Article', $schema[0]['value'] );

		$foreign = array_column( $report['foreign'], 'source' );
		self::assertContains( 'Yoast SEO', $foreign );
		self::assertContains( HeadInspector::OTHER, $foreign );
		self::assertNotContains( 'IndexPilot', $foreign );
	}
}
