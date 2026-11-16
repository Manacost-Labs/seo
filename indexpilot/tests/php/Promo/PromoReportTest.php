<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Promo;

use IndexPilot\Promo\Report;
use IndexPilot\Tests\Support\ArrayClickStore;
use PHPUnit\Framework\TestCase;

final class PromoReportTest extends TestCase {

	public function test_two_clicks_from_one_post_make_one_row_with_two_hits(): void {
		$store = new ArrayClickStore();
		$store->hit( 'boosty', 15, 3, 0, '2026-11-16' );
		$store->hit( 'boosty', 15, 3, 0, '2026-11-16' );

		$rows = $store->rows( '2026-11-16', '2026-11-16' );

		self::assertCount( 1, $rows );
		self::assertSame( 2, $rows[0]['hits'] );
		self::assertSame(
			array(
				array(
					'post_id' => 15,
					'hits'    => 2,
				),
			),
			Report::by_post( $rows )
		);
	}

	public function test_groups_by_destination_post_variant_and_day(): void {
		$rows = $this->rows();

		self::assertSame( 10, Report::total( $rows ) );
		self::assertSame(
			array(
				array(
					'destination' => 'boosty',
					'hits'        => 7,
				),
				array(
					'destination' => 'tg',
					'hits'        => 3,
				),
			),
			Report::by_destination( $rows )
		);
		self::assertSame(
			array(
				array(
					'post_id' => 15,
					'hits'    => 6,
				),
				array(
					'post_id' => 20,
					'hits'    => 3,
				),
			),
			Report::by_post( $rows )
		);
		self::assertSame(
			array(
				array(
					'rule_id'     => 3,
					'variant'     => 1,
					'destination' => 'boosty',
					'hits'        => 4,
				),
				array(
					'rule_id'     => 3,
					'variant'     => 0,
					'destination' => 'boosty',
					'hits'        => 2,
				),
			),
			Report::by_variant( $rows )
		);
		self::assertSame(
			array(
				array(
					'day'  => '2026-11-16',
					'hits' => 6,
				),
				array(
					'day'  => '2026-11-15',
					'hits' => 4,
				),
			),
			Report::by_day( $rows )
		);
	}

	public function test_best_posts_for_chosen_destinations(): void {
		self::assertSame(
			array(
				'boosty' => array(
					array(
						'post_id' => 15,
						'hits'    => 4,
					),
					array(
						'post_id' => 20,
						'hits'    => 2,
					),
				),
			),
			Report::top_posts( $this->rows(), array( 'boosty', 'hearthpulse' ), 2 )
		);
	}

	public function test_period_is_one_of_allowed_and_includes_today(): void {
		self::assertSame( 30, Report::period( 30 ) );
		self::assertSame( 30, Report::period( 31 ) );
		self::assertSame(
			array(
				'from' => '2026-11-10',
				'to'   => '2026-11-16',
			),
			Report::range( 7, '2026-11-16' )
		);
		self::assertSame( '2026-08-19', Report::range( 90, '2026-11-16' )['from'] );
	}

	/**
	 * Переходы двух дней: блок правила 3 в двух вариантах, блок подписки и ссылка без записи.
	 *
	 * @return list<array{destination: string, post_id: int, rule_id: int, variant: int, day: string, hits: int}>
	 */
	private function rows(): array {
		$rows = array();

		foreach (
			array(
				array( 'boosty', 15, 3, 0, '2026-11-15', 2 ),
				array( 'boosty', 15, 3, 1, '2026-11-16', 2 ),
				array( 'boosty', 20, 3, 1, '2026-11-16', 2 ),
				array( 'boosty', 0, 0, 0, '2026-11-16', 1 ),
				array( 'tg', 15, 0, 0, '2026-11-15', 2 ),
				array( 'tg', 20, 0, 0, '2026-11-16', 1 ),
			) as $row
		) {
			$rows[] = array_combine( array( 'destination', 'post_id', 'rule_id', 'variant', 'day', 'hits' ), $row );
		}

		return $rows;
	}
}
