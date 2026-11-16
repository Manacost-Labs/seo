<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\SearchData;

use IndexPilot\Game\Lexicon;
use IndexPilot\SearchData\TermQueries;
use PHPUnit\Framework\TestCase;

final class TermQueriesTest extends TestCase {

	private function row( string $query, int $clicks, int $impressions, float $position ): array {
		return array(
			'query'       => $query,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'position'    => $position,
		);
	}

	public function test_queries_of_three_posts_are_merged_with_game_lexicon(): void {
		$lexicon = new Lexicon();
		$posts   = array(
			$this->row( 'колода жреца', 10, 300, 4.0 ),
			$this->row( 'дека приста', 2, 100, 8.0 ),
			$this->row( 'Колоды жреца', 1, 100, 12.0 ),
			$this->row( 'муллиган жреца', 5, 80, 6.0 ),
			$this->row( 'гайд на жреца', 3, 60, 5.0 ),
		);
		$own     = array( $this->row( 'жрец hearthstone', 1, 50, 9.0 ) );

		$result = TermQueries::collect( $own, $posts, 'Жрец', array( $lexicon, 'normalize' ) );

		self::assertSame( array( 'колода жреца', 'муллиган жреца', 'гайд на жреца', 'жрец hearthstone' ), array_column( $result['queries'], 'query' ) );
		self::assertSame( 500, $result['queries'][0]['impressions'] );
		self::assertSame( 13, $result['queries'][0]['clicks'] );
		self::assertEqualsWithDelta( 6.4, $result['queries'][0]['position'], 0.01 );
		self::assertSame( 'колода жреца', $result['main'] );
		self::assertSame( array( 'муллиган жреца', 'гайд на жреца', 'жрец hearthstone' ), $result['extra'] );
		self::assertTrue( $result['name_found'] );
	}

	public function test_no_shows_for_term_name_is_noted(): void {
		$lexicon = new Lexicon();
		$result  = TermQueries::collect( array(), array( $this->row( 'колода мага', 1, 10, 3.0 ) ), 'Агро', array( $lexicon, 'normalize' ) );

		self::assertFalse( $result['name_found'] );
		self::assertSame( 'колода мага', $result['main'] );

		$empty = TermQueries::collect( array(), array(), 'Агро', array( $lexicon, 'normalize' ) );
		self::assertSame( '', $empty['main'] );
		self::assertSame( array(), $empty['queries'] );
	}
}
