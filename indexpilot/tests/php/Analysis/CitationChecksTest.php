<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\CitationChecks;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Report;
use IndexPilot\Game\MaterialType;
use PHPUnit\Framework\TestCase;

final class CitationChecksTest extends TestCase {

	/**
	 * @return array<string, string> Идентификатор проверки цитирования => статус.
	 */
	private function statuses( Paper $paper ): array {
		$report   = ( new Analyzer( array( new CitationChecks() ) ) )->analyze( $paper );
		$statuses = array();

		foreach ( $report->results as $result ) {
			self::assertSame( Report::READABILITY, $result->group );
			self::assertSame( 0, $result->max, 'Проверки цитирования идут без баллов.' );
			self::assertStringStartsWith( 'Цитирование: ', $result->message );

			$statuses[ $result->id ] = $result->status;
		}

		return $statuses;
	}

	public function test_ready_article_passes_all_five_checks(): void {
		$statuses = $this->statuses(
			new Paper(
				content: '<p>Винрейт 54 % в патче 37.0 — по данным <a href="https://hsreplay.net/decks/">HSReplay</a>.</p>',
				site_host: 'example.test',
				author_profile_complete: true,
				answer: 'Маг на заклинаниях выигрывает темпом.',
				material: MaterialType::GUIDE,
				days_since_update: 12
			)
		);

		self::assertSame(
			array(
				'citation_answer'        => 'good',
				'citation_dated_numbers' => 'good',
				'citation_sources'       => 'good',
				'citation_author'        => 'good',
				'citation_fresh'         => 'good',
			),
			$statuses
		);
	}

	public function test_unprepared_article_gets_a_remark_for_every_check(): void {
		$paper = new Paper(
			content: '<p>Колода сильная и побеждает часто. Смотрите также <a href="/gajd/">наш гайд</a>.</p>',
			site_host: 'example.test',
			author_profile_complete: false,
			answer: '',
			material: MaterialType::GUIDE,
			days_since_update: 200
		);

		self::assertSame(
			array(
				'citation_answer'        => 'bad',
				'citation_dated_numbers' => 'ok',
				'citation_sources'       => 'ok',
				'citation_author'        => 'ok',
				'citation_fresh'         => 'ok',
			),
			$this->statuses( $paper )
		);

		$report = ( new Analyzer( array( new CitationChecks() ) ) )->analyze( $paper );

		self::assertSame( 0, $report->score, 'Оценка SEO от проверок цитирования не зависит.' );
		self::assertStringContainsString( '200 дней', $report->results[4]->message );
	}

	public function test_numbers_need_a_date_or_a_patch_number_in_the_same_sentence(): void {
		$dated = array(
			'Винрейт 54 % в патче 37.0.',
			'<p>На 15.11.2026 колода сыграла 312 игр.</p>',
			'По данным на 15 ноября 2026 года у колоды 54 % побед.',
			'В ноябре 2026 колоду взяли 12 игроков топ-100.',
			'После обновления 37.2 у колоды 51,5 % побед.',
			'В 2026 году колода выиграла 3 турнира.',
			'Замеры от 2026-11-15: 54 %.',
		);

		foreach ( $dated as $text ) {
			self::assertTrue( CitationChecks::has_dated_number( $text ), $text );
		}

		$undated = array(
			'Винрейт колоды — 54 %.',
			'Патч 37.0 вышел вчера.',
			'Вышел патч 37.0. Винрейт колоды — 54 %.',
			'Материал обновлён 15.11.2026.',
			'В колоде 30 карт и 2 легендарные.',
			'Лучшая колода мая для новичков.',
			'',
		);

		foreach ( $undated as $text ) {
			self::assertFalse( CitationChecks::has_dated_number( $text ), $text );
		}
	}

	public function test_stat_block_counts_as_a_dated_number_and_its_source_as_a_reference(): void {
		$with_source = $this->statuses( new Paper( content: '<p>Текст.</p>[indexpilot_stat value="54 %" label="побед" source="HSReplay" date="15.11.2026"]', answer: 'Ответ.' ) );
		$with_url    = $this->statuses( new Paper( content: '<p>Текст.</p>[indexpilot_stat value="54 %" url="https://hsreplay.net/"]', answer: 'Ответ.' ) );
		$bare        = $this->statuses( new Paper( content: '<p>Текст.</p>[indexpilot_stat value="54 %" label="побед"]', answer: 'Ответ.' ) );
		$internal    = $this->statuses( new Paper( content: '<p>Текст <a href="https://example.test/a/">ссылка</a>.</p>', site_host: 'example.test', answer: 'Ответ.' ) );

		self::assertSame( 'good', $with_source['citation_dated_numbers'] );
		self::assertSame( 'good', $with_source['citation_sources'] );
		self::assertSame( 'good', $with_url['citation_sources'] );
		self::assertSame( 'good', $bare['citation_dated_numbers'] );
		self::assertSame( 'ok', $bare['citation_sources'], 'Факт без источника и адреса источником не считается.' );
		self::assertSame( 'ok', $internal['citation_sources'], 'Ссылка на свой сайт — не источник данных.' );
	}

	public function test_unknown_author_and_unknown_date_are_not_checked(): void {
		$statuses = $this->statuses( new Paper( content: '<p>Текст.</p>', answer: 'Ответ.' ) );

		self::assertSame( array( 'citation_answer', 'citation_dated_numbers', 'citation_sources' ), array_keys( $statuses ) );
	}

	public function test_six_months_is_the_freshness_border(): void {
		$fresh = $this->statuses( new Paper( answer: 'Ответ.', days_since_update: CitationChecks::FRESH_DAYS ) );
		$stale = $this->statuses( new Paper( answer: 'Ответ.', days_since_update: CitationChecks::FRESH_DAYS + 1 ) );

		self::assertSame( 'good', $fresh['citation_fresh'] );
		self::assertSame( 'ok', $stale['citation_fresh'] );
	}

	public function test_decks_and_pages_without_an_answer_field_are_not_checked(): void {
		$deck = new Paper( content: '<p>Колода «Blood DK» для Hearthstone.</p>', author_profile_complete: false, answer: '', material: MaterialType::DECK, days_since_update: 400 );
		$page = new Paper( content: '<p>О сайте.</p>', author_profile_complete: false, answer: null, days_since_update: 400 );

		self::assertSame( array(), $this->statuses( $deck ) );
		self::assertSame( array(), $this->statuses( $page ) );

		// Полный анализ колоды: ни проверки краткого ответа, ни цитирования, ни группы «Читаемость».
		$report = ( new Analyzer() )->analyze( $deck );
		$ids    = array_map( static fn( $result ): string => $result->id, $report->results );

		self::assertNotContains( 'answer', $ids );
		self::assertSame( array(), array_filter( $ids, static fn( string $id ): bool => str_starts_with( $id, 'citation_' ) || str_starts_with( $id, 'readability' ) ) );
		self::assertNull( $report->readability );
		self::assertSame(
			array(
				'type'  => 'deck',
				'label' => 'Колода',
			),
			$report->material
		);
	}
}
