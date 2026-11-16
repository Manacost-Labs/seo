<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Health;

use IndexPilot\Game\MaterialType;
use IndexPilot\Health\StalePages;
use PHPUnit\Framework\TestCase;

final class StalePagesTest extends TestCase {

	private const NOW = 1000 * 86400;

	private function page( int $id, string $title, int $clicks, int $impressions, array $extra = array() ): array {
		return $extra + array(
			'id'          => $id,
			'title'       => $title,
			'type'        => MaterialType::OTHER,
			'published'   => self::NOW - 200 * 86400,
			'words'       => 600,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'inbound'     => 0,
			'kept'        => false,
			'stems'       => array(),
		);
	}

	public function test_page_with_two_clicks_is_alive_and_dead_page_gets_similar(): void {
		$pages = array(
			$this->page( 1, 'Колода жреца на дракона', 2, 50, array( 'stems' => array( 'колод', 'жрец', 'дракон' ) ) ),
			$this->page( 2, 'Колода жреца драконы', 0, 3, array( 'stems' => array( 'колод', 'жрец', 'дракон' ) ) ),
			$this->page( 3, 'Новость о турнире', 0, 0, array( 'stems' => array( 'новост', 'турнир' ) ) ),
		);

		$stale = StalePages::find( $pages, self::NOW );

		self::assertSame( array( 2, 3 ), array_column( $stale, 'id' ) );
		self::assertSame( 1, $stale[0]['similar'] );
		self::assertSame( 'merge', $stale[0]['advice'] );
		self::assertStringContainsString( '0 кликов', $stale[0]['reason'] );
		self::assertSame( 0, $stale[1]['similar'] );
	}

	public function test_short_deck_with_clicks_is_not_stale_and_short_text_is_not_a_reason(): void {
		$deck = $this->page( 5, 'Колода мага', 4, 80, array( 'type' => MaterialType::DECK, 'words' => 40 ) );
		self::assertSame( array(), StalePages::find( array( $deck ), self::NOW ) );

		$quiet = StalePages::find( array( $this->page( 6, 'Колода воина', 0, 0, array( 'type' => MaterialType::DECK, 'words' => 40 ) ) ), self::NOW );
		self::assertSame( 'noindex', $quiet[0]['advice'] );
		self::assertStringNotContainsString( 'слов', $quiet[0]['reason'] );
	}

	public function test_young_linked_or_kept_pages_are_skipped(): void {
		$pages = array(
			$this->page( 7, 'Свежая', 0, 0, array( 'published' => self::NOW - 30 * 86400 ) ),
			$this->page( 8, 'Со ссылкой', 0, 0, array( 'inbound' => 2 ) ),
			$this->page( 9, 'Оставлена', 0, 0, array( 'kept' => true ) ),
			$this->page( 10, 'Есть показы', 0, 40 ),
		);

		self::assertSame( array(), StalePages::find( $pages, self::NOW ) );
	}

	public function test_page_with_some_shows_gets_update_advice(): void {
		$stale = StalePages::find( array( $this->page( 11, 'Гайд', 0, 12 ) ), self::NOW );

		self::assertSame( 'update', $stale[0]['advice'] );
	}
}
