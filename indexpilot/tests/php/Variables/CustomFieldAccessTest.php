<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Variables;

use IndexPilot\Variables\CoreVariables;
use PHPUnit\Framework\TestCase;

final class CustomFieldAccessTest extends TestCase {

	public function test_private_fields_are_closed_except_deck_fields(): void {
		self::assertTrue( CoreVariables::field_allowed( 'dust_cost' ) );
		self::assertTrue( CoreVariables::field_allowed( '_dust_cost' ) );
		self::assertTrue( CoreVariables::field_allowed( '_deck_player' ) );
		self::assertFalse( CoreVariables::field_allowed( '_svl_locked_content' ) );
		self::assertFalse( CoreVariables::field_allowed( '_edit_lock' ) );
		self::assertFalse( CoreVariables::field_allowed( '' ) );
	}
}
