<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Health;

use IndexPilot\Health\OutboundCheck;
use IndexPilot\Tests\Support\ArrayHealthStore;
use PHPUnit\Framework\TestCase;

final class OutboundCheckTest extends TestCase {

	private int $now = 1_000_000;

	/**
	 * Ответы по адресам; адрес без ответа отвечает 200.
	 *
	 * @var array<string, int>
	 */
	private array $statuses = array();

	private function check( ArrayHealthStore $store ): OutboundCheck {
		return new OutboundCheck(
			$store,
			fn( string $url, string $kind ): int => $this->statuses[ $url ] ?? 200,
			static function ( float $seconds ): void {
			},
			fn(): float => (float) $this->now
		);
	}

	public function test_address_is_dead_after_three_failures_in_a_row(): void {
		$store = new ArrayHealthStore();
		$store->save( 'link', 'https://gone.test/', array( 'post_id' => 5 ) );
		$this->statuses['https://gone.test/'] = 404;

		$check = $this->check( $store );

		for ( $i = 1; $i <= 2; $i++ ) {
			$check->check_due( 10, $this->now );
			self::assertFalse( OutboundCheck::is_dead( $store->get( 'link', 'https://gone.test/' ) ), 'Сбоев: ' . $i );
			$this->now += OutboundCheck::RECHECK_FAILED;
		}

		$check->check_due( 10, $this->now );

		self::assertTrue( OutboundCheck::is_dead( $store->get( 'link', 'https://gone.test/' ) ) );
		self::assertSame( 5, $store->get( 'link', 'https://gone.test/' )['post_id'] );
	}

	public function test_one_failure_is_not_death_and_success_resets_the_count(): void {
		$store = new ArrayHealthStore();
		$store->save( 'image', 'https://cdn.test/a.png', array() );
		$this->statuses['https://cdn.test/a.png'] = 0;

		$check = $this->check( $store );
		$check->check_due( 10, $this->now );

		self::assertSame( 1, $store->get( 'image', 'https://cdn.test/a.png' )['fails'] );
		self::assertFalse( OutboundCheck::is_dead( $store->get( 'image', 'https://cdn.test/a.png' ) ) );

		$this->statuses['https://cdn.test/a.png'] = 200;
		$this->now                               += OutboundCheck::RECHECK_FAILED;
		$check->check_due( 10, $this->now );

		self::assertSame( 0, $store->get( 'image', 'https://cdn.test/a.png' )['fails'] );
	}

	public function test_blocked_answer_neither_kills_nor_heals(): void {
		self::assertNull( OutboundCheck::failed( 403, 'link' ) );
		self::assertNull( OutboundCheck::failed( 429, 'image' ) );
		self::assertTrue( OutboundCheck::failed( 403, 'video' ), 'Закрытый ролик посмотреть нельзя.' );
		self::assertTrue( OutboundCheck::failed( 0, 'link' ) );
		self::assertTrue( OutboundCheck::failed( 503, 'link' ) );
		self::assertFalse( OutboundCheck::failed( 301, 'link' ) );

		$row = OutboundCheck::record( array( 'fails' => 2 ), 403, 'link', 50 );

		self::assertSame( 2, $row['fails'] );
		self::assertSame( 50, $row['checked'] );
	}

	public function test_fresh_addresses_wait_a_week(): void {
		$store = new ArrayHealthStore();
		$store->save( 'link', 'https://ok.test/', array() );

		$check = $this->check( $store );

		self::assertSame( 1, $check->check_due( 10, $this->now ) );
		self::assertSame( 0, $check->check_due( 10, $this->now + OutboundCheck::RECHECK_FAILED ) );
		self::assertSame( 1, $check->check_due( 10, $this->now + OutboundCheck::RECHECK_OK ) );
	}
}
