<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\SearchData;

use IndexPilot\Game\Lexicon;
use IndexPilot\SearchData\QueriesReport;
use PHPUnit\Framework\TestCase;

final class QueriesReportTest extends TestCase {

	private function row( string $page, int $id, string $query, int $clicks, int $impressions, float $position, string $period = 'current', string $engine = 'google' ): array {
		return array(
			'engine'      => $engine,
			'period'      => $period,
			'page'        => $page,
			'object_type' => $id > 0 ? 'post' : ( '' === $page ? 'site' : 'unknown' ),
			'object_id'   => $id,
			'query'       => $query,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'position'    => $position,
		);
	}

	private function report(): QueriesReport {
		$lexicon = new Lexicon();

		return new QueriesReport( array( $lexicon, 'normalize' ) );
	}

	public function test_query_shown_for_two_pages_is_reported_as_pair(): void {
		$rows = array(
			$this->row( 'https://example.org/a/', 1, 'колода жреца', 10, 500, 6.0 ),
			$this->row( 'https://example.org/b/', 2, 'дека приста', 2, 120, 12.0 ),
			$this->row( 'https://example.org/c/', 3, 'гайд на мага', 5, 100, 3.0 ),
			// Прошлый период и строки без страницы в пары не попадают.
			$this->row( 'https://example.org/c/', 3, 'колода жреца', 5, 100, 3.0, 'previous' ),
			$this->row( '', 0, 'колода жреца', 5, 100, 3.0, 'current', 'yandex' ),
		);

		$pairs = $this->report()->cannibal( $rows );

		self::assertCount( 1, $pairs );
		self::assertSame( 'колода жреца', $pairs[0]['query'] );
		self::assertSame( array( 1, 2 ), array_column( $pairs[0]['pages'], 'object_id' ) );
		self::assertSame( 620, $pairs[0]['impressions'] );
	}

	public function test_near_top_takes_positions_from_5_to_15_with_shows(): void {
		$rows = array(
			$this->row( 'https://example.org/a/', 1, 'колода жреца', 10, 500, 6.0 ),
			$this->row( 'https://example.org/a/', 1, 'жрец', 1, 900, 14.5 ),
			$this->row( 'https://example.org/b/', 2, 'маг', 1, 900, 3.0 ),
			$this->row( 'https://example.org/c/', 3, 'воин', 1, 900, 25.0 ),
			$this->row( 'https://example.org/d/', 4, 'шаман', 0, 3, 7.0 ),
		);

		$near = $this->report()->near_top( $rows );

		self::assertSame( array( 1 ), array_column( $near, 'object_id' ) );
		self::assertSame( 'жрец', $near[0]['query'] );
		self::assertSame( 1400, $near[0]['impressions'] );
	}

	public function test_losing_pages_compare_periods(): void {
		$rows = array(
			$this->row( 'https://example.org/a/', 1, 'q1', 5, 100, 6.0 ),
			$this->row( 'https://example.org/a/', 1, 'q1', 40, 300, 3.0, 'previous' ),
			$this->row( 'https://example.org/b/', 2, 'q2', 20, 300, 3.0 ),
			$this->row( 'https://example.org/b/', 2, 'q2', 21, 300, 3.0, 'previous' ),
		);

		$losing = $this->report()->losing( $rows );

		self::assertCount( 1, $losing );
		self::assertSame( 1, $losing[0]['object_id'] );
		self::assertSame( 40, $losing[0]['before'] );
		self::assertSame( 5, $losing[0]['after'] );
	}

	public function test_queries_without_suitable_page(): void {
		$rows = array(
			$this->row( '', 0, 'муллиган жреца', 3, 150, 18.0, 'current', 'yandex' ),
			$this->row( '', 0, 'колода жреца', 40, 800, 4.0, 'current', 'yandex' ),
			$this->row( 'https://example.org/a/', 1, 'бюджетная колода мага', 0, 60, 35.0 ),
			$this->row( 'https://example.org/a/', 1, 'гайд на мага', 0, 60, 3.0 ),
		);

		$has_page = static fn( string $query ): bool => 'колода жреца' === $query;
		$topics   = $this->report()->without_page( $rows, $has_page );

		self::assertSame( array( 'муллиган жреца', 'бюджетная колода мага' ), array_column( $topics, 'query' ) );
	}
}
