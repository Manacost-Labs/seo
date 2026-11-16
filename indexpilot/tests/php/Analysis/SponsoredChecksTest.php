<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\SponsoredChecks;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use PHPUnit\Framework\TestCase;

final class SponsoredChecksTest extends TestCase {

	/**
	 * @return array<string, Result>
	 */
	private function results( string $content, bool $sponsored = true ): array {
		$paper   = new Paper( content: $content, site_host: 'example.test', sponsored: $sponsored, own_hosts: array( 'hearthpulse.net', 'boosty.to' ) );
		$results = array();

		foreach ( ( new Analyzer( array( new SponsoredChecks() ) ) )->analyze( $paper )->results as $result ) {
			self::assertSame( 0, $result->max );
			$results[ $result->id ] = $result;
		}

		return $results;
	}

	public function test_paid_post_with_link_without_sponsored_gets_a_remark(): void {
		$results = $this->results( '<p>Реклама. Купите <a href="https://shop.example.com/cards" rel="nofollow">наборы карт</a>.</p>' );

		self::assertSame( Result::BAD, $results['sponsored_links']->status );
		self::assertStringContainsString( 'https://shop.example.com/cards', $results['sponsored_links']->message );
		self::assertSame( Result::GOOD, $results['sponsored_label']->status );
	}

	public function test_links_to_own_projects_and_marked_links_are_fine(): void {
		$results = $this->results(
			'<p>На правах рекламы. <a href="https://hearthpulse.net/decks/">Hearthpulse</a>, <a href="https://www.boosty.to/x">Boosty</a>, '
			. '<a href="/gajd/">гайд</a>, <a href="https://example.test/b/">ещё</a>, <a href="https://shop.example.com/" rel="nofollow sponsored">магазин</a>.</p>'
		);

		self::assertSame( Result::GOOD, $results['sponsored_links']->status );
		self::assertSame( Result::GOOD, $results['sponsored_label']->status );
	}

	public function test_paid_post_without_visible_label_gets_a_remark(): void {
		$results = $this->results( '<p>Отличный магазин <a href="https://shop.example.com/" rel="sponsored">карт</a>.</p>' );

		self::assertSame( Result::BAD, $results['sponsored_label']->status );
		self::assertSame( Result::GOOD, $results['sponsored_links']->status );
	}

	public function test_label_variants_are_recognised(): void {
		foreach ( array( 'Партнёрский материал', 'партнерский материал', 'erid: 2VtzqwXyz', 'РЕКЛАМА' ) as $label ) {
			self::assertTrue( SponsoredChecks::has_label( '<p>' . $label . '</p>' ), $label );
		}

		self::assertFalse( SponsoredChecks::has_label( '<p>Рекламация на колоду не принимается.</p>' ) );
	}

	public function test_nothing_is_required_without_the_mark(): void {
		self::assertSame( array(), $this->results( '<p><a href="https://shop.example.com/">магазин</a></p>', false ) );
	}
}
