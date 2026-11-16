<?php
/**
 * Тесты инструмента сравнения тегов tools/compare-head.php.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tests\Tools;

use IndexPilot\Tools\CompareHead\Fetcher;
use IndexPilot\Tools\CompareHead\HeadParser;
use IndexPilot\Tools\CompareHead\SitemapSampler;
use IndexPilot\Tools\CompareHead\SnapshotDiff;
use PHPUnit\Framework\TestCase;

/**
 * Разбор сохранённых страниц, сравнение снимков и выборка из карты сайта — без сети.
 */
final class CompareHeadTest extends TestCase {

	/**
	 * Снимок одной страницы из сохранённого HTML.
	 *
	 * @param string $fixture Имя файла.
	 * @param int    $status  Код ответа.
	 * @return array<string, mixed>
	 */
	private static function page( string $fixture, int $status = 200 ): array {
		return array(
			'status'    => $status,
			'final_url' => 'https://hs-manacost.ru/koloda-zhreca/',
			'head'      => HeadParser::parse( (string) file_get_contents( __DIR__ . '/fixtures/' . $fixture ) ),
		);
	}

	public function test_parser_reads_head_tags_and_counts(): void {
		$head = HeadParser::parse( (string) file_get_contents( __DIR__ . '/fixtures/before.html' ) );

		self::assertSame( 'Колода Жреца на Контроль — hs-manacost.ru', $head['title'] );
		self::assertSame( '1', $head['title_count'], 'title внутри svg не считается заголовком страницы' );
		self::assertSame( 'Лучшая колода жреца: состав, мулиган и советы по игре.', $head['description'] );
		self::assertSame( 'https://hs-manacost.ru/koloda-zhreca/', $head['canonical'] );
		self::assertSame( 'max-image-preview:large', $head['robots'] );
		self::assertSame( 'https://hs-manacost.ru/a.jpg | https://hs-manacost.ru/b.jpg', $head['og:image'] );
		self::assertSame( 'summary', $head['twitter:card'] );
		self::assertSame( '2026-01-02T10:00:00+03:00', $head['article:published_time'] );
		self::assertSame( '2', $head['h1_count'] );
		self::assertSame( 'Article, Person, WebSite', $head['jsonld_types'] );
		self::assertSame( '1', $head['jsonld_errors'] );
	}

	public function test_parser_marks_missing_tags_as_empty(): void {
		$head = HeadParser::parse( '<html><head><title>Только заголовок</title><title>Второй</title></head><body></body></html>' );

		self::assertSame( 'Только заголовок | Второй', $head['title'] );
		self::assertSame( '2', $head['title_count'] );
		self::assertSame( '', $head['description'] );
		self::assertSame( '0', $head['description_count'] );
		self::assertSame( '0', $head['h1_count'] );
		self::assertSame( '', $head['jsonld_types'] );
		self::assertArrayNotHasKey( 'jsonld_errors', $head );

		self::assertSame( '0', HeadParser::parse( '' )['title_count'] );
	}

	public function test_diff_lists_removed_changed_and_added_tags(): void {
		$report = SnapshotDiff::compare(
			array( 'pages' => array( 'https://hs-manacost.ru/koloda-zhreca/' => self::page( 'before.html' ) ) ),
			array( 'pages' => array( 'https://hs-manacost.ru/koloda-zhreca/' => self::page( 'after.html' ) ) )
		);

		$page = $report['pages']['https://hs-manacost.ru/koloda-zhreca/'];

		self::assertSame( array( 'jsonld_errors', 'og:image' ), array_keys( $page['removed'] ) );
		self::assertSame( array( 'Колода Жреца на Контроль — hs-manacost.ru', 'Колода Жреца на Контроль — Manacost' ), $page['changed']['title'] );
		self::assertSame( array( '2', '1' ), $page['changed']['h1_count'] );
		self::assertSame( array( 'Article, Person, WebSite', 'Article, BreadcrumbList, NewsArticle, WebSite' ), $page['changed']['jsonld_types'] );
		self::assertSame( array( 'summary', 'summary_large_image' ), $page['changed']['twitter:card'] );
		self::assertArrayNotHasKey( 'description', $page['changed'], 'разница в пробелах не считается изменением' );
		self::assertArrayNotHasKey( 'canonical', $page['changed'] );
		self::assertSame( array( 'og:locale' => 'ru_RU' ), $page['added'] );
		self::assertFalse( $page['lost_title'] );
		self::assertFalse( $page['lost_description'] );
		self::assertSame( 0, $report['summary']['lost_any'] );
		self::assertSame( 1, $report['summary']['changed'] );
	}

	public function test_diff_counts_pages_that_lost_title_or_description(): void {
		$lost_both  = self::page( 'after.html' );
		$lost_descr = self::page( 'after.html' );
		$same       = self::page( 'after.html' );

		$lost_both['head']['title']        = '';
		$lost_both['head']['description']  = '';
		$lost_descr['head']['description'] = '';

		$report = SnapshotDiff::compare(
			array(
				'pages' => array(
					'https://a/1' => self::page( 'after.html' ),
					'https://a/2' => self::page( 'after.html' ),
					'https://a/3' => self::page( 'after.html' ),
					'https://a/4' => self::page( 'after.html' ),
				),
			),
			array(
				'pages' => array(
					'https://a/1' => $lost_both,
					'https://a/2' => $lost_descr,
					'https://a/3' => $same,
					'https://a/5' => $same,
				),
			)
		);

		self::assertSame(
			array(
				'urls'             => 5,
				'changed'          => 4,
				'lost_title'       => 2,
				'lost_description' => 3,
				'lost_any'         => 3,
				'missing'          => 2,
			),
			$report['summary']
		);
		self::assertSame( array(), $report['pages']['https://a/3']['changed'] );
		self::assertTrue( $report['pages']['https://a/4']['lost_title'], 'адрес, пропавший из снимка «после», теряет и заголовок' );
		self::assertSame( 'after', $report['pages']['https://a/4']['missing'] );

		$text = SnapshotDiff::render( $report );

		self::assertStringContainsString( "https://a/1\n", $text );
		self::assertStringContainsString( '- пропало     title: «Колода Жреца на Контроль — Manacost»', $text );
		self::assertStringNotContainsString( 'https://a/3', $text );
		self::assertStringContainsString( 'Потеряли заголовок или описание: 3 (заголовок — 2, описание — 3).', $text );
	}

	public function test_diff_shows_status_and_redirect_changes(): void {
		$before = self::page( 'before.html' );
		$after  = self::page( 'before.html', 301 );

		$after['final_url'] = 'https://hs-manacost.ru/new/';

		$page = SnapshotDiff::compare( array( 'pages' => array( 'u' => $before ) ), array( 'pages' => array( 'u' => $after ) ) )['pages']['u'];

		self::assertSame( array( 'status', 'final_url' ), array_keys( $page['changed'] ) );
	}

	public function test_sitemap_kind_is_the_same_for_indexpilot_wordpress_and_aioseo(): void {
		foreach ( array( 'https://s/sitemap-pt-post-2.xml', 'https://s/wp-sitemap-posts-post-1.xml', 'https://s/post-sitemap2.xml', 'https://s/post-sitemap.xml' ) as $map ) {
			self::assertSame( 'post', SitemapSampler::kind( $map ), $map );
		}

		self::assertSame( 'hs_deck', SitemapSampler::kind( 'https://s/sitemap-pt-hs_deck-12.xml' ) );
		self::assertSame( 'category', SitemapSampler::kind( 'https://s/sitemap-tax-category-1.xml' ) );
		self::assertSame( 'category', SitemapSampler::kind( 'https://s/wp-sitemap-taxonomies-category-1.xml' ) );
		self::assertSame( 'category', SitemapSampler::kind( 'https://s/category-sitemap.xml' ) );
		self::assertSame( 'author', SitemapSampler::kind( 'https://s/sitemap-authors-1.xml' ) );
		self::assertSame( 'author', SitemapSampler::kind( 'https://s/wp-sitemap-users-1.xml' ) );
		self::assertSame( 'author', SitemapSampler::kind( 'https://s/author-sitemap.xml' ) );
	}

	public function test_sample_takes_n_urls_of_each_kind_and_adds_special_pages(): void {
		$index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
			. '<sitemap><loc>https://s/sitemap-pt-post-1.xml</loc></sitemap>'
			. '<sitemap><loc>https://s/sitemap-pt-post-2.xml</loc></sitemap>'
			. '<sitemap><loc>https://s/sitemap-tax-category-1.xml</loc></sitemap>'
			. '</sitemapindex>';

		$urlset = static function ( string $prefix, int $count ): string {
			$xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
			for ( $i = 1; $i <= $count; $i++ ) {
				$xml .= '<url><loc>https://s/' . $prefix . $i . '/</loc></url>';
			}
			return $xml . '</urlset>';
		};

		$responses = array(
			'https://s/sitemap_index.xml'          => array( 404, '' ),
			'https://s/sitemap.xml'                => array( 200, $index ),
			'https://s/sitemap-pt-post-1.xml'      => array( 200, $urlset( 'p', 2 ) ),
			'https://s/sitemap-pt-post-2.xml'      => array( 200, $urlset( 'q', 5 ) ),
			'https://s/sitemap-tax-category-1.xml' => array( 200, $urlset( 'c', 1 ) ),
		);

		$requested = array();
		$sampler   = new SitemapSampler(
			static function ( string $url ) use ( $responses, &$requested ): array {
				$requested[] = $url;
				return array(
					'status' => $responses[ $url ][0] ?? 404,
					'body'   => $responses[ $url ][1] ?? '',
				);
			},
			3
		);

		$sample = $sampler->sample( 'https://s/' );

		self::assertSame( 'https://s/sitemap.xml', $sample['index'] );
		self::assertSame(
			array(
				'post'     => 3,
				'category' => 1,
			),
			$sample['kinds']
		);
		self::assertSame(
			array( 'https://s/', 'https://s/p1/', 'https://s/p2/', 'https://s/q1/', 'https://s/c1/', 'https://s' . SitemapSampler::SEARCH, 'https://s' . SitemapSampler::NOT_FOUND ),
			$sample['urls']
		);
		self::assertNotContains( 'https://s/wp-sitemap.xml', $requested, 'найдя карту, дальше не ищет' );
	}

	public function test_basic_auth_is_set_only_when_given(): void {
		$plain = Fetcher::options( 20 );

		self::assertArrayNotHasKey( CURLOPT_USERPWD, $plain );
		self::assertArrayNotHasKey( CURLOPT_HTTPAUTH, $plain );
		self::assertSame( Fetcher::USER_AGENT, $plain[ CURLOPT_USERAGENT ] );
		self::assertContains( 'Cache-Control: no-cache', $plain[ CURLOPT_HTTPHEADER ] );
		self::assertSame( 20, $plain[ CURLOPT_TIMEOUT ] );
		self::assertArrayNotHasKey( CURLOPT_USERPWD, Fetcher::options( 20, 'без-двоеточия' ) );

		$auth = Fetcher::options( 20, 'staging:secret' );

		self::assertSame( 'staging:secret', $auth[ CURLOPT_USERPWD ] );
		self::assertSame( CURLAUTH_BASIC, $auth[ CURLOPT_HTTPAUTH ] );
		self::assertFalse( $auth[ CURLOPT_UNRESTRICTED_AUTH ], 'при перенаправлении на другой хост пароль не уходит' );
	}

	public function test_sample_without_sitemap_keeps_home_search_and_404(): void {
		$sampler = new SitemapSampler(
			static fn (): array => array(
				'status' => 404,
				'body'   => '',
			)
		);

		$sample = $sampler->sample( 'https://s' );

		self::assertSame( '', $sample['index'] );
		self::assertCount( 3, $sample['urls'] );
	}
}
