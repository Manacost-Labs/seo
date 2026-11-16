<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Audit;

use IndexPilot\Audit\Fingerprints;
use PHPUnit\Framework\TestCase;

final class FingerprintsTest extends TestCase {

	private function article( int $variant ): string {
		$paragraphs = array();

		for ( $p = 0; $p < 20; ++$p ) {
			$words = array();
			for ( $w = 0; $w < 60; ++$w ) {
				$words[] = 'слово' . substr( md5( ( 4 === $p ? $variant : 0 ) . ":$p:$w" ), 0, 8 );
			}
			$paragraphs[] = '<p>' . implode( ' ', $words ) . '</p>';
		}

		return implode( "\n", $paragraphs );
	}

	public function test_texts_differing_by_one_paragraph_are_a_pair_after_storage(): void {
		$stored = array(
			10 => Fingerprints::for_post( $this->article( 0 ), false ),
			20 => Fingerprints::for_post( $this->article( 1 ), false ),
			30 => Fingerprints::for_post( str_repeat( 'кошка собака дерево облако самолёт ', 30 ), false ),
		);

		self::assertSame( 64, count( Fingerprints::decode( $stored[10] ) ) );
		self::assertLessThanOrEqual( 800, strlen( $stored[10] ), 'Отпечаток помещается в столбец таблицы.' );

		$pairs = Fingerprints::pairs( $stored );

		self::assertCount( 1, $pairs );
		self::assertSame( array( 10, 20 ), array( $pairs[0]['a'], $pairs[0]['b'] ) );
		self::assertGreaterThanOrEqual( 0.8, $pairs[0]['similarity'] );
	}

	public function test_decks_and_short_texts_are_not_compared(): void {
		self::assertSame( '', Fingerprints::for_post( $this->article( 0 ), true ) );
		self::assertSame( '', Fingerprints::for_post( str_repeat( 'слово ', 49 ), false ) );
		self::assertSame( array(), Fingerprints::decode( '' ) );
		self::assertSame(
			array(),
			Fingerprints::pairs(
				array(
					1 => '',
					2 => '',
				)
			)
		);
	}
}
