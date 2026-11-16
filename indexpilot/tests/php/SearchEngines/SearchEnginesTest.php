<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\SearchEngines;

use IndexPilot\IndexNow\Log;
use IndexPilot\Robots\RobotsTxt;
use IndexPilot\SearchEngines\Links;
use PHPUnit\Framework\TestCase;

final class SearchEnginesTest extends TestCase {

	public function test_log_keeps_the_latest_entries(): void {
		$stored = array();
		$log    = new Log(
			static function () use ( &$stored ): array {
				return $stored;
			},
			static function ( array $entries ) use ( &$stored ): void {
				$stored = $entries;
			}
		);

		for ( $i = 1; $i <= Log::MAX_ENTRIES + 5; $i++ ) {
			$log->add( $i, 200 === 200, 200, 'https://site.test/' . $i . '/', 1000 + $i );
		}

		$entries = $log->entries();

		self::assertCount( Log::MAX_ENTRIES, $entries );
		self::assertSame( Log::MAX_ENTRIES + 5, $entries[0]['count'], 'Сначала самые свежие отправки.' );
		self::assertSame( 'https://site.test/' . ( Log::MAX_ENTRIES + 5 ) . '/', $entries[0]['sample'] );
		self::assertSame( 1000 + Log::MAX_ENTRIES + 5, $entries[0]['time'] );
		self::assertTrue( $entries[0]['ok'] );
	}

	public function test_failed_send_is_recorded_with_its_code(): void {
		$stored = array();
		$log    = new Log(
			static function () use ( &$stored ): array {
				return $stored;
			},
			static function ( array $entries ) use ( &$stored ): void {
				$stored = $entries;
			}
		);

		$log->add( 3, false, 403, 'https://site.test/a/', 500 );

		self::assertSame(
			array(
				array(
					'time'   => 500,
					'count'  => 3,
					'ok'     => false,
					'code'   => 403,
					'sample' => 'https://site.test/a/',
				),
			),
			$log->entries()
		);
	}

	public function test_cabinet_links_are_built_for_the_site(): void {
		$links = Links::for_site( 'https://hs-manacost.ru/' );

		self::assertSame( 'https://search.google.com/search-console?resource_id=https%3A%2F%2Fhs-manacost.ru%2F', $links['google']['cabinet'] );
		self::assertSame( 'https://search.google.com/search-console/sitemaps?resource_id=https%3A%2F%2Fhs-manacost.ru%2F', $links['google']['sitemaps'] );
		self::assertSame( 'https://webmaster.yandex.ru/site/https:hs-manacost.ru:443/dashboard/', $links['yandex']['cabinet'] );
		self::assertSame( 'https://webmaster.yandex.ru/site/https:hs-manacost.ru:443/indexing/sitemap/', $links['yandex']['sitemaps'] );
		self::assertSame( 'https://www.bing.com/webmasters/home?siteUrl=https%3A%2F%2Fhs-manacost.ru%2F', $links['bing']['cabinet'] );
		self::assertSame( 'https://webmaster.yandex.ru/site/http:test.site:80/dashboard/', Links::for_site( 'http://test.site' )['yandex']['cabinet'] );
	}

	public function test_page_check_links_are_built_for_an_address(): void {
		$links = Links::for_url( 'https://hs-manacost.ru/', 'https://hs-manacost.ru/гайд-маг/?a=1&b=2' );

		self::assertSame( 'https://search.google.com/search-console/inspect?resource_id=https%3A%2F%2Fhs-manacost.ru%2F&id=https%3A%2F%2Fhs-manacost.ru%2F%D0%B3%D0%B0%D0%B9%D0%B4-%D0%BC%D0%B0%D0%B3%2F%3Fa%3D1%26b%3D2', $links['google_inspect'] );
		self::assertStringStartsWith( 'https://www.google.com/search?q=site%3Ahttps%3A%2F%2Fhs-manacost.ru%2F', $links['google_index'] );
		self::assertStringStartsWith( 'https://yandex.ru/search/?text=url%3Ahttps%3A%2F%2Fhs-manacost.ru%2F', $links['yandex_index'] );
	}

	public function test_clean_param_is_added_once_for_yandex_without_a_separate_section(): void {
		$core = "User-agent: *\nDisallow: /wp-admin/\n";
		$txt  = RobotsTxt::build( $core, 'https://site.test/sitemap.xml', false, RobotsTxt::TRAINING_BOTS, 'utm_source&utm_medium&yclid' );

		self::assertSame( $core . "Clean-param: utm_source&utm_medium&yclid\n\nSitemap: https://site.test/sitemap.xml\n", $txt );
		self::assertStringNotContainsString( 'User-agent: Yandex', $txt, 'Отдельный раздел для Яндекса отменил бы для него общие запреты.' );

		$blocked = RobotsTxt::build( $core, '', true, array( 'GPTBot' ), 'utm_source' );

		self::assertSame( $core . "Clean-param: utm_source\n\nUser-agent: GPTBot\nDisallow: /\n", $blocked );
		self::assertSame( 1, substr_count( RobotsTxt::build( $core . "Clean-param: utm_source\n", '', false, array(), 'utm_source' ), 'Clean-param' ) );
	}
}
