<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Authors;

use IndexPilot\Authors\AuthorSummary;
use IndexPilot\Game\Lexicon;
use PHPUnit\Framework\TestCase;

final class AuthorSummaryTest extends TestCase {

	/**
	 * @return list<array{title: string, url: string, modified: string, weight: int}>
	 */
	private function posts(): array {
		return array(
			array(
				'title'    => 'Гайд: колода жреца на контроль',
				'url'      => 'https://s.test/1/',
				'modified' => '2026-11-01 10:00:00',
				'weight'   => 3,
			),
			array(
				'title'    => 'Жрец в новом патче: муллиган',
				'url'      => 'https://s.test/2/',
				'modified' => '2026-11-10 10:00:00',
				'weight'   => 120,
			),
			array(
				'title'    => 'Лучшие колоды для жреца',
				'url'      => 'https://s.test/3/',
				'modified' => '2026-10-01 10:00:00',
				'weight'   => 0,
			),
			array(
				'title'    => 'Маг на темпе',
				'url'      => 'https://s.test/4/',
				'modified' => '2026-09-01 10:00:00',
				'weight'   => 50,
			),
		);
	}

	public function test_author_with_three_priest_guides_knows_priest(): void {
		$summary = AuthorSummary::build( $this->posts(), new Lexicon(), 'Hearthstone' );

		self::assertSame( array( 'Hearthstone', 'Жрец' ), $summary['knows_about'] );
		self::assertSame( 4, $summary['count'] );
		self::assertSame( '2026-11-10 10:00:00', $summary['updated'] );
		self::assertSame( 'https://s.test/2/', $summary['best'][0]['url'] );
		self::assertSame( 'https://s.test/4/', $summary['best'][1]['url'] );
		self::assertCount( 3, AuthorSummary::build( $this->posts(), new Lexicon(), 'Hearthstone', 3 )['best'] );
	}

	public function test_missing_profile_parts(): void {
		self::assertSame( array( 'description', 'same_as', 'image', 'role' ), AuthorSummary::missing( array( 'name' => 'A' ) ) );
		self::assertSame(
			array(),
			AuthorSummary::missing(
				array(
					'description' => 'Играю с 2014 года.',
					'same_as'     => array( 'https://twitch.tv/a' ),
					'image'       => array( 'url' => 'https://s.test/a.jpg' ),
					'role'        => 'Стример',
				)
			)
		);
	}

	public function test_empty_author(): void {
		$summary = AuthorSummary::build( array(), new Lexicon(), 'Hearthstone' );

		self::assertSame( 0, $summary['count'] );
		self::assertSame( array( 'Hearthstone' ), $summary['knows_about'] );
		self::assertSame( array(), $summary['best'] );
	}
}
