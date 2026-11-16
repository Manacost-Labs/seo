<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Redirects;

use IndexPilot\Redirects\NotFoundLog;
use IndexPilot\Tests\Support\ArrayNotFoundStore;
use PHPUnit\Framework\TestCase;

final class NotFoundLogTest extends TestCase {

	public function test_noise_is_not_logged(): void {
		$store = new ArrayNotFoundStore();
		$log   = new NotFoundLog( $store );

		foreach ( array( '/.env', '/wp-login.php', '/xmlrpc.php', '/wp-content/uploads/2020/01/x.jpg', '/old/style.css?ver=1', '/.git/config', '/phpmyadmin/', '/wp-admin/setup-config.php', '/x/../../etc/passwd' ) as $url ) {
			self::assertFalse( $log->record( $url ), $url );
		}

		self::assertSame( array(), $store->rows );
	}

	public function test_hundred_hits_make_one_row_with_counter(): void {
		$store = new ArrayNotFoundStore();
		$log   = new NotFoundLog( $store );

		for ( $i = 0; $i < 100; $i++ ) {
			$log->record( '/staryj-gajd/?utm_source=vk', 0 === $i ? 'https://vk.com/wall1' : '' );
		}

		self::assertCount( 1, $store->rows );

		$row = array_values( $store->rows )[0];

		self::assertSame( 100, $row['hits'] );
		self::assertSame( '/staryj-gajd/', $row['url'], 'Параметры в журнал не пишутся: иначе один адрес разойдётся на сотни строк.' );
		self::assertSame( 'https://vk.com/wall1', $row['referrer'] );
	}

	public function test_log_is_trimmed_to_the_limit(): void {
		$store = new ArrayNotFoundStore();
		$log   = new NotFoundLog( $store );

		for ( $i = 1; $i <= 120; $i++ ) {
			$log->record( '/page-' . $i . '/' );
		}

		self::assertSame( array( NotFoundLog::LIMIT - NotFoundLog::TRIM_EVERY, NotFoundLog::LIMIT - NotFoundLog::TRIM_EVERY ), $store->trims );
		self::assertSame( 5000, NotFoundLog::LIMIT );
	}

	public function test_cyrillic_address_is_kept_readable(): void {
		$store = new ArrayNotFoundStore();

		( new NotFoundLog( $store ) )->record( '/%D0%B3%D0%B0%D0%B9%D0%B4/' );

		self::assertSame( '/гайд/', array_values( $store->rows )[0]['url'] );
	}
}
