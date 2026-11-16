<?php
/**
 * Проверки актуальности материалов после патча.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Game\MaterialType;
use IndexPilot\Game\Patch;
use PHPUnit\Framework\TestCase;

/**
 * Гайды и колоды, не обновлённые после патча, устарели; новость не устаревает никогда.
 */
final class PatchTest extends TestCase {

	/**
	 * Гайд, изменённый за день до патча, устарел; изменённый после — нет; новость не устаревает.
	 */
	public function test_outdated(): void {
		$patch = new Patch( '37.0', '2026-11-15' );

		self::assertTrue( $patch->active() );
		self::assertTrue( $patch->outdated( MaterialType::GUIDE, '2026-11-14 23:59:59' ) );
		self::assertFalse( $patch->outdated( MaterialType::GUIDE, '2026-11-16 10:00:00' ) );
		self::assertFalse( $patch->outdated( MaterialType::GUIDE, '2026-11-15 08:00:00' ) );
		self::assertTrue( $patch->outdated( MaterialType::DECK, '2026-10-01 10:00:00' ) );
		self::assertFalse( $patch->outdated( MaterialType::NEWS, '2020-01-01 00:00:00' ) );
		self::assertFalse( $patch->outdated( MaterialType::META, '2020-01-01 00:00:00' ) );

		self::assertTrue( $patch->current( MaterialType::GUIDE, '2026-11-16 10:00:00' ) );
		self::assertFalse( $patch->current( MaterialType::NEWS, '2026-11-16 10:00:00' ) );
		self::assertFalse( $patch->current( MaterialType::GUIDE, '2026-11-01 10:00:00' ) );
	}

	/**
	 * Без номера или даты патча ничего не считается устаревшим; дата принимается и в русском виде.
	 */
	public function test_settings(): void {
		self::assertFalse( ( new Patch( '', '2026-11-15' ) )->active() );
		self::assertFalse( ( new Patch( '37.0', '' ) )->outdated( MaterialType::GUIDE, '2020-01-01 00:00:00' ) );
		self::assertSame( '2026-11-15', Patch::normalize_date( '15.11.2026' ) );
		self::assertSame( '2026-11-15', Patch::normalize_date( ' 2026-11-15 ' ) );
		self::assertSame( '', Patch::normalize_date( '31.02.2026' ) );
		self::assertSame( '', Patch::normalize_date( 'завтра' ) );
		self::assertSame( '2026-11-15', ( new Patch( '37.0', '15.11.2026' ) )->date );
	}
}
