<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\AnchorChecks;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use PHPUnit\Framework\TestCase;

final class AnchorChecksTest extends TestCase {

	/**
	 * @return list<Result>
	 */
	private function results( string $content ): array {
		return ( new Analyzer( array( new AnchorChecks() ) ) )->analyze( new Paper( content: $content, site_host: 'example.test' ) )->results;
	}

	public function test_link_named_here_gets_a_remark_with_its_address(): void {
		$results = $this->results( '<p>Гайд по муллигану — <a href="https://example.test/gajd/">здесь</a>.</p>' );

		self::assertCount( 1, $results );
		self::assertSame( 'anchor_text', $results[0]->id );
		self::assertSame( Result::OK, $results[0]->status );
		self::assertSame( 0, $results[0]->max, 'Замечание о тексте ссылки оценку не меняет.' );
		self::assertStringContainsString( 'https://example.test/gajd/', $results[0]->message );
		self::assertStringContainsString( '«здесь»', $results[0]->message );
	}

	public function test_descriptive_link_has_no_remark(): void {
		self::assertSame( array(), $this->results( '<p>Читайте <a href="/mulligan/">муллиган колоды жреца</a>.</p>' ) );
	}

	public function test_vague_words_bare_address_and_image_without_alt_are_found(): void {
		$found = AnchorChecks::vague(
			'<a href="/a/">Подробнее…</a> <a href="/b/">Читать далее</a> <a href="/c/">по ссылке</a> <a href="/d/">ТУТ</a> '
			. '<a href="https://hsreplay.net/decks/">https://hsreplay.net/decks/</a> <a href="/e/"><img src="/x.png" alt=""></a> '
			. '<a href="/f/"><img src="/y.png" alt="Колода жреца"></a> <a href="/g/" aria-label="Колода мага"><img src="/z.png"></a> '
			. '<a href="#top">здесь</a> <a href="/h/">клик</a>'
		);

		self::assertSame( array( '/a/', '/b/', '/c/', '/d/', 'https://hsreplay.net/decks/', '/e/', '/h/' ), array_column( $found, 'href' ) );
		self::assertSame( 'image', $found[5]['kind'] );
		self::assertSame( 'address', $found[4]['kind'] );
	}

	public function test_text_without_links_has_no_remark(): void {
		self::assertSame( array(), $this->results( '' ) );
		self::assertSame( array(), $this->results( '<p>Подробнее о колоде — в следующем разделе.</p>' ) );
	}
}
