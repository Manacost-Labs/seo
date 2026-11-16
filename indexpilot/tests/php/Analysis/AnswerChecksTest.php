<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use PHPUnit\Framework\TestCase;

final class AnswerChecksTest extends TestCase {

	private function check( ?string $answer ): ?Result {
		$report = ( new Analyzer() )->analyze( new Paper( title: 'Колода мага', content: '<p>Текст.</p>', answer: $answer ) );

		foreach ( $report->results as $result ) {
			if ( 'answer' === $result->id ) {
				return $result;
			}
		}

		return null;
	}

	public function test_answer_of_forty_to_sixty_words_is_good(): void {
		$result = $this->check( trim( str_repeat( 'слово ', 45 ) ) );

		self::assertNotNull( $result );
		self::assertSame( Result::GOOD, $result->status );
		self::assertSame( 'content', $result->group );
		self::assertStringContainsString( '45', $result->message );
	}

	public function test_missing_answer_is_reported(): void {
		$result = $this->check( '' );

		self::assertNotNull( $result );
		self::assertSame( Result::BAD, $result->status );
		self::assertStringContainsString( 'Содержание', $result->message );
	}

	public function test_answer_out_of_range_is_a_warning(): void {
		$short = $this->check( 'Слишком короткий ответ.' );
		$long  = $this->check( trim( str_repeat( 'слово ', 80 ) ) );

		self::assertSame( Result::OK, $short->status );
		self::assertStringContainsString( '3', $short->message );
		self::assertSame( Result::OK, $long->status );
		self::assertStringContainsString( '80', $long->message );
	}

	public function test_pages_without_the_field_are_not_checked(): void {
		self::assertNull( $this->check( null ) );
	}
}
