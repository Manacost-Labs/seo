<?php
/** Проверки игрового контракта.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Tests\Game;

use PHPUnit\Framework\TestCase;
/** Проверяет контракт. */
final class GamesTest extends TestCase {
	/** Проверяет контракт. */
	public function test_contract(): void {
		$games                     = \IndexPilot\Game\Games::defaults();
		$games[1]['root_term_ids'] = array( 10 );
		$r                         = new \IndexPilot\Game\Games( $games, 'hearthstone' );
		$this->assertSame( 'wow', $r->for_terms( array( 10 => array() ) ) );
		$this->assertSame( 'hearthstone', $r->for_terms( array() ) );
		$this->assertSame( 'World of Warcraft', $r->get( 'wow' )['schema_name'] );
		$this->assertCount( 2, $r->all() );
		$this->assertSame( 'wow', $r->for_terms( array( 12 => array( 11, 10 ) ) ) );
		$this->assertSame( 'hearthstone', $r->for_terms( array( 20 => array() ) ) );
		$this->assertSame( $r->lexicon( 'wow' ), $r->lexicon( 'wow' ) );
	}
}
