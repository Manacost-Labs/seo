<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Language\EnglishStemmer;
use IndexPilot\Analysis\Language\RussianStemmer;
use IndexPilot\Game\Lexicon;
use PHPUnit\Framework\TestCase;

final class KeywordMatcherTest extends TestCase {

	private function matcher(): KeywordMatcher {
		return new KeywordMatcher( new RussianStemmer(), new EnglishStemmer() );
	}

	public function test_russian_word_forms_share_one_stem(): void {
		$stemmer = new RussianStemmer();

		self::assertSame( $stemmer->stem( 'колода' ), $stemmer->stem( 'колоды' ) );
		self::assertSame( $stemmer->stem( 'колода' ), $stemmer->stem( 'колодой' ) );
		self::assertSame( $stemmer->stem( 'маг' ), $stemmer->stem( 'мага' ) );
		self::assertSame( $stemmer->stem( 'заклинание' ), $stemmer->stem( 'заклинаниях' ) );
		self::assertSame( $stemmer->stem( 'гайд' ), $stemmer->stem( 'гайды' ) );
		self::assertNotSame( $stemmer->stem( 'маг' ), $stemmer->stem( 'жрец' ) );
	}

	public function test_english_plural_and_ing_forms_share_one_stem(): void {
		$stemmer = new EnglishStemmer();

		self::assertSame( $stemmer->stem( 'deck' ), $stemmer->stem( 'decks' ) );
		self::assertSame( $stemmer->stem( 'hunter' ), $stemmer->stem( 'hunters' ) );
		self::assertSame( 'dk', $stemmer->stem( 'DK' ) );
	}

	public function test_keyword_matches_other_word_forms_and_order(): void {
		$matcher = $this->matcher();

		self::assertTrue( $matcher->contains( 'Лучшие колоды мага в новом сезоне', 'колода мага' ) );
		self::assertTrue( $matcher->contains( 'Гайд: колода для мага на заклинаниях', 'колода мага' ), 'Слово между частями ключа допустимо.' );
		self::assertTrue( $matcher->contains( 'Маг и его колода', 'колода мага' ), 'Порядок слов не важен.' );
		self::assertFalse( $matcher->contains( 'Колода жреца и отдельно про тактику. Много текста. Потом маг.', 'колода мага' ), 'Слова ключа должны стоять рядом.' );
		self::assertFalse( $matcher->contains( 'Колода жреца', 'колода мага' ) );
	}

	public function test_mixed_language_keyword(): void {
		$matcher = $this->matcher();

		self::assertTrue( $matcher->contains( 'Гайд на Blood DK в стандарте', 'blood dk' ) );
		self::assertTrue( $matcher->contains( 'Колоды Reno Demon Hunters', 'reno demon hunter' ) );
	}

	public function test_occurrences_are_counted_without_overlap(): void {
		$text = 'Колода мага сильна. Эту колоду мага собрать легко. Маг играет от темпа.';

		self::assertSame( 2, $this->matcher()->count( $text, 'колода мага' ) );
		self::assertSame( 0, $this->matcher()->count( $text, '' ) );
	}

	public function test_keyword_is_found_in_a_transliterated_slug(): void {
		$matcher = $this->matcher();

		self::assertTrue( $matcher->in_slug( 'luchshie-kolody-maga-sezona', 'колода мага' ) );
		self::assertTrue( $matcher->in_slug( 'gajd-na-blood-dk', 'Blood DK' ) );
		self::assertFalse( $matcher->in_slug( 'kolody-zhrecza', 'колода мага' ) );
	}

	public function test_position_of_first_occurrence(): void {
		$matcher = $this->matcher();

		self::assertSame( 0, $matcher->position( 'Колода мага: гайд на сезон', 'колода мага' ) );
		self::assertSame( 3, $matcher->position( 'Гайд на сезон: колода мага', 'колода мага' ) );
		self::assertSame( -1, $matcher->position( 'Гайд на сезон', 'колода мага' ) );
	}

	public function test_game_synonyms_count_as_the_keyword(): void {
		$matcher = new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Lexicon() );

		self::assertTrue( $matcher->contains( 'Лучшая дека приста в этом сезоне', 'колода жреца' ) );
		self::assertTrue( $matcher->contains( 'Колода жреца на контроле', 'дека приста' ), 'Синоним может стоять и в самом ключе.' );
		self::assertTrue( $matcher->contains( 'Как играть против локов', 'против чернокнижника' ) );
		self::assertTrue( $matcher->contains( 'Сборка ДК на крови', 'колода рыцаря смерти' ) );
		self::assertFalse( $matcher->contains( 'Дека приста', 'колода мага' ) );
		self::assertFalse( $matcher->contains( 'Палка и локация', 'паладин чернокнижник' ) );
		self::assertSame( 2, $matcher->count( 'Дека приста сильна. Эту колоду жреца собрать легко.', 'колода жреца' ) );
		self::assertSame( 1, $matcher->position( 'Гайд: дека приста', 'колода жреца' ) );
		self::assertSame( 2, $matcher->length( 'дека для приста' ) );
		self::assertSame( array( 'дека', 'приста' ), $matcher->words( 'Дека приста' ), 'Счёт слов текста от словаря не зависит.' );
	}

	public function test_slug_matches_the_keyword_as_written_or_through_synonyms(): void {
		$matcher = new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Lexicon() );

		self::assertTrue( $matcher->in_slug( 'deka-prista-na-kontrole', 'дека приста' ), 'Слаг написан теми же словами, что и ключ.' );
		self::assertTrue( $matcher->in_slug( 'koloda-zhrecza', 'дека приста' ), 'Слаг написан каноническими словами.' );
		self::assertFalse( $matcher->in_slug( 'koloda-maga', 'дека приста' ) );
	}

	public function test_two_keywords_are_the_same_when_each_contains_the_other(): void {
		$plain = $this->matcher();
		$game  = new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Lexicon() );

		self::assertTrue( $plain->same( 'колода мага', 'Колоды для мага' ) );
		self::assertFalse( $plain->same( 'колода мага', 'колода мага на заклинаниях' ), 'Более длинный ключ — другой ключ.' );
		self::assertFalse( $plain->same( 'дека приста', 'колода жреца' ), 'Без словаря синонимы не сравниваются.' );
		self::assertTrue( $game->same( 'дека приста', 'колода жреца' ) );
		self::assertTrue( $game->same( 'Сборка локов', 'колода чернокнижника' ) );
		self::assertFalse( $game->same( 'дека приста', 'колода мага' ) );
		self::assertFalse( $game->same( '', '' ) );
	}
}
