<?php
/** Проверки близких текстов. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Audit;
use IndexPilot\Audit\NearDuplicates;
use PHPUnit\Framework\TestCase;
/** Проверки отпечатков и кандидатов. */
final class NearDuplicatesTest extends TestCase {
	/** Русские абзацы с независимыми словами. */
	private function paragraphs(): array {
		$result = array();
		for ( $p = 0; $p < 10; ++$p ) {
			$words = array();
			for ( $w = 0; $w < 80; ++$w ) { $words[] = 'русское' . hash( 'sha256', "$p:$w" ); }
			$result[] = implode( ' ', $words );
		}
		return $result;
	}
	/** Изменение одного абзаца и посторонний документ. */
	public function test_documents(): void {
		$paragraphs = $this->paragraphs();
		$a = NearDuplicates::fingerprint( implode( "\n\n", $paragraphs ) );
		$paragraphs[4] = str_repeat( 'посторонний другой абзац ', 27 );
		$b = NearDuplicates::fingerprint( implode( "\n\n", $paragraphs ) );
		$c = NearDuplicates::fingerprint( str_repeat( 'кошка собака дерево облако самолёт ', 30 ) );
		$pairs = NearDuplicates::pairs( array( 9 => $a, 2 => $b, 5 => $c, 1 => array() ) );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 2, $pairs[0]['a'] );
		$this->assertSame( 9, $pairs[0]['b'] );
		$this->assertGreaterThanOrEqual( 0.8, $pairs[0]['similarity'] );
	}
	/** Очистка разметки и границы сравнения. */
	public function test_normalization(): void {
		$text = str_repeat( 'ЁЖИК лес река солнце трава ', 15 );
		$this->assertSame( NearDuplicates::fingerprint( $text ), NearDuplicates::fingerprint( '<div>[gallery ids="123"]<b>' . $text . '</b><script>лишние слова</script><style>лишние слова</style></div>' ) );
		$this->assertSame( NearDuplicates::fingerprint( $text ), NearDuplicates::fingerprint( str_replace( 'ЁЖИК', 'ежик', $text ) ) );
		$this->assertSame( array(), NearDuplicates::fingerprint( str_repeat( 'слово ', 30 ) ) );
		$this->assertSame( 0.0, NearDuplicates::similarity( array(), array() ) );
		$this->assertSame( 0.0, NearDuplicates::similarity( array( 1 ), array( 1, 2 ) ) );
		$this->assertSame( 0.5, NearDuplicates::similarity( array( 1, 2 ), array( 1, 3 ) ) );
	}
	/** Уникальность и сортировка. */
	public function test_order(): void {
		$a = range( 1, 64 ); $b = $a; $b[63] = 100;
		$this->assertSame( array( array( 'a' => 3, 'b' => 9, 'similarity' => 1.0 ), array( 'a' => 1, 'b' => 3, 'similarity' => 63 / 64 ), array( 'a' => 1, 'b' => 9, 'similarity' => 63 / 64 ) ), NearDuplicates::pairs( array( 9 => $a, 1 => $b, 3 => $a ) ) );
	}
	/** Большая независимая выборка. */
	public function test_random_fingerprints(): void {
		$items = array();
		for ( $i = 0; $i < 3000; ++$i ) {
			$items[ $i ] = array();
			for ( $j = 0; $j < 64; ++$j ) { $items[ $i ][] = crc32( hash( 'sha256', "$i:$j" ) ); }
		}
		$time = microtime( true );
		$this->assertSame( array(), NearDuplicates::pairs( $items ) );
		$this->assertLessThan( 5, microtime( true ) - $time );
	}
}
