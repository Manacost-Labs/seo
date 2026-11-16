<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\ContentChecks;
use IndexPilot\Analysis\Checks\MaterialChecks;
use IndexPilot\Analysis\Checks\ReadabilityChecks;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Readability;
use IndexPilot\Analysis\Report;
use IndexPilot\Game\MaterialType;
use PHPUnit\Framework\TestCase;

final class ReadabilityChecksTest extends TestCase {

	/**
	 * @return array<string, string> Идентификатор => сообщение.
	 */
	private function messages( Report $report ): array {
		$messages = array();

		foreach ( $report->results as $result ) {
			$messages[ $result->id ] = $result->message;
		}

		return $messages;
	}

	public function test_every_issue_is_explained_in_plain_words_with_numbers(): void {
		$long    = 'Эта колода в настоящее время является одной из самых сильных в мете и стоит отметить что она таким образом наказывает медленные архетипы уже к шестому ходу партии без особого риска для игрока.';
		$content = '<p>' . str_repeat( $long . ' ', 4 ) . str_repeat( 'Короткое предложение. ', 6 ) . '</p>'
			. '<p>' . trim( str_repeat( 'высококвалифицированный киберспортсмен ', 80 ) ) . '.</p>';

		$report   = ( new Analyzer( array( new ReadabilityChecks() ) ) )->analyze( new Paper( content: $content ) );
		$messages = $this->messages( $report );
		$measure  = Readability::measure( $content );

		self::assertSame(
			array( 'readability_long_sentences', 'readability_long_paragraphs', 'readability_long_words', 'readability_filler', 'readability_no_lists', 'readability_no_subheadings', 'readability_long_intro' ),
			array_keys( $messages )
		);
		self::assertSame( 'Длинных предложений 5 из 11 — разбейте те, что длиннее 25 слов.', $messages['readability_long_sentences'] );
		self::assertStringContainsString( 'Длинных абзацев 1 из 2', $messages['readability_long_paragraphs'] );
		self::assertStringContainsString( 'длиннее 150 слов', $messages['readability_long_paragraphs'] );
		self::assertMatchesRegularExpression( '/^Длинных слов \d+ % \(длиннее 15 букв\)/u', $messages['readability_long_words'] );
		self::assertStringContainsString( '(4): «в настоящее время», «является», «стоит отметить», «таким образом»', $messages['readability_filler'] );
		self::assertStringContainsString( 'В тексте ' . $measure['words'] . ' слов и ни одного списка', $messages['readability_no_lists'] );
		self::assertStringContainsString( 'ни одного подзаголовка', $messages['readability_no_subheadings'] );
		self::assertStringContainsString( 'Первый абзац длинный: ' . $measure['first_paragraph_words'] . ' слов', $messages['readability_long_intro'] );

		foreach ( $report->results as $result ) {
			self::assertSame( Report::READABILITY, $result->group );
			self::assertSame( 0, $result->max );
			self::assertSame( 'ok', $result->status );
		}

		self::assertSame( $measure['score'], $report->readability );
		self::assertSame( 0, $report->score );
	}

	public function test_long_sentences_message_matches_the_counts(): void {
		$content = '<p>' . str_repeat( 'Маг держит темп с первых ходов и наказывает медленные архетипы, потому что дешёвые заклинания чистят стол, а урон в лицо закрывает игру уже к восьмому ходу партии без лишнего риска. ', 4 ) . str_repeat( 'Это работает. ', 6 ) . '</p>';

		$messages = $this->messages( ( new Analyzer( array( new ReadabilityChecks() ) ) )->analyze( new Paper( content: $content ) ) );

		self::assertSame( 'Длинных предложений 4 из 10 — разбейте те, что длиннее 25 слов.', $messages['readability_long_sentences'] );
	}

	public function test_clean_text_gets_one_good_line(): void {
		$report = ( new Analyzer( array( new ReadabilityChecks() ) ) )->analyze( new Paper( content: '<p>Маг держит темп. Колода закрывает игру к восьмому ходу.</p>' ) );

		self::assertCount( 1, $report->results );
		self::assertSame( 'readability', $report->results[0]->id );
		self::assertSame( 'good', $report->results[0]->status );
		self::assertSame( 100, $report->readability );
	}

	public function test_empty_text_and_decks_are_not_measured(): void {
		$analyzer = new Analyzer( array( new ReadabilityChecks() ) );

		self::assertSame( array(), $analyzer->analyze( new Paper( content: '' ) )->results );
		self::assertSame( array(), $analyzer->analyze( new Paper( content: '<p>Колода «Blood DK» для Hearthstone.</p>', material: MaterialType::DECK ) )->results );
	}

	public function test_guide_without_a_section_gets_a_remark_naming_it(): void {
		$sections = '<h2>Состав колоды</h2><p>Текст.</p><h2>Муллиган</h2><p>Текст.</p><h2>Стратегия</h2><p>Текст.</p><h2>Матчапы</h2><p>Текст.</p>';
		$analyzer = new Analyzer( array( new MaterialChecks() ) );

		$partial = $analyzer->analyze( new Paper( content: $sections, material: MaterialType::GUIDE ) );
		$full    = $analyzer->analyze( new Paper( content: $sections . '<h3>Замены карт</h3><p>Текст.</p>', material: MaterialType::GUIDE ) );
		$empty   = $analyzer->analyze( new Paper( content: '<p>Текст.</p>', material: MaterialType::PATCH ) );

		self::assertSame( 'ok', $partial->results[0]->status );
		self::assertStringStartsWith( 'В гайде нет раздела: Замены.', $partial->results[0]->message );
		self::assertSame( 'good', $full->results[0]->status );
		self::assertStringStartsWith( 'В материале о патче нет разделов: Что изменилось, Как это повлияет на мету.', $empty->results[0]->message );
		self::assertSame( 0, $partial->results[0]->max, 'Тип определяется по косвенным признакам: проверка идёт без баллов.' );
		self::assertSame(
			array(
				'type'  => 'guide',
				'label' => 'Гайд по архетипу',
			),
			$partial->to_array()['material']
		);
	}

	public function test_news_decks_and_unknown_materials_have_no_section_check(): void {
		$analyzer = new Analyzer( array( new MaterialChecks() ) );

		foreach ( array( MaterialType::NEWS, MaterialType::OTHER, MaterialType::DECK, MaterialType::MULLIGAN, '' ) as $material ) {
			self::assertSame( array(), $analyzer->analyze( new Paper( content: '<p>Текст.</p>', material: $material ) )->results, $material );
		}

		self::assertSame( 'Новость', ( new Analyzer() )->analyze( new Paper( material: MaterialType::NEWS ) )->material['label'] );
		self::assertNull( ( new Analyzer() )->analyze( new Paper() )->material );
	}

	public function test_post_without_any_social_image_gets_a_remark(): void {
		$analyzer = new Analyzer( array( new ContentChecks() ) );
		$find     = static function ( Report $report, string $id ): ?array {
			foreach ( $report->results as $result ) {
				if ( $id === $result->id ) {
					return array( $result->status, $result->message );
				}
			}

			return null;
		};

		$without = $find( $analyzer->analyze( new Paper( has_social_image: false ) ), 'social_image' );

		self::assertSame( 'ok', $without[0] );
		self::assertStringContainsString( 'нет картинки для карточки в соцсетях', $without[1] );
		self::assertNull( $find( $analyzer->analyze( new Paper( has_social_image: true ) ), 'social_image' ) );
		self::assertNull( $find( $analyzer->analyze( new Paper() ), 'social_image' ), 'У термина картинка не проверяется.' );
	}

	public function test_noindex_with_a_canonical_to_another_page_is_a_conflict(): void {
		$analyzer = new Analyzer( array( new ContentChecks() ) );
		$ids      = static fn( Paper $paper ): array => array_map( static fn( $result ): string => $result->id, $analyzer->analyze( $paper )->results );

		$conflict = new Paper( noindex: true, url: 'https://example.test/gajd/', canonical: 'https://example.test/drugoj/' );

		self::assertContains( 'noindex_canonical', $ids( $conflict ) );
		self::assertStringContainsString( 'https://example.test/drugoj/', $analyzer->analyze( $conflict )->results[0]->message );
		self::assertSame( 'bad', $analyzer->analyze( $conflict )->results[0]->status );
		self::assertNotContains( 'noindex_canonical', $ids( new Paper( noindex: true, url: 'https://example.test/gajd/', canonical: 'https://Example.test/gajd' ) ), 'Каноникал на себя — не конфликт.' );
		self::assertNotContains( 'noindex_canonical', $ids( new Paper( noindex: false, url: 'https://example.test/gajd/', canonical: 'https://example.test/drugoj/' ) ), 'Открытая страница с чужим каноникалом — обычный случай.' );
		self::assertNotContains( 'noindex_canonical', $ids( new Paper( noindex: true, url: 'https://example.test/gajd/', canonical: '' ) ) );
	}
}
