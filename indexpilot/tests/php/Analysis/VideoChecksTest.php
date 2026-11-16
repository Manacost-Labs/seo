<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\VideoChecks;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use PHPUnit\Framework\TestCase;

final class VideoChecksTest extends TestCase {

	private const VIDEO = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>';

	/**
	 * @return list<Result>
	 */
	private function results( Paper $paper ): array {
		return ( new Analyzer( array( new VideoChecks() ) ) )->analyze( $paper )->results;
	}

	public function test_video_with_thirty_words_gets_a_remark(): void {
		$results = $this->results( new Paper( content: self::VIDEO . '<p>' . str_repeat( 'слово ', 30 ) . '</p>', answer: '' ) );

		self::assertCount( 1, $results );
		self::assertSame( 'video_text', $results[0]->id );
		self::assertSame( Result::OK, $results[0]->status );
		self::assertSame( 0, $results[0]->max );
	}

	public function test_video_with_short_answer_is_fine(): void {
		$results = $this->results( new Paper( content: self::VIDEO . '<p>Коротко.</p>', answer: 'Жрец на воскрешении сильнее всего против контроля.' ) );

		self::assertSame( Result::GOOD, $results[0]->status );
	}

	public function test_video_with_long_text_is_fine(): void {
		$results = $this->results( new Paper( content: self::VIDEO . '<p>' . str_repeat( 'слово ', 160 ) . '</p>' ) );

		self::assertSame( Result::GOOD, $results[0]->status );
	}

	public function test_text_without_video_has_no_result(): void {
		self::assertSame( array(), $this->results( new Paper( content: '<p>Коротко.</p>' ) ) );
	}
}
