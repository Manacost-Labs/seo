<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Meta;

use IndexPilot\Meta\PrimaryCategory;
use PHPUnit\Framework\TestCase;

final class PrimaryCategoryTest extends TestCase {

	public function test_choice_works_only_while_the_post_is_in_that_category(): void {
		self::assertSame( 7, PrimaryCategory::choose( array( 3, 7 ), 7 ) );
		self::assertSame( 0, PrimaryCategory::choose( array( 3, 7 ), 9 ) );
		self::assertSame( 0, PrimaryCategory::choose( array( 3, 7 ), 0 ) );
		self::assertSame( 0, PrimaryCategory::choose( array(), 7 ) );
	}

	public function test_primary_category_goes_first_and_the_rest_keep_their_order(): void {
		$terms = array(
			(object) array( 'term_id' => 3 ),
			(object) array( 'term_id' => 5 ),
			(object) array( 'term_id' => 7 ),
		);

		self::assertSame( array( 7, 3, 5 ), array_column( PrimaryCategory::first( $terms, 7 ), 'term_id' ) );
		self::assertSame( array( 3, 5, 7 ), array_column( PrimaryCategory::first( $terms, 0 ), 'term_id' ) );
		self::assertSame( array( 3, 5, 7 ), array_column( PrimaryCategory::first( $terms, 99 ), 'term_id' ) );
	}
}
