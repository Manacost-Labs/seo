<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Links;

use IndexPilot\Links\Depth;
use IndexPilot\Links\DepthMap;
use PHPUnit\Framework\TestCase;

final class DepthMapTest extends TestCase {

	public function test_post_linked_only_from_a_second_level_post_has_depth_three(): void {
		// Главная (1) → запись 2 → запись 3 → запись 4.
		$depths = Depth::compute(
			array(
				1 => array( 2 ),
				2 => array( 3 ),
				3 => array( 4 ),
			),
			array( 1 => 0 )
		);

		self::assertSame( 3, $depths[4] );
	}

	public function test_deep_cornerstone_posts_go_first(): void {
		$rows = DepthMap::deep(
			array(
				10 => 1,
				11 => 5,
				12 => 4,
				13 => 6,
				14 => 3,
			),
			array( 12, 14 )
		);

		self::assertSame( array( 12, 13, 11 ), array_column( $rows, 'post_id' ) );
		self::assertTrue( $rows[0]['cornerstone'] );
		self::assertFalse( $rows[1]['cornerstone'] );
		self::assertSame( 4, $rows[0]['depth'] );
	}

	public function test_distribution_counts_posts_per_level(): void {
		self::assertSame(
			array(
				1 => 2,
				2 => 1,
				4 => 1,
			),
			DepthMap::distribution(
				array(
					5 => 2,
					6 => 1,
					7 => 4,
					8 => 1,
					9 => 0,
				)
			)
		);
	}

	public function test_start_points_keep_the_smallest_depth(): void {
		self::assertSame(
			array(
				3 => 0,
				4 => 1,
				5 => 1,
			),
			DepthMap::merge_starts( array( 3 => 0 ), array( 4, 3, 5, 0 ) )
		);
	}
}
