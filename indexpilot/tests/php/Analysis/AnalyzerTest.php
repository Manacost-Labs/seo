<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\AnswerChecks;
use IndexPilot\Analysis\Checks\ContentChecks;
use IndexPilot\Analysis\Checks\KeywordChecks;
use IndexPilot\Analysis\Checks\SnippetChecks;
use IndexPilot\Analysis\KeywordUsage;
use IndexPilot\Analysis\Result;
use IndexPilot\CrossSite\KeywordAccess;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Report;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase {

	private function good_paper(): Paper {
		$paragraph = '<p>Колода мага на заклинаниях держит темп с первых ходов и наказывает медленные архетипы. '
			. str_repeat( 'Игрок разыгрывает дешёвые карты, копит ресурсы и закрывает партию к восьмому ходу без лишнего риска. ', 24 ) . '</p>';

		$content = $paragraph
			. '<h2>Как играть: колода мага против агро</h2><p>Против агро колода мага играет от контроля стола. '
			. str_repeat( 'Сохраняйте массовые заклинания до удобного момента и не тратьте их на одиночные цели. ', 12 ) . '</p>'
			. '<p>Смотрите также <a href="https://example.test/gajd-po-mulliganu/">гайд по муллигану</a> и '
			. '<a href="https://hsreplay.net/">статистику</a>.</p>'
			. '<img src="https://example.test/a.jpg" alt="Колода мага: список карт" width="800" height="600">';

		return new Paper(
			title: 'Колода мага на заклинаниях: гайд — Manacost',
			description: 'Колода мага на заклинаниях: список карт, муллиган и тактика против популярных архетипов. Разбираем сильные стороны и типичные ошибки.',
			description_source: 'custom',
			slug: 'koloda-maga-na-zaklinaniyah',
			content: $content,
			keyword: 'колода мага',
			keywords: array(),
			site_host: 'example.test',
			min_words: 300
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function statuses( Report $report ): array {
		$statuses = array();
		foreach ( $report->results as $result ) {
			$statuses[ $result->id ] = $result->status;
		}

		return $statuses;
	}

	public function test_well_optimized_post_gets_a_high_score_and_all_expected_checks(): void {
		$report   = ( new Analyzer() )->analyze( $this->good_paper() );
		$statuses = $this->statuses( $report );

		$expected_ids = array(
			'keyword_set',
			'keyword_in_title',
			'keyword_at_title_start',
			'keyword_in_description',
			'keyword_in_slug',
			'keyword_in_first_paragraph',
			'keyword_in_subheadings',
			'keyword_in_image_alt',
			'keyword_density',
			'keyword_unique',
			'title_width',
			'description_length',
			'slug_length',
			'content_length',
			'internal_links',
			'external_links',
			'images',
			'single_h1',
			'image_alt',
		);

		// Группа «Читаемость» идёт отдельно: в оценку SEO её замечания не входят.
		$readability = array();
		foreach ( $report->results as $result ) {
			if ( Report::READABILITY === $result->group ) {
				$readability[ $result->id ] = $result->status;
				unset( $statuses[ $result->id ] );
			}
		}

		self::assertSame( $expected_ids, array_keys( $statuses ) );
		self::assertSame( array( 'good' ), array_values( array_unique( $statuses ) ), json_encode( $statuses ) );
		self::assertSame( array( 'readability_long_paragraphs', 'readability_no_lists', 'readability_long_intro' ), array_keys( $readability ) );
		self::assertSame( 100, $report->score, 'Замечания о читаемости оценку SEO не снижают.' );
		self::assertIsInt( $report->readability );
		self::assertLessThan( 100, $report->readability );
	}

	public function test_new_image_link_and_video_checks_do_not_lower_a_deck_without_text(): void {
		$deck = new Paper(
			title: 'Колода жреца на воскрешении',
			content: '<p>Колода жреца: 30 карт, стоимость 8400 пыли.</p>',
			keyword: 'колода жреца',
			site_host: 'example.test',
			material: \IndexPilot\Game\MaterialType::DECK,
			featured_image: array(),
			has_social_image: false
		);
		$old  = new Analyzer( array( new KeywordChecks(), new SnippetChecks(), new ContentChecks(), new AnswerChecks() ) );
		$full = ( new Analyzer() )->analyze( $deck );

		self::assertSame( $old->analyze( $deck )->score, $full->score );

		foreach ( $full->results as $result ) {
			self::assertNotContains( $result->id, array( 'image_alt', 'anchor_text', 'sponsored_links', 'video_text', 'featured_image' ) );
		}
	}

	public function test_readability_group_has_its_own_score_and_never_changes_the_seo_score(): void {
		$seo_only = new Analyzer( array( new KeywordChecks(), new SnippetChecks(), new ContentChecks(), new AnswerChecks() ) );

		foreach ( array( $this->good_paper(), new Paper( title: 'Гайд', content: '<p>' . str_repeat( 'Очень длинное предложение без конца и края тянется и тянется дальше ', 40 ) . '.</p>', keyword: 'гайд', answer: '', author_profile_complete: false, days_since_update: 400 ) ) as $paper ) {
			$full  = ( new Analyzer() )->analyze( $paper );
			$plain = $seo_only->analyze( $paper );

			self::assertSame( $plain->score, $full->score );
			self::assertNull( $plain->readability );
			self::assertIsInt( $full->readability );
			self::assertSame( $full->readability, $full->to_array()['readability'] );
		}

		// Даже проверка с баллами, попавшая в группу «Читаемость», оценку SEO не меняет.
		$report = new Report(
			array(
				new Result( 'a', 'content', Result::GOOD, 4, '' ),
				new Result( 'b', Report::READABILITY, Result::BAD, 6, '' ),
			),
			55
		);

		self::assertSame( 100, $report->score );
		self::assertSame( 55, $report->readability );
		self::assertNull( ( new Analyzer() )->analyze( new Paper( title: 'Пусто' ) )->readability, 'Без текста оценки читаемости нет.' );
	}

	public function test_score_is_the_share_of_earned_points(): void {
		$report = ( new Analyzer() )->analyze( $this->good_paper() );

		$earned = 0;
		$max    = 0;
		foreach ( $report->results as $result ) {
			$earned += $result->score;
			$max    += $result->max;
		}

		self::assertSame( (int) round( $earned / $max * 100 ), $report->score );
	}

	public function test_missing_keyword_is_reported_and_keyword_checks_are_skipped(): void {
		$paper          = $this->good_paper();
		$paper->keyword = '';
		$report         = ( new Analyzer() )->analyze( $paper );
		$statuses       = $this->statuses( $report );

		self::assertSame( 'bad', $statuses['keyword_set'] );
		self::assertArrayNotHasKey( 'keyword_in_title', $statuses );
		self::assertLessThan( 100, $report->score );
		self::assertGreaterThan( 0, $report->score );
	}

	public function test_problems_are_detected(): void {
		$paper                     = $this->good_paper();
		$paper->title              = 'Гайд на сезон: всё, что нужно знать новичку и опытному игроку, с примерами, таблицами и подробным разбором — Manacost';
		$paper->description        = '';
		$paper->description_source = 'none';
		$paper->slug               = 'gajd-na-sezon';
		$paper->content            = '<h1>Лишний заголовок</h1><p>Короткий текст без ключа.</p>';
		$paper->duplicates         = array( 'Старый гайд на мага' );

		$statuses = $this->statuses( ( new Analyzer() )->analyze( $paper ) );

		self::assertSame( 'bad', $statuses['keyword_in_title'] );
		self::assertSame( 'bad', $statuses['keyword_in_description'] );
		self::assertSame( 'bad', $statuses['keyword_in_slug'] );
		self::assertSame( 'bad', $statuses['keyword_in_first_paragraph'] );
		self::assertSame( 'bad', $statuses['keyword_unique'] );
		self::assertSame( 'bad', $statuses['title_width'] );
		self::assertSame( 'bad', $statuses['description_length'] );
		self::assertSame( 'bad', $statuses['content_length'] );
		self::assertSame( 'bad', $statuses['single_h1'] );
		self::assertArrayNotHasKey( 'keyword_at_title_start', $statuses, 'Позицию ключа в заголовке нет смысла оценивать, если ключа там нет.' );
	}

	public function test_keyword_stuffing_is_penalized(): void {
		$paper          = $this->good_paper();
		$paper->content = '<p>' . str_repeat( 'Колода мага. Колода мага сильна. ', 60 ) . '</p>';

		$statuses = $this->statuses( ( new Analyzer() )->analyze( $paper ) );

		self::assertSame( 'bad', $statuses['keyword_density'] );
	}

	public function test_auto_description_is_only_acceptable(): void {
		$paper                     = $this->good_paper();
		$paper->description_source = 'auto';

		self::assertSame( 'ok', $this->statuses( ( new Analyzer() )->analyze( $paper ) )['description_length'] );
	}

	public function test_page_without_text_skips_content_checks(): void {
		$paper            = $this->good_paper();
		$paper->content   = '';
		$paper->min_words = 0;

		$statuses = $this->statuses( ( new Analyzer() )->analyze( $paper ) );

		self::assertArrayNotHasKey( 'content_length', $statuses );
		self::assertArrayNotHasKey( 'keyword_density', $statuses );
		self::assertArrayNotHasKey( 'internal_links', $statuses );
		self::assertArrayHasKey( 'keyword_in_title', $statuses );
	}

	public function test_report_exports_plain_data_for_rest_and_storage(): void {
		$data = ( new Analyzer() )->analyze( $this->good_paper() )->to_array();

		self::assertSame( 100, $data['score'] );
		self::assertSame( 'keyword', $data['results'][0]['group'] );
		self::assertNotSame( '', $data['results'][0]['message'] );
	}

	public function test_links_to_redirected_addresses_and_redirected_page_are_reported(): void {
		$clean   = ( new Analyzer() )->analyze( new Paper( content: '<p>Текст.</p>', redirected_links: array() ) );
		$dirty   = ( new Analyzer() )->analyze(
			new Paper(
				content: '<p>Текст.</p>',
				redirected_links: array(
					array(
						'href'  => '/old/',
						'final' => 'https://kolodahearthstone.com/new/',
					),
				),
				redirect_target: 'https://kolodahearthstone.com/moved/'
			)
		);
		$unknown = ( new Analyzer() )->analyze( new Paper( content: '<p>Текст.</p>' ) );

		$status = static function ( Report $report, string $id ): ?string {
			foreach ( $report->results as $result ) {
				if ( $id === $result->id ) {
					return $result->status;
				}
			}

			return null;
		};

		self::assertSame( 'good', $status( $clean, 'redirected_links' ) );
		self::assertNull( $status( $clean, 'redirected_page' ) );
		self::assertSame( 'bad', $status( $dirty, 'redirected_links' ) );
		self::assertSame( 'bad', $status( $dirty, 'redirected_page' ) );
		self::assertNull( $status( $unknown, 'redirected_links' ), 'Без данных о редиректах проверка не выполняется.' );
		self::assertLessThan( $clean->score, $dirty->score );
	}

	public function test_same_keyword_on_the_second_site_gives_a_warning_with_a_link(): void {
		$find = static function ( Report $report ): ?array {
			foreach ( $report->results as $result ) {
				if ( 'keyword_cross_site' === $result->id ) {
					return array( $result->status, $result->message );
				}
			}

			return null;
		};

		$busy    = ( new Analyzer() )->analyze(
			new Paper(
				keyword: 'колода мага',
				partner_matches: array(
					array(
						'title' => 'Маг на заклинаниях',
						'url'   => 'https://kolodahearthstone.com/mage/',
					),
				)
			)
		);
		$free    = ( new Analyzer() )->analyze( new Paper( keyword: 'колода мага', partner_matches: array() ) );
		$offline = ( new Analyzer() )->analyze( new Paper( keyword: 'колода мага' ) );

		self::assertSame( 'ok', $find( $busy )[0] );
		self::assertStringContainsString( 'https://kolodahearthstone.com/mage/', $find( $busy )[1] );
		self::assertSame( 'good', $find( $free )[0] );
		self::assertNull( $find( $offline ), 'Если второй сайт недоступен, проверки просто нет: анализ не ломается.' );
		self::assertGreaterThan( 0, $offline->score );
	}

	public function test_partner_access_needs_the_exact_shared_key(): void {
		$key = str_repeat( 'k', KeywordAccess::MIN_KEY_LENGTH );

		self::assertTrue( KeywordAccess::allows( $key, $key ) );
		self::assertFalse( KeywordAccess::allows( $key, $key . 'x' ) );
		self::assertFalse( KeywordAccess::allows( $key, '' ) );
		self::assertFalse( KeywordAccess::allows( '', '' ), 'Пока ключ не настроен, доступ закрыт для всех.' );
		self::assertFalse( KeywordAccess::allows( 'short', 'short' ) );
	}

	public function test_score_level_matches_the_editor_panel(): void {
		self::assertSame( 'bad', Report::level( 0 ) );
		self::assertSame( 'bad', Report::level( 40 ) );
		self::assertSame( 'ok', Report::level( 41 ) );
		self::assertSame( 'ok', Report::level( 70 ) );
		self::assertSame( 'good', Report::level( 71 ) );
	}

	public function test_keyword_checks_accept_game_synonyms_and_colloquial_class_names(): void {
		$paper = new Paper(
			title: 'Дека приста на контроле: гайд — Manacost',
			description: 'Лучшая дека приста этого сезона: список карт, муллиган и тактика против популярных архетипов.',
			description_source: 'custom',
			slug: 'deka-prista',
			content: '<p>Дека приста на контроле переживает раннюю агрессию и выигрывает за счёт ценности.</p><h2>Как собрать сборку жреца</h2><p>Текст раздела.</p>',
			keyword: 'колода жреца'
		);

		$statuses = $this->statuses( ( new Analyzer() )->analyze( $paper ) );

		self::assertSame( 'good', $statuses['keyword_in_title'] );
		self::assertSame( 'good', $statuses['keyword_at_title_start'] );
		self::assertSame( 'good', $statuses['keyword_in_description'] );
		self::assertSame( 'good', $statuses['keyword_in_first_paragraph'] );
		self::assertSame( 'good', $statuses['keyword_in_subheadings'] );
		self::assertSame( 'bad', $statuses['keyword_in_slug'], 'В слаге нет ни слов ключа, ни их синонимов в каноническом виде.' );

		// Статья про Берн мага начиналась с карточки автора: имя и роль — короткие абзацы, они не первый абзац текста.
		$card = '[su_note]<div class="hs-v-author"><p class="hs-v-author-name">TrueBench</p><p class="hs-v-author-role">Стример, игрок</p></div>[/su_note]'
			. '<p dir="ltr"><strong>Берн Маг</strong> брал небольшой перерыв во время бана, когда мету заполонили Зоолоки.</p>';
		self::assertSame( 'good', $this->statuses( ( new Analyzer() )->analyze( new Paper( title: 'Гайд на Берн Мага', content: $card, keyword: 'берн маг' ) ) )['keyword_in_first_paragraph'] );

		$other = $this->statuses( ( new Analyzer() )->analyze( new Paper( title: 'Дека приста', content: '<p>Дека приста.</p>', keyword: 'колода мага' ) ) );

		self::assertSame( 'bad', $other['keyword_in_title'] );
	}

	public function test_pages_with_a_synonymous_keyword_are_found_as_duplicates(): void {
		$rows = array(
			array(
				'post_id'    => 9,
				'meta_value' => 'Дека приста',
			),
			array(
				'post_id'    => 8,
				'meta_value' => 'колода мага',
			),
			array(
				'post_id'    => 7,
				'meta_value' => 'колоды жреца',
			),
			array(
				'post_id'    => 6,
				'meta_value' => 'колода жреца на контроле',
			),
			array(
				'post_id'    => 5,
				'meta_value' => 'сборка для жреца',
			),
		);

		$matcher = ( new Analyzer() )->matcher();

		self::assertSame( array( 9, 7, 5 ), KeywordUsage::matching( $rows, 'колода жреца', $matcher, 5 ) );
		self::assertSame( array( 9, 7 ), KeywordUsage::matching( $rows, 'колода жреца', $matcher, 2 ) );
		self::assertSame( array(), KeywordUsage::matching( $rows, 'колода друида', $matcher, 5 ) );
	}

	public function test_keyword_list_read_once_follows_keywords_written_in_the_same_request(): void {
		$known = array(
			9 => array(
				'keyword'   => 'колода жреца',
				'protected' => false,
			),
			4 => array(
				'keyword'   => 'гайд по магу',
				'protected' => true,
			),
		);

		// Импорт записал ключ записи 6 после того, как список был прочитан: запись 6 встаёт на своё место по ID.
		$known = KeywordUsage::patch( $known, 6, 'колоды жреца', false );
		self::assertSame( array( 9, 6, 4 ), array_keys( $known ) );

		$matcher = ( new Analyzer() )->matcher();

		self::assertSame( array( 9, 6 ), KeywordUsage::matching( KeywordUsage::candidates( $known, 0, false ), 'колода жреца', $matcher, 5 ) );
		self::assertSame( array( 6 ), KeywordUsage::matching( KeywordUsage::candidates( $known, 9, false ), 'колода жреца', $matcher, 5 ), 'сама запись не считается' );
		self::assertSame( array( 9, 6 ), array_column( KeywordUsage::candidates( $known, 0, true ), 'post_id' ), 'записи под паролем пропускаются' );

		// Пустой ключ убирает запись из списка.
		self::assertSame( array( 9, 4 ), array_keys( KeywordUsage::patch( $known, 6, '', false ) ) );
	}
}
