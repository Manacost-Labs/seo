<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\SearchData;

use IndexPilot\SearchData\GoogleClient;
use IndexPilot\SearchData\SyncJob;
use IndexPilot\SearchData\UrlMatcher;
use IndexPilot\SearchData\YandexClient;
use IndexPilot\Tests\Support\ArrayQueryStore;
use IndexPilot\Tests\Support\FixtureHttp;
use PHPUnit\Framework\TestCase;

final class SyncJobTest extends TestCase {

	private static string $key = '';

	public static function service_account(): string {
		if ( '' === self::$key ) {
			$config = array( 'config' => __DIR__ . '/openssl.cnf' );
			$key    = openssl_pkey_new( $config + array( 'private_key_bits' => 2048 ) );
			openssl_pkey_export( $key, $pem, null, $config );
			self::$key = (string) json_encode(
				array(
					'type'         => 'service_account',
					'client_email' => 'robot@test-project.iam.gserviceaccount.com',
					'private_key'  => $pem,
				)
			);
		}

		return self::$key;
	}

	private function job( ArrayQueryStore $store, FixtureHttp $http ): SyncJob {
		$google  = new GoogleClient( self::service_account(), $http, static fn(): int => 1000 );
		$yandex  = new YandexClient( 'token', $http );
		$site    = 'https://example.org/';
		$matcher = new UrlMatcher(
			static fn( string $url ): int => array(
				'https://example.org/gajd-zhreca'  => 10,
				'https://example.org/koloda-zhreca' => 11,
			)[ $url ] ?? 0,
			static fn( string $url ): int => 'https://example.org/category/gajdy' === $url ? 42 : 0,
			static fn(): int => 0
		);

		return new SyncJob(
			array(
				'google' => array(
					'current'  => static fn( string $start, string $end ): array => $google->query( $site, $start, $end ),
					'previous' => static fn( string $start, string $end ): array => $google->query( $site, $start, $end ),
					'p90'      => static fn( string $start, string $end ): array => $google->query( $site, $start, $end, array( 'page' ) ),
				),
				'yandex' => array(
					'current'  => static fn( string $start, string $end ): array => $yandex->popular_queries( $yandex->host_id( $site ), $start, $end ),
					'previous' => static fn( string $start, string $end ): array => $yandex->popular_queries( $yandex->host_id( $site ), $start, $end ),
				),
			),
			$store,
			$matcher
		);
	}

	private function run_all( SyncJob $job, int $now ): array {
		$state = $job->start( '2026-11-16', $now );

		for ( $i = 0; $i < 20 && ! $state['done']; $i++ ) {
			$state = $job->run( $state, $now );
		}

		return $state;
	}

	public function test_periods_are_two_windows_of_28_days_and_90_days_with_lag(): void {
		self::assertSame(
			array(
				'current'  => array( '2026-10-17', '2026-11-13' ),
				'previous' => array( '2026-09-19', '2026-10-16' ),
				'p90'      => array( '2026-08-16', '2026-11-13' ),
			),
			SyncJob::periods( '2026-11-16' )
		);
	}

	public function test_repeated_sync_does_not_double_rows_and_binds_term(): void {
		$store = new ArrayQueryStore();
		$http  = new FixtureHttp();
		$job   = $this->job( $store, $http );

		$state = $this->run_all( $job, 5000 );
		self::assertTrue( $state['done'] );
		self::assertSame( array(), $state['errors'] );
		$count = count( $store->rows );
		self::assertSame( 6 + 6 + 2 + 2 + 2, $count );

		$state = $this->run_all( $job, 6000 );
		self::assertCount( $count, $store->rows );

		$term = array_values( array_filter( $store->rows, static fn( array $row ): bool => 'term' === $row['object_type'] ) );
		self::assertNotEmpty( $term );
		self::assertSame( array( 42 ), array_values( array_unique( array_column( $term, 'object_id' ) ) ) );

		$post = array_values( array_filter( $store->rows, static fn( array $row ): bool => 'current' === $row['period'] && 'колода жреца' === $row['query'] && 'google' === $row['engine'] ) );
		self::assertSame( array( 10, 11 ), array_column( $post, 'object_id' ) );

		$yandex = array_values( array_filter( $store->rows, static fn( array $row ): bool => 'yandex' === $row['engine'] ) );
		self::assertSame( array( 'site' ), array_values( array_unique( array_column( $yandex, 'object_type' ) ) ) );

		$home = array_values( array_filter( $store->rows, static fn( array $row ): bool => 'hs manacost' === $row['query'] && 'current' === $row['period'] ) );
		self::assertSame( 'home', $home[0]['object_type'] );
	}

	public function test_failed_engine_keeps_other_engine_and_explains(): void {
		$store = new ArrayQueryStore();
		$http  = new FixtureHttp( 'https://example.org', array( 'webmaster.yandex' => 403 ) );
		$state = $this->run_all( $this->job( $store, $http ), 5000 );

		self::assertTrue( $state['done'] );
		self::assertArrayHasKey( 'yandex', $state['errors'] );
		self::assertStringContainsString( 'токен', $state['errors']['yandex'] );
		self::assertSame( array( 'google' ), array_values( array_unique( array_column( $store->rows, 'engine' ) ) ) );

		// После ошибки движок не запрашивается повторно в том же обходе.
		$yandex_calls = array_filter( $http->calls, static fn( array $call ): bool => str_contains( $call[1], 'webmaster.yandex' ) );
		self::assertCount( 1, $yandex_calls );
	}

	public function test_old_rows_and_removed_engines_are_deleted(): void {
		$store       = new ArrayQueryStore();
		$store->rows = array(
			array( 'engine' => 'bing', 'period' => 'current', 'page' => '', 'object_type' => 'site', 'object_id' => 0, 'query' => 'x', 'clicks' => 0, 'impressions' => 1, 'position' => 1.0, 'synced' => 4999 ),
			array( 'engine' => 'google', 'period' => 'old', 'page' => '', 'object_type' => 'site', 'object_id' => 0, 'query' => 'y', 'clicks' => 0, 'impressions' => 1, 'position' => 1.0, 'synced' => 1 ),
		);

		$this->run_all( $this->job( $store, new FixtureHttp() ), 5000 + 30 * 86400 );

		self::assertSame( array(), array_filter( $store->rows, static fn( array $row ): bool => in_array( $row['query'], array( 'x', 'y' ), true ) ) );
	}

	public function test_top_per_page_limits_queries_of_one_page(): void {
		$rows = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$rows[] = array( 'page' => 'https://example.org/a/', 'query' => 'q' . $i, 'clicks' => 0, 'impressions' => $i, 'position' => 1.0 );
		}

		$store = new ArrayQueryStore();
		$job   = new SyncJob(
			array( 'google' => array( 'current' => static fn(): array => $rows ) ),
			$store,
			new UrlMatcher( static fn(): int => 5, static fn(): int => 0, static fn(): int => 0 )
		);
		$state = $job->start( '2026-11-16', 100 );
		$job->run( $state, 100 );

		self::assertCount( SyncJob::TOP_PER_PAGE, $store->rows );
		self::assertSame( 59, $store->rows[0]['impressions'] );
	}
}
