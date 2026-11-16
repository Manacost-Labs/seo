<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\SearchData;

use IndexPilot\SearchData\GoogleClient;
use IndexPilot\SearchData\Inspection;
use IndexPilot\Tests\Support\FixtureHttp;
use PHPUnit\Framework\TestCase;

final class InspectionTest extends TestCase {

	public function test_crawled_but_not_indexed_gets_clear_reason(): void {
		$http   = new FixtureHttp();
		$client = new GoogleClient( SyncJobTest::service_account(), $http, static fn(): int => 1000 );
		$result = Inspection::interpret( $client->inspect( 'https://example.org/', 'https://example.org/staraya-novost/' ) );

		self::assertFalse( $result['indexed'] );
		self::assertSame( 'Crawled - currently not indexed', $result['coverage'] );
		self::assertStringContainsString( 'просканировал, но не добавил', $result['reason'] );

		$sent = json_decode( $http->calls[1][2], true );
		self::assertSame( 'https://example.org/staraya-novost/', $sent['inspectionUrl'] );
		self::assertSame( 'https://example.org/', $sent['siteUrl'] );

		$ok = Inspection::interpret( $client->inspect( 'https://example.org/', 'https://example.org/gajd/' ) );
		self::assertTrue( $ok['indexed'] );
		self::assertSame( '', $ok['reason'] );
	}

	public function test_unknown_state_is_reported_as_is(): void {
		$result = Inspection::interpret( array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'FAIL', 'coverageState' => 'Something new' ) ) ) );

		self::assertFalse( $result['indexed'] );
		self::assertStringContainsString( 'Something new', $result['reason'] );
	}

	public function test_pick_respects_daily_limit_and_recheck_intervals(): void {
		$now        = 100 * 86400;
		$candidates = array(
			'https://e.org/new-a/' => 1,
			'https://e.org/hub/'   => 3,
			'https://e.org/ok/'    => 3,
			'https://e.org/bad/'   => 2,
			'https://e.org/new-b/' => 2,
		);
		$checked    = array(
			'https://e.org/ok/'  => array( 'checked' => $now - 3 * 86400, 'indexed' => true ),
			'https://e.org/bad/' => array( 'checked' => $now - 2 * 86400, 'indexed' => false ),
		);

		self::assertSame( array( 'https://e.org/hub/', 'https://e.org/new-b/', 'https://e.org/new-a/' ), Inspection::pick( $candidates, $checked, $now, 3 ) );
		self::assertSame( array( 'https://e.org/hub/', 'https://e.org/new-b/', 'https://e.org/new-a/', 'https://e.org/bad/' ), Inspection::pick( $candidates, $checked, $now, 10 ) );
	}
}
