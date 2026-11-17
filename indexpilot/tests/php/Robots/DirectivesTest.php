<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Robots;

use IndexPilot\Robots\Directives;
use PHPUnit\Framework\TestCase;

final class DirectivesTest extends TestCase {

	public function test_limits_cannot_be_relaxed_by_either_provider(): void {
		$existing  = array( 'max-snippet' => '0', 'max-video-preview' => '-1', 'max-image-preview' => 'standard' );
		$requested = array( 'max-snippet:-1', 'max-video-preview:15', 'max-image-preview:none' );

		self::assertSame(
			array( 'max-snippet' => '0', 'max-video-preview' => '15', 'max-image-preview' => 'none' ),
			Directives::merge( $existing, $requested )
		);
	}

	public function test_none_cancels_positive_flags_but_keeps_other_restrictions(): void {
		$merged = Directives::merge(
			array( 'none' => true, 'noarchive' => true, 'nosnippet' => true ),
			array( 'all', 'index', 'follow', 'max-snippet:-1', 'max-video-preview:-1' )
		);
		self::assertSame( array( 'none' => true, 'noarchive' => true, 'nosnippet' => true ), $merged );
	}
}
