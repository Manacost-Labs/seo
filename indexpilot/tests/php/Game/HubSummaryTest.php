<?php
/**
 * Проверки страницы-ответа по классу и архетипу.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Game\HubSummary;
use PHPUnit\Framework\TestCase;

/**
 * Выбирает лучшие актуальные колоды и собирает фразу-ответ.
 */
final class HubSummaryTest extends TestCase {

	/**
	 * Колода для теста.
	 *
	 * @param int    $id       ID.
	 * @param int    $wins     Победы.
	 * @param int    $losses   Поражения.
	 * @param string $date     Дата.
	 * @param bool   $archived В архиве.
	 * @return array<string, mixed>
	 */
	private static function deck( int $id, int $wins, int $losses, string $date, bool $archived = false ): array {
		return array(
			'id'       => $id,
			'title'    => 'Колода ' . $id,
			'url'      => 'https://hs.test/deck-' . $id . '/',
			'wins'     => $wins,
			'losses'   => $losses,
			'date'     => $date,
			'archived' => $archived,
		);
	}

	/**
	 * Первой идёт колода с лучшим процентом побед среди сыгравших не меньше порога; колода с пятью играми лучшей не становится.
	 */
	public function test_best_deck_needs_enough_games(): void {
		$ranked = HubSummary::rank(
			array(
				self::deck( 1, 4, 1, '2026-11-15' ),
				self::deck( 2, 168, 144, '2026-11-10' ),
				self::deck( 3, 50, 50, '2026-11-14' ),
				self::deck( 4, 400, 100, '2026-11-01', true ),
			)
		);

		self::assertSame( array( 2, 3, 1 ), array_column( $ranked, 'id' ) );
		self::assertSame( 54, $ranked[0]['winrate'] );
		self::assertSame( 312, $ranked[0]['games'] );
		self::assertTrue( $ranked[0]['qualified'] );
		self::assertFalse( $ranked[2]['qualified'] );

		self::assertSame(
			'Лучшая колода жреца на 16.11.2026 — Колода 2, 54 % побед по 312 играм.',
			HubSummary::answer( $ranked, 'жреца', '16.11.2026' )
		);
	}

	/**
	 * Без данных о победах колоды идут от новых к старым, фраза — без процентов.
	 */
	public function test_without_win_data(): void {
		$ranked = HubSummary::rank(
			array(
				self::deck( 1, 0, 0, '2026-11-01' ),
				self::deck( 2, 0, 0, '2026-11-12' ),
			)
		);

		self::assertSame( array( 2, 1 ), array_column( $ranked, 'id' ) );
		self::assertSame( 'Свежая колода жреца на 16.11.2026 — Колода 2.', HubSummary::answer( $ranked, 'жреца', '16.11.2026' ) );
		self::assertSame( '', HubSummary::answer( array(), 'жреца', '16.11.2026' ) );
	}

	/**
	 * Число игр согласуется с предлогом «по»: по 21 игре, по 312 играм.
	 */
	public function test_games_word(): void {
		$ranked = HubSummary::rank( array( self::deck( 7, 11, 10, '2026-11-01' ) ), 5, 20 );

		self::assertStringEndsWith( '52 % побед по 21 игре.', HubSummary::answer( $ranked, 'мага', '16.11.2026' ) );
		self::assertSame( 'рыцаря смерти', HubSummary::genitive( 'death-knight' ) );
	}
}
