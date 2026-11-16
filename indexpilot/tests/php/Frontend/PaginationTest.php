<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Frontend;

use IndexPilot\Frontend\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase {

	public function test_neighbours(): void {
		self::assertSame(
			array(
				'prev' => 0,
				'next' => 2,
			),
			Pagination::neighbours( 1, 3 )
		);
		self::assertSame(
			array(
				'prev' => 1,
				'next' => 3,
			),
			Pagination::neighbours( 2, 3 )
		);
		self::assertSame(
			array(
				'prev' => 2,
				'next' => 0,
			),
			Pagination::neighbours( 3, 3 )
		);
		self::assertSame(
			array(
				'prev' => 0,
				'next' => 0,
			),
			Pagination::neighbours( 1, 1 )
		);
		self::assertSame(
			array(
				'prev' => 0,
				'next' => 0,
			),
			Pagination::neighbours( 1, 0 )
		);
	}

	public function test_post_parts_and_urls(): void {
		self::assertSame( 3, Pagination::post_parts( 'a<!--nextpage-->b<!--nextpage-->c' ) );
		self::assertSame( 'https://s.test/gajd/', Pagination::post_page_url( 'https://s.test/gajd/', 1, true ) );
		self::assertSame( 'https://s.test/gajd/2/', Pagination::post_page_url( 'https://s.test/gajd/', 2, true ) );
		self::assertSame( 'https://s.test/?p=5&page=2', Pagination::post_page_url( 'https://s.test/?p=5', 2, false ) );
	}
}
