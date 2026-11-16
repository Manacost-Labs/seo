<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Links;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Links\Suggester;
use PHPUnit\Framework\TestCase;

final class SuggesterTest extends TestCase {

	private function suggester(): Suggester {
		return new Suggester( ( new Analyzer() )->matcher() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function index(): array {
		return $this->suggester()->build(
			array(
				'p1' => 'Маг на заклинаниях: гайд по колоде',
				'p2' => 'Гайд по арене для новичков',
				'p3' => 'Новости патча 37.0',
				'p4' => 'Колода жреца на контроле',
				'p5' => 'Гайд по рыцарю смерти крови',
				't9' => 'Blood DK',
				'p6' => 'Колоды',
			)
		);
	}

	public function test_post_about_the_same_subject_ranks_first(): void {
		$text = '<p>В этом сезоне мага на заклинаниях снова берут в легенду. Разберём муллиган и замены. Посмотрите также другие колоды сезона.</p>';

		$found = $this->suggester()->suggest( $this->index(), $text );

		self::assertSame( 'p1', $found[0]['id'] );
		self::assertNotContains( 'p3', array_column( $found, 'id' ), 'Посторонняя запись не предлагается.' );
		self::assertNotContains( 'p6', array_column( $found, 'id' ), 'Заголовок из одного общего слова не предлагается.' );
	}

	public function test_word_forms_and_latin_names_are_matched(): void {
		$found = $this->suggester()->suggest( $this->index(), 'Против Blood DK играйте от темпа. Колоду жреца на контроле лучше не брать.' );
		$ids   = array_column( $found, 'id' );

		self::assertContains( 't9', $ids );
		self::assertContains( 'p4', $ids );
	}

	public function test_excluded_and_unrelated_candidates_are_skipped(): void {
		$text = 'Маг на заклинаниях держит темп. Гайд по арене для новичков пригодится тем, кто начинает.';

		$found = $this->suggester()->suggest( $this->index(), $text, array( 'p1' ) );
		$ids   = array_column( $found, 'id' );

		self::assertNotContains( 'p1', $ids );
		self::assertContains( 'p2', $ids );
		self::assertSame( array(), $this->suggester()->suggest( $this->index(), 'Совсем другой текст о погоде и кулинарии.' ) );
	}

	public function test_result_is_limited_and_sorted(): void {
		$text  = 'Маг на заклинаниях, гайд по арене для новичков, колода жреца на контроле, гайд по рыцарю смерти крови, Blood DK.';
		$found = $this->suggester()->suggest( $this->index(), $text, array(), 3 );

		self::assertCount( 3, $found );
		self::assertGreaterThanOrEqual( $found[1]['score'], $found[0]['score'] );
		self::assertGreaterThanOrEqual( $found[2]['score'], $found[1]['score'] );
	}

	public function test_game_synonyms_lead_to_the_page_about_the_same_subject(): void {
		$found = $this->suggester()->suggest( $this->index(), 'Дека приста на контроле снова в мете. Против ДК крови она держится хорошо.' );
		$ids   = array_column( $found, 'id' );

		self::assertContains( 'p4', $ids, '«Дека приста» — это «Колода жреца».' );
		self::assertContains( 'p5', $ids, '«ДК» — это «рыцарь смерти».' );
		self::assertNotContains( 'p1', $ids );
	}
}
