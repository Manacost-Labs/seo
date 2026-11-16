<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Admin;

use IndexPilot\Admin\ReadableUrl;
use PHPUnit\Framework\TestCase;

final class ReadableUrlTest extends TestCase {

	public function test_cyrillic_address_is_shown_readable(): void {
		self::assertSame( 'https://example.com/гайд-на-жреца/', ReadableUrl::of( 'https://example.com/%d0%b3%d0%b0%d0%b9%d0%b4-%d0%bd%d0%b0-%d0%b6%d1%80%d0%b5%d1%86%d0%b0/' ) );
	}

	public function test_plain_address_is_unchanged(): void {
		self::assertSame( 'https://example.com/gajd/', ReadableUrl::of( 'https://example.com/gajd/' ) );
	}

	public function test_broken_encoding_keeps_original(): void {
		self::assertSame( 'https://example.com/%ff%fe/', ReadableUrl::of( 'https://example.com/%ff%fe/' ) );
	}
}
