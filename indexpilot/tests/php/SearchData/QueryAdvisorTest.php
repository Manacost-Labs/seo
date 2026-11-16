<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\SearchData;

use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Language\EnglishStemmer;
use IndexPilot\Analysis\Language\RussianStemmer;
use IndexPilot\Game\Lexicon;
use IndexPilot\SearchData\QueryAdvisor;
use PHPUnit\Framework\TestCase;

final class QueryAdvisorTest extends TestCase {

	public function test_hints_compare_phrases_through_game_lexicon(): void {
		$matcher = new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Lexicon() );
		$same    = static fn( string $query, string $text ): bool => '' !== trim( $text ) && $matcher->contains( $text, $query );
		$rows    = array(
			array( 'query' => 'дека приста', 'impressions' => 1200, 'clicks' => 5, 'position' => 4.0 ),
			array( 'query' => 'муллиган', 'impressions' => 50, 'clicks' => 5, 'position' => 9.0 ),
		);
		$advisor = new QueryAdvisor();

		$types = array_column( $advisor->hints( $rows, 'Колода жреца на легенду', '', 'колода жреца', $same ), 'type' );
		self::assertSame( array( 'low_ctr' ), $types );

		$hints = $advisor->hints( $rows, 'Обзор патча', '', '', $same );
		self::assertSame( array( 'missing_in_title', 'low_ctr', 'focus_suggestion' ), array_column( $hints, 'type' ) );
		self::assertSame( 1200, $hints[0]['impressions'] );
	}
}
