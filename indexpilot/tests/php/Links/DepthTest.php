<?php
/** Проверки глубины ссылок. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Links;
use IndexPilot\Links\Depth;
use PHPUnit\Framework\TestCase;
/** Проверки обхода графа. */
final class DepthTest extends TestCase {
	/** Глубина, циклы, короткие пути и порядок. */
	public function test_paths(): void {
		$this->assertSame( array( 1 => 1, 2 => 2, 3 => 3 ), Depth::compute( array( 1 => array( 1, 2, 2 ), 2 => array( 3 ), 3 => array( 1 ) ), array( 3 => 9, 1 => 1 ) ) );
		$this->assertSame( array( 8, 7 ), Depth::unreachable( array( 1 => 1 ), array( 8, 1, 7 ) ) );
		$this->assertSame( array( 2 => 2, 3 => 3 ), Depth::compute( array( 2 => array( 3 ) ), array( 2 => 2 ) ) );
		$this->assertSame( array( 2 => 1, 9 => 1 ), Depth::compute( array(), array( 9 => 1, 2 => 1, 4 => 11 ) ) );
	}
	/** Ограниченный обход большой цепочки. */
	public function test_large_chain(): void {
		$edges = array();
		for ( $i = 1; $i < 20000; ++$i ) { $edges[ $i ] = array( $i + 1 ); }
		$time = microtime( true );
		$this->assertSame( array_combine( range( 1, 10 ), range( 1, 10 ) ), Depth::compute( $edges, array( 1 => 1 ) ) );
		$this->assertLessThan( 5, microtime( true ) - $time );
	}
}
