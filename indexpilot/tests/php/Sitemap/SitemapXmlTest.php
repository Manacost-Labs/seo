<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Sitemap;

use DOMDocument;
use DOMElement;
use DOMXPath;
use IndexPilot\Sitemap\Xml;
use PHPUnit\Framework\TestCase;

/**
 * Готовый XML разбирается строгим разбором и сверяется с требованиями протокола sitemaps.org
 * и расширений Google: пространства имён, обязательные элементы и их порядок. В сеть тест не ходит.
 */
final class SitemapXmlTest extends TestCase {

	private const SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	private const IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
	private const NEWS    = 'http://www.google.com/schemas/sitemap-news/0.9';
	private const VIDEO   = 'http://www.google.com/schemas/sitemap-video/1.1';
	private const W3C     = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/';

	public function test_index_follows_the_protocol(): void {
		$xpath = $this->parse(
			Xml::index(
				array(
					array(
						'loc'     => 'https://site.test/sitemap-pt-post-1.xml',
						'lastmod' => '2026-11-15T10:00:00+00:00',
					),
					array( 'loc' => 'https://site.test/sitemap-tax-category-1.xml' ),
				)
			),
			'sitemapindex'
		);

		$files = $xpath->query( '/s:sitemapindex/s:sitemap' );

		self::assertSame( 2, $files->length );
		self::assertSame( 0, $xpath->query( '/s:sitemapindex/*[not(self::s:sitemap)]' )->length, 'В списке файлов нет посторонних элементов.' );

		foreach ( $files as $file ) {
			self::assertContains( $this->child_names( $file ), array( array( 'loc' ), array( 'loc', 'lastmod' ) ), 'Сначала адрес, затем необязательная дата.' );
			$this->assert_address( $xpath->evaluate( 'string(s:loc)', $file ) );
		}

		self::assertMatchesRegularExpression( self::W3C, $xpath->evaluate( 'string(/s:sitemapindex/s:sitemap[1]/s:lastmod)' ) );
		self::assertSame( 0, $xpath->query( '/s:sitemapindex/s:sitemap[2]/s:lastmod' )->length, 'Пустая дата не печатается.' );
	}

	public function test_urlset_follows_the_protocol(): void {
		$xpath = $this->parse(
			Xml::urlset(
				array(
					array(
						'loc'     => 'https://site.test/gajd/?a=1&b=<2>',
						'lastmod' => '2026-11-15T10:00:00+00:00',
						'images'  => array( 'https://site.test/a.png', 'https://site.test/b.png' ),
					),
					array(
						'loc'     => 'https://site.test/category/гайды/',
						'lastmod' => '2026-11-10T08:30:00+00:00',
					),
					array( 'loc' => 'https://site.test/author/admin/' ),
				)
			),
			'urlset'
		);

		$urls = $xpath->query( '/s:urlset/s:url' );

		self::assertSame( 3, $urls->length );
		self::assertSame( 0, $xpath->query( '/s:urlset/*[not(self::s:url)]' )->length );

		foreach ( $urls as $url ) {
			self::assertSame( 'loc', $this->child_names( $url )[0], 'Адрес идёт первым.' );
			self::assertSame( 1, $xpath->query( 's:loc', $url )->length );
			$this->assert_address( $xpath->evaluate( 'string(s:loc)', $url ) );
			self::assertLessThanOrEqual( 1, $xpath->query( 's:lastmod', $url )->length );
		}

		self::assertSame( 'https://site.test/gajd/?a=1&b=<2>', $xpath->evaluate( 'string(/s:urlset/s:url[1]/s:loc)' ) );
		self::assertMatchesRegularExpression( self::W3C, $xpath->evaluate( 'string(/s:urlset/s:url[2]/s:lastmod)' ), 'У архива термина есть дата.' );
		self::assertSame( 2, $xpath->query( '/s:urlset/s:url[1]/image:image/image:loc' )->length );
		self::assertSame( 0, $xpath->query( '//image:image[not(image:loc)]' )->length, 'У каждой картинки есть адрес.' );
		self::assertSame( 0, $xpath->query( '/s:urlset/s:url[3]/s:lastmod' )->length );
	}

	public function test_videos_follow_the_google_video_extension(): void {
		$xpath = $this->parse(
			Xml::urlset(
				array(
					array(
						'loc'    => 'https://site.test/gajd/',
						'images' => array( 'https://site.test/a.png' ),
						'videos' => array(
							array(
								'thumbnail_loc' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
								'title'         => 'Жрец & <воскрешение>',
								'description'   => 'Как играть жрецом.',
								'player_loc'    => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
							),
						),
					),
				)
			),
			'urlset'
		);

		$video = $xpath->query( '/s:urlset/s:url[1]/video:video' );

		self::assertSame( 1, $video->length );
		self::assertSame( array( 'video:thumbnail_loc', 'video:title', 'video:description', 'video:player_loc' ), $this->child_names( $video->item( 0 ) ) );
		self::assertSame( 'Жрец & <воскрешение>', $xpath->evaluate( 'string(/s:urlset/s:url[1]/video:video/video:title)' ) );
		self::assertSame( array( 'loc', 'image:image', 'video:video' ), $this->child_names( $xpath->query( '/s:urlset/s:url[1]' )->item( 0 ) ) );
	}

	public function test_news_follows_the_google_news_extension(): void {
		$xpath = $this->parse(
			Xml::news(
				array(
					array(
						'loc'       => 'https://site.test/novost/',
						'published' => '2026-11-15T10:00:00+00:00',
						'title'     => 'Патч 31.2: «Маг» & <новые> карты',
					),
					array(
						'loc'       => 'https://site.test/vtoraya/',
						'published' => '2026-11-14T22:15:00+00:00',
						'title'     => 'Вторая новость',
					),
				),
				'Manacost & Co',
				'ru'
			),
			'urlset'
		);

		$urls = $xpath->query( '/s:urlset/s:url' );

		self::assertSame( 2, $urls->length );

		foreach ( $urls as $url ) {
			self::assertSame( array( 'loc', 'news:news' ), $this->child_names( $url ) );
			self::assertSame( array( 'news:publication', 'news:publication_date', 'news:title' ), $this->child_names( $xpath->query( 'news:news', $url )->item( 0 ) ) );
			self::assertSame( array( 'news:name', 'news:language' ), $this->child_names( $xpath->query( 'news:news/news:publication', $url )->item( 0 ) ) );
			self::assertSame( 'Manacost & Co', $xpath->evaluate( 'string(news:news/news:publication/news:name)', $url ) );
			self::assertSame( 'ru', $xpath->evaluate( 'string(news:news/news:publication/news:language)', $url ) );
			self::assertMatchesRegularExpression( self::W3C, $xpath->evaluate( 'string(news:news/news:publication_date)', $url ) );
			self::assertNotSame( '', $xpath->evaluate( 'string(news:news/news:title)', $url ) );
			$this->assert_address( $xpath->evaluate( 'string(s:loc)', $url ) );
		}

		self::assertSame( 'Патч 31.2: «Маг» & <новые> карты', $xpath->evaluate( 'string(/s:urlset/s:url[1]/news:news/news:title)' ) );
	}

	/**
	 * Разбирает XML строгим разбором и проверяет корневой элемент.
	 *
	 * @param string $xml  XML карты.
	 * @param string $root Ожидаемый корневой элемент.
	 */
	private function parse( string $xml, string $root ): DOMXPath {
		self::assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $xml );

		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$loaded   = $document->loadXML( $xml, LIBXML_NONET );
		$errors   = libxml_get_errors();

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		self::assertTrue( $loaded, 'XML разбирается.' );
		self::assertSame( array(), $errors, 'В XML нет ошибок и предупреждений.' );
		self::assertSame( 'UTF-8', $document->encoding );
		self::assertSame( $root, $document->documentElement->localName );
		self::assertSame( self::SITEMAP, $document->documentElement->namespaceURI );

		$xpath = new DOMXPath( $document );
		$xpath->registerNamespace( 's', self::SITEMAP );
		$xpath->registerNamespace( 'image', self::IMAGE );
		$xpath->registerNamespace( 'news', self::NEWS );
		$xpath->registerNamespace( 'video', self::VIDEO );

		// Каждый элемент принадлежит одному из известных пространств имён.
		foreach ( $xpath->query( '//*' ) as $node ) {
			self::assertContains( $node->namespaceURI, array( self::SITEMAP, self::IMAGE, self::NEWS, self::VIDEO ), $node->nodeName );
		}

		return $xpath;
	}

	/**
	 * Имена дочерних элементов по порядку.
	 *
	 * @param DOMElement $node Элемент.
	 * @return list<string>
	 */
	private function child_names( DOMElement $node ): array {
		$names = array();

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$names[] = $child->nodeName;
			}
		}

		return $names;
	}

	/**
	 * Адрес в карте — полный, с протоколом, не длиннее 2048 знаков.
	 *
	 * @param string $url Адрес.
	 */
	private function assert_address( string $url ): void {
		self::assertMatchesRegularExpression( '#^https?://[^\s/]+/#', $url );
		self::assertLessThanOrEqual( 2048, strlen( $url ) );
	}
}
