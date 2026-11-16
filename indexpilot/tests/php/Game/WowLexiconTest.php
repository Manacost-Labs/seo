<?php
/** Проверки игрового контракта.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Tests\Game;

use PHPUnit\Framework\TestCase;
/** Проверяет контракт. */
final class WowLexiconTest extends TestCase {
	/** Проверяет контракт. */
	public function test_contract(): void {
		$l = new \IndexPilot\Game\Lexicons\Wow();
		$this->assertSame( array( 'priest-shadow' ), $l->classes( 'шп' ) );
		$this->assertSame( array( 'mage-frost' ), $l->classes( 'гайд на фрост мага' ) );
		$this->assertSame( array( 'druid-restoration' ), $l->classes( 'рдру в мифик плюс' ) );
		$this->assertSame( array( 'mythic-plus' ), $l->terms( 'рдру в мифик плюс' ) );
		$this->assertSame( array(), $l->classes( 'палка варить локация' ) );
		$this->assertTrue( $l->same( 'гайд шп', 'руководство на жреца тьмы' ) );
	}
	/** Проверяет полноту и термины. */
	public function test_catalog(): void {
		$l = new \IndexPilot\Game\Lexicons\Wow();
		$this->assertCount( 52, $l->class_slugs() );
		foreach ( $l->class_slugs() as $slug ) {
			$this->assertContains( $slug, $l->classes( $l->class_name( $slug ) ), $slug );
		}
		$this->assertSame( array( 'mythic-plus', 'pvp', 'bis' ), $l->terms( 'М+ PvP BiS' ) );
		$this->assertSame( array( 'warlock', 'shaman' ), $l->classes( 'локов и шамом' ) );
		$this->assertTrue( $l->same( 'билд', 'сборка талантов' ) );
		$this->assertTrue( $l->same( 'спек', 'специализация' ) );
	}
}
