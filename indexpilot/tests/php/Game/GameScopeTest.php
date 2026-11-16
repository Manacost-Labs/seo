<?php
/**
 * Разведение игр: занятые ключи, подсказки ссылок, название игры в заголовке.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\GameChecks;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\KeywordUsage;
use IndexPilot\Analysis\Language\EnglishStemmer;
use IndexPilot\Analysis\Language\RussianStemmer;
use IndexPilot\Analysis\Paper;
use IndexPilot\Game\Games;
use IndexPilot\Game\GameScope;
use IndexPilot\Game\HubSummary;
use IndexPilot\Game\Lexicons\Wow;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\Patch;
use IndexPilot\Game\WowMaterialType;
use IndexPilot\Links\Suggester;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет, что игры не смешиваются.
 */
final class GameScopeTest extends TestCase {

	/**
	 * Реестр, в котором раздел WoW — рубрика 10.
	 */
	private static function games(): Games {
		$games                     = Games::defaults();
		$games[1]['root_term_ids'] = array( 10 );

		return new Games( $games, 'hearthstone' );
	}

	/**
	 * Ключ «гайд на жреца» в разделе WoW не занят записью из Hearthstone, но даёт замечание «добавьте название игры».
	 */
	public function test_wow_keyword_is_not_taken_by_hearthstone_but_title_needs_game_name(): void {
		$rows    = array(
			array(
				'post_id'    => 9,
				'meta_value' => 'Гайд на жреца',
				'game'       => '',
			),
			array(
				'post_id'    => 7,
				'meta_value' => 'гайд на жреца',
				'game'       => 'wow',
			),
		);
		$matcher = ( new Analyzer() )->matcher();

		self::assertSame( array( 7 ), KeywordUsage::matching( $rows, 'гайд на жреца', $matcher, 5, 'wow', 'hearthstone' ) );
		self::assertSame( array( 9 ), KeywordUsage::matching( $rows, 'гайд на жреца', $matcher, 5, 'hearthstone', 'hearthstone' ) );
		self::assertSame( array( 9, 7 ), KeywordUsage::matching( $rows, 'гайд на жреца', $matcher, 5 ), 'Без игры сравниваются все записи.' );

		$games = self::games();

		self::assertSame( array( 'World of Warcraft', 'WoW' ), GameScope::missing_names( 'Гайд на жреца Тьмы — Сайт', 'wow', $games ) );
		self::assertSame( array(), GameScope::missing_names( 'Гайд на жреца Тьмы в WoW', 'wow', $games ) );
		self::assertSame( array(), GameScope::missing_names( 'Жрец Тьма: гайд World of Warcraft', 'wow', $games ) );
		self::assertSame( array(), GameScope::missing_names( 'Гайд на монаха', 'wow', $games ), 'Монаха в Hearthstone нет: путать не с чем.' );
		self::assertSame( array(), GameScope::missing_names( 'Тактика на босса', 'wow', $games ) );
		self::assertSame( array(), GameScope::missing_names( 'Гайд на жреца', 'hearthstone', $games ), 'Игра сайта по умолчанию названия не требует.' );
		self::assertSame( array(), GameScope::missing_names( 'Гайд на жреца', 'wow', new Games( Games::defaults(), 'hearthstone' ) ), 'Раздел WoW не настроен.' );
	}

	/**
	 * Проверка анализа: замечание в заголовке и словарь игры записи.
	 */
	public function test_analysis_uses_game_of_post(): void {
		$wow      = new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), new Wow() );
		$analyzer = new Analyzer( null, null, static fn( string $game ): ?KeywordMatcher => 'wow' === $game ? $wow : null );
		$paper    = new Paper(
			title: 'Гайд на жреца Тьмы — Сайт',
			keyword: 'шп',
			content: '<h2>Таланты</h2><p>Текст.</p>',
			material: WowMaterialType::GUIDE,
			material_types: WowMaterialType::class,
			game: 'wow',
			missing_game_names: array( 'World of Warcraft', 'WoW' )
		);
		$report   = $analyzer->analyze( $paper );
		$by_id    = array();

		foreach ( $report->results as $result ) {
			$by_id[ $result->id ] = $result;
		}

		self::assertSame( 'good', $by_id['keyword_in_title']->status, 'По словарю WoW «шп» — это жрец тьмы.' );
		self::assertSame( 'bad', $by_id['game_in_title']->status );
		self::assertStringContainsString( 'World of Warcraft или WoW', $by_id['game_in_title']->message );
		self::assertStringContainsString( 'Ротация', $by_id['material_sections']->message );
		self::assertSame( 'Гайд по классу и специализации', $report->material['label'] );

		$hearthstone = ( new Analyzer() )->analyze( new Paper( title: 'Гайд на жреца', keyword: 'шп' ) );
		$ids         = array();
		foreach ( $hearthstone->results as $result ) {
			$ids[ $result->id ] = $result->status;
		}

		self::assertSame( 'bad', $ids['keyword_in_title'], 'В Hearthstone «шп» ничего не значит.' );
		self::assertArrayNotHasKey( 'game_in_title', $ids );
		self::assertSame( array(), ( new GameChecks() )->run( new Paper(), new \IndexPilot\Analysis\Facts( new Paper(), ( new Analyzer() )->matcher() ), ( new Analyzer() )->matcher() ) );
	}

	/**
	 * Подсказки ссылок — только на записи той же игры.
	 */
	public function test_suggestions_stay_inside_game(): void {
		$suggester = new Suggester( ( new Analyzer() )->matcher() );
		$index     = $suggester->build(
			array(
				'p1' => 'Гайд на жреца тьмы',
				'p2' => 'Гайд на жреца через контроль',
				'p3' => 'Новости патча',
			),
			array(
				'p1' => 'wow',
				'p2' => 'hearthstone',
				'p3' => 'hearthstone',
			)
		);
		$text      = 'Этот гайд на жреца тьмы через контроль разбирает всё.';

		self::assertSame( array( 'p1' ), array_column( $suggester->suggest( $index, $text, array(), 5, 'wow' ), 'id' ) );
		self::assertSame( array( 'p2' ), array_column( $suggester->suggest( $index, $text, array(), 5, 'hearthstone' ), 'id' ) );
		self::assertCount( 2, $suggester->suggest( $index, $text ), 'Без игры подсказки не ограничены.' );
	}

	/**
	 * Отчёт «Одинаковые ключи в разных играх».
	 */
	public function test_cross_game_keywords(): void {
		$groups = GameScope::cross_game_groups(
			array(
				array(
					'post_id' => 1,
					'keyword' => 'Гайд на жреца',
					'game'    => '',
				),
				array(
					'post_id' => 2,
					'keyword' => 'гайд  на жреца',
					'game'    => 'wow',
				),
				array(
					'post_id' => 3,
					'keyword' => 'колода мага',
					'game'    => '',
				),
				array(
					'post_id' => 4,
					'keyword' => 'колода мага',
					'game'    => 'hearthstone',
				),
			),
			'hearthstone'
		);

		self::assertSame(
			array(
				array(
					'keyword' => 'гайд на жреца',
					'posts'   => array( 1, 2 ),
				),
			),
			$groups
		);
	}

	/**
	 * Предки рубрик по карте родителей; петля в данных не зацикливает.
	 */
	public function test_ancestry_from_parents(): void {
		$parents = array(
			12 => 11,
			11 => 10,
			10 => 0,
			20 => 21,
			21 => 20,
		);

		self::assertSame(
			array(
				12 => array( 11, 10 ),
				20 => array( 21 ),
			),
			GameScope::ancestry( array( 12, 20 ), $parents )
		);
		self::assertSame( 'wow', self::games()->for_terms( GameScope::ancestry( array( 12 ), $parents ) ) );
		self::assertSame( array( 10, 11, 12 ), GameScope::descendants( array( 10 ), $parents ) );
	}

	/**
	 * Патч WoW следит за своими типами материалов.
	 */
	public function test_wow_patch_tracks_wow_materials(): void {
		$patch = new Patch( '11.2', '2026-11-01', Patch::TRACKED_WOW );

		self::assertTrue( $patch->outdated( WowMaterialType::BOSS, '2026-10-01 10:00:00' ) );
		self::assertFalse( $patch->outdated( WowMaterialType::NEWS, '2026-10-01 10:00:00' ) );
		self::assertFalse( ( new Patch( '37.0', '2026-11-01' ) )->outdated( WowMaterialType::BOSS, '2026-10-01 10:00:00' ) );
		self::assertTrue( ( new Patch( '37.0', '2026-11-01' ) )->outdated( MaterialType::MULLIGAN, '2026-10-01 10:00:00' ) );
	}

	/**
	 * Блок-ответ класса WoW собирается из гайдов.
	 */
	public function test_guide_answer(): void {
		$guides = array(
			array(
				'title' => 'Гайд на жреца Тьмы',
				'url'   => 'https://example.com/a/',
			),
		);

		self::assertSame( 'Свежий гайд для жреца на 16.11.2026 — Гайд на жреца Тьмы.', HubSummary::guide_answer( $guides, 'жреца', '16.11.2026' ) );
		self::assertSame( '', HubSummary::guide_answer( array(), 'жреца', '16.11.2026' ) );
		self::assertSame( 'монаха', HubSummary::genitive( 'monk' ) );
		self::assertSame( 'жреца (Тьма)', GameScope::subject( 'priest-shadow', new Wow() ) );
		self::assertSame( 'жреца', GameScope::subject( 'priest', new Wow() ) );
	}
}
