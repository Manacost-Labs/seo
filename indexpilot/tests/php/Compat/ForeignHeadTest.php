<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Compat;

use IndexPilot\Compat\ForeignHead;
use PHPUnit\Framework\TestCase;

final class ForeignHeadTest extends TestCase {

	public function test_only_known_foreign_head_output_is_removed(): void {
		self::assertTrue( ForeignHead::matches( 'KolodaHearthstone_Decks', 'output_og_tags' ) );
		self::assertFalse( ForeignHead::matches( 'KolodaHearthstone_Decks', 'output_styles' ), 'Стили плагина колод остаются.' );
		self::assertTrue( ForeignHead::matches( 'HS_Decks_Manager', 'render_archetype_seo_meta' ) );
		self::assertFalse( ForeignHead::matches( 'Other_Plugin', 'output_og_tags' ) );
		self::assertTrue( ForeignHead::matches( 'Theme_Seo', 'meta', array( 'Theme_Seo' => array( 'meta' ) ) ) );
	}
}
