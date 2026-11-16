<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Sitemap;

use IndexPilot\Sitemap\Chunker;
use IndexPilot\Sitemap\EntryPolicy;
use IndexPilot\Sitemap\FileList;
use IndexPilot\Sitemap\ImageExtractor;
use IndexPilot\Sitemap\Lastmod;
use IndexPilot\Sitemap\News;
use IndexPilot\Sitemap\Router;
use IndexPilot\Sitemap\Xml;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase {

	public function test_noindex_redirected_and_foreign_canonical_pages_are_left_out(): void {
		$url  = 'https://site.test/gajd/';
		$open = array(
			'indexable' => true,
			'canonical' => $url,
		);

		self::assertTrue( EntryPolicy::allows( $url, $open, false ) );
		self::assertTrue( EntryPolicy::allows( $url, array( 'canonical' => 'http://SITE.test/gajd' ) + $open, false ), 'Протокол, регистр хоста и слэш в конце не важны.' );
		self::assertFalse( EntryPolicy::allows( $url, array( 'indexable' => false ) + $open, false ), 'noindex' );
		self::assertFalse( EntryPolicy::allows( $url, $open, true ), 'редирект' );
		self::assertFalse( EntryPolicy::allows( $url, array( 'canonical' => 'https://site.test/drugaya/' ) + $open, false ), 'каноникал на другую страницу' );
	}

	public function test_pages_are_split_by_id_ranges(): void {
		$ids        = range( 3, 2600, 1 );
		$boundaries = Chunker::boundaries( $ids, 1000 );

		self::assertSame( array( 1002, 2002, 2600 ), $boundaries );
		self::assertSame( array( 'after' => 0, 'upto' => 1002 ), Chunker::range( $boundaries, 1 ) );
		self::assertSame( array( 'after' => 1002, 'upto' => 2002 ), Chunker::range( $boundaries, 2 ) );
		self::assertSame( array( 'after' => 2002, 'upto' => 2600 ), Chunker::range( $boundaries, 3 ) );
		self::assertNull( Chunker::range( $boundaries, 4 ) );
		self::assertNull( Chunker::range( $boundaries, 0 ) );
		self::assertSame( array(), Chunker::boundaries( array(), 1000 ) );
	}

	public function test_images_are_taken_from_text(): void {
		$html = '<p><img class="a" src="/wp-content/uploads/mag.png" alt=""> <img src=\'https://cdn.test/dk.jpg?x=1&amp;y=2\'> '
			. '<img src="data:image/png;base64,AAAA"> <img src="/wp-content/uploads/mag.png"> <img alt="без адреса"></p>';

		self::assertSame(
			array( 'https://site.test/wp-content/uploads/mag.png', 'https://cdn.test/dk.jpg?x=1&y=2' ),
			ImageExtractor::from_html( $html, 'https://site.test' )
		);
		self::assertCount( 2, ImageExtractor::from_html( str_repeat( '<img src="/a.png"><img src="/b.png"><img src="/c.png">', 3 ), 'https://site.test', 2 ) );
	}

	public function test_urlset_is_valid_xml_with_escaped_values(): void {
		$xml = Xml::urlset(
			array(
				array(
					'loc'     => 'https://site.test/?p=1&preview=<x>',
					'lastmod' => '2026-11-15T10:00:00+00:00',
					'images'  => array( 'https://site.test/a.png' ),
				),
				array( 'loc' => 'https://site.test/гайд/' ),
			)
		);

		$document = simplexml_load_string( $xml );

		self::assertNotFalse( $document );
		self::assertCount( 2, $document->url );
		self::assertSame( 'https://site.test/?p=1&preview=<x>', (string) $document->url[0]->loc );
		self::assertSame( '2026-11-15T10:00:00+00:00', (string) $document->url[0]->lastmod );
		self::assertSame( 'https://site.test/a.png', (string) $document->url[0]->children( 'http://www.google.com/schemas/sitemap-image/1.1' )->image->loc );
		self::assertSame( 0, $document->url[1]->lastmod->count() );
	}

	public function test_index_lists_sitemap_files(): void {
		$document = simplexml_load_string(
			Xml::index(
				array(
					array(
						'loc'     => 'https://site.test/sitemap-pt-post-1.xml',
						'lastmod' => '2026-11-15T10:00:00+00:00',
					),
					array( 'loc' => 'https://site.test/sitemap-tax-category-1.xml' ),
				)
			)
		);

		self::assertNotFalse( $document );
		self::assertSame( 'sitemapindex', $document->getName() );
		self::assertCount( 2, $document->sitemap );
	}

	public function test_taxonomy_with_all_terms_closed_is_not_listed(): void {
		$open   = array(
			'indexable' => true,
			'canonical' => '',
		);
		$closed = array( 'indexable' => false ) + $open;
		$terms  = array(
			'category' => array(
				'https://site.test/category/gajdy/'   => $open,
				'https://site.test/category/arhiv/'   => $closed,
			),
			'post_tag' => array(
				'https://site.test/tag/tonkaya/'      => $closed,
				'https://site.test/tag/eshhe-odna/'   => $closed,
			),
		);

		$file  = static fn( string $name ): array => array(
			'kind'    => 'tax',
			'name'    => $name,
			'page'    => 1,
			'lastmod' => '',
		);
		$files = FileList::build(
			array( $file( 'category' ), $file( 'post_tag' ) ),
			static function ( array $candidate ) use ( $terms ): bool {
				foreach ( $terms[ $candidate['name'] ] as $url => $indexing ) {
					if ( EntryPolicy::allows( $url, $indexing, false ) ) {
						return true;
					}
				}

				return false;
			}
		);

		self::assertSame( array( $file( 'category' ) ), $files );
		self::assertSame( array(), FileList::build( array( $file( 'post_tag' ) ), static fn(): bool => false ) );
	}

	public function test_post_older_than_48_hours_is_not_news(): void {
		$now  = 1_800_000_000;
		$rows = array(
			array( 'id' => 1, 'published' => $now - 49 * 3600 ),
			array( 'id' => 2, 'published' => $now - 47 * 3600 ),
			array( 'id' => 3, 'published' => $now - 60 ),
			array( 'id' => 4, 'published' => $now + 3600 ),
		);

		self::assertSame( array( 3, 2 ), array_column( News::recent( $rows, $now ), 'id' ), 'Свежие идут первыми; старые и ещё не вышедшие не попадают.' );
		self::assertSame( array( 3 ), array_column( News::recent( $rows, $now, 1 ), 'id' ) );
		self::assertSame( $now - 48 * 3600, News::since( $now ) );
	}

	public function test_news_language_is_iso_639(): void {
		self::assertSame( 'ru', News::language( 'ru-RU' ) );
		self::assertSame( 'en', News::language( 'en_US' ) );
		self::assertSame( 'zh-cn', News::language( 'zh-CN' ) );
		self::assertSame( 'zh-tw', News::language( 'zh_TW' ) );
		self::assertSame( 'ru', News::language( '' ) );
	}

	public function test_news_cache_expires_when_post_leaves_the_window(): void {
		$now = 1_800_000_000;

		self::assertSame( 3600, News::ttl( $now - 47 * 3600, $now, 6 * 3600 ) );
		self::assertSame( 6 * 3600, News::ttl( $now - 3600, $now, 6 * 3600 ), 'Не дольше обычного срока.' );
		self::assertSame( 60, News::ttl( $now - 48 * 3600, $now, 6 * 3600 ), 'Не меньше минуты.' );
	}

	public function test_term_lastmod_is_the_latest_post_of_term_and_its_children(): void {
		$own = array(
			10 => '2026-11-01 10:00:00',
			11 => '2026-11-10 08:30:00',
			12 => '2026-10-01 00:00:00',
			20 => '',
		);
		// 11 и 12 — дочерние рубрики 10; 13 — пустая рубрика между 10 и 14.
		$parents = array(
			10 => 0,
			11 => 10,
			12 => 10,
			13 => 10,
			14 => 13,
			20 => 0,
		);

		$with = Lastmod::with_descendants( $own + array( 14 => '2026-11-12 00:00:00' ), $parents );

		self::assertSame( '2026-11-12 00:00:00', $with[10] );
		self::assertSame( '2026-11-10 08:30:00', $with[11] );
		self::assertSame( '2026-11-12 00:00:00', $with[13] );
		self::assertArrayNotHasKey( 20, $with );
		self::assertSame( '2026-11-10T08:30:00+00:00', Lastmod::w3c( $with[11] ) );
		self::assertSame( '', Lastmod::w3c( '0000-00-00 00:00:00' ) );
		self::assertSame( '2026-11-10 08:30:00', Lastmod::latest( array( '', '2026-11-01 10:00:00', '2026-11-10 08:30:00' ) ) );
		self::assertSame( '', Lastmod::latest( array() ) );
		self::assertCount( 2, Lastmod::with_descendants( array( 1 => '2026-11-01 10:00:00' ), array( 1 => 2, 2 => 1 ) ), 'Петля в родителях не зацикливает расчёт.' );
	}

	public function test_request_path_is_parsed(): void {
		self::assertSame( array( 'kind' => 'news', 'name' => '', 'page' => 1 ), Router::parse( '/sitemap-news.xml', '/' ) );
		self::assertSame( array( 'kind' => 'index', 'name' => '', 'page' => 0 ), Router::parse( '/sitemap.xml', '/' ) );
		self::assertSame( array( 'kind' => 'index', 'name' => '', 'page' => 0 ), Router::parse( '/sitemap_index.xml', '/' ) );
		self::assertSame( array( 'kind' => 'pt', 'name' => 'hs_deck', 'page' => 3 ), Router::parse( '/sitemap-pt-hs_deck-3.xml', '/' ) );
		self::assertSame( array( 'kind' => 'tax', 'name' => 'deck-archetype', 'page' => 1 ), Router::parse( '/blog/sitemap-tax-deck-archetype-1.xml?x=1', '/blog/' ) );
		self::assertSame( array( 'kind' => 'authors', 'name' => '', 'page' => 1 ), Router::parse( '/sitemap-authors-1.xml', '/' ) );
		self::assertNull( Router::parse( '/sitemap-pt-post-0.xml', '/' ) );
		self::assertNull( Router::parse( '/category/sitemap.xml', '/' ) );
		self::assertNull( Router::parse( '/gajd/', '/' ) );
	}

	public function test_old_sitemap_addresses_lead_to_ours(): void {
		foreach ( array( '/wp-sitemap.xml', '/wp-sitemap-posts-post-1.xml', '/wp-sitemap-taxonomies-category-1.xml', '/post-sitemap.xml', '/hs_deck-sitemap2.xml', '/category-sitemap.xml?x=1' ) as $old ) {
			self::assertTrue( Router::legacy( $old, '/' ), $old );
		}

		self::assertTrue( Router::legacy( '/blog/wp-sitemap.xml', '/blog/' ) );

		foreach ( array( '/sitemap.xml', '/sitemap-pt-post-1.xml', '/sitemap-news.xml', '/gajd/wp-sitemap.xml', '/wp-sitemap-index.xsl', '/sitemap.xml.gz' ) as $other ) {
			self::assertFalse( Router::legacy( $other, '/' ), $other );
		}
	}
}
