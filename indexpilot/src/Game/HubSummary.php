<?php
/**
 * Страница-ответ по классу и архетипу: лучшие колоды и фраза-ответ.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Выбирает лучшие актуальные колоды раздела и собирает одну фразу-ответ на вопрос «какая колода лучшая».
 * Лучшей считается колода с наибольшим процентом побед среди сыгравших не меньше порога: колода с пятью играми
 * и случайными 80 % побед лучшей не становится. Если данных о победах нет, колоды идут от новых к старым,
 * а фраза говорит о самой свежей колоде без процентов. Класс не зависит от WordPress.
 */
final class HubSummary {

	/**
	 * Сколько игр нужно, чтобы процент побед колоды считался надёжным.
	 */
	public const MIN_GAMES = 30;

	/**
	 * Шаблоны фразы-ответа. Подстановки: раздел в родительном падеже, дата, колода, процент побед, число игр.
	 */
	public const WORDS = array(
		'best'      => 'Лучшая колода %1$s на %2$s — %3$s, %4$d %% побед по %5$s.',
		'fresh'     => 'Свежая колода %1$s на %2$s — %3$s.',
		'game_one'  => '%d игре',
		'game_many' => '%d играм',
	);

	/**
	 * Классы в родительном падеже: «колода жреца».
	 */
	private const GENITIVE = array(
		'death-knight' => 'рыцаря смерти',
		'demon-hunter' => 'охотника на демонов',
		'druid'        => 'друида',
		'hunter'       => 'охотника',
		'mage'         => 'мага',
		'paladin'      => 'паладина',
		'priest'       => 'жреца',
		'rogue'        => 'разбойника',
		'shaman'       => 'шамана',
		'warlock'      => 'чернокнижника',
		'warrior'      => 'воина',
	);

	/**
	 * Лучшие актуальные колоды: архивные убираются; сначала колоды с достаточным числом игр по проценту побед,
	 * затем остальные от новых к старым.
	 *
	 * @param list<array<string, mixed>> $decks     Колоды: id, title, url, wins, losses, date (Y-m-d…), archived; прочие ключи сохраняются.
	 * @param int                        $limit     Сколько колод вернуть.
	 * @param int                        $min_games Порог числа игр.
	 * @return list<array<string, mixed>> Колоды с добавленными games, winrate (целый процент) и qualified.
	 */
	public static function rank( array $decks, int $limit = 5, int $min_games = self::MIN_GAMES ): array {
		$ranked = array();

		foreach ( $decks as $deck ) {
			if ( ! empty( $deck['archived'] ) ) {
				continue;
			}

			$wins   = max( 0, (int) ( $deck['wins'] ?? 0 ) );
			$losses = max( 0, (int) ( $deck['losses'] ?? 0 ) );
			$games  = $wins + $losses;

			$deck['games']     = $games;
			$deck['rate']      = $games > 0 ? $wins / $games : 0.0;
			$deck['winrate']   = $games > 0 ? (int) round( 100 * $wins / $games ) : 0;
			$deck['qualified'] = $games > 0 && $games >= max( 1, $min_games );
			$ranked[]          = $deck;
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				if ( $a['qualified'] !== $b['qualified'] ) {
					return $a['qualified'] ? -1 : 1;
				}

				if ( $a['qualified'] ) {
					$order = array( $b['rate'], $b['games'] ) <=> array( $a['rate'], $a['games'] );

					if ( 0 !== $order ) {
						return $order;
					}
				}

				return (string) ( $b['date'] ?? '' ) <=> (string) ( $a['date'] ?? '' );
			}
		);

		$result = array();
		foreach ( array_slice( $ranked, 0, max( 0, $limit ) ) as $deck ) {
			unset( $deck['rate'] );
			$result[] = $deck;
		}

		return $result;
	}

	/**
	 * Фраза-ответ: «Лучшая колода жреца на 16.11.2026 — …, 54 % побед по 312 играм.» Без надёжных данных о победах —
	 * «Свежая колода жреца на 16.11.2026 — ….». Пустая строка, если колод нет.
	 *
	 * @param list<array<string, mixed>> $ranked  Колоды из rank().
	 * @param string                     $subject Раздел в родительном падеже: «жреца», «архетипа Blood DK».
	 * @param string                     $date    Дата в том виде, в каком её видит читатель.
	 * @param array<string, string>      $words   Шаблоны фразы; по умолчанию — WORDS.
	 */
	public static function answer( array $ranked, string $subject, string $date, array $words = self::WORDS ): string {
		$first = $ranked[0] ?? null;

		if ( null === $first ) {
			return '';
		}

		$words = $words + self::WORDS;
		$title = trim( (string) ( $first['title'] ?? '' ) );

		if ( empty( $first['qualified'] ) ) {
			return sprintf( $words['fresh'], $subject, $date, $title );
		}

		$games = (int) $first['games'];
		$form  = 1 === $games % 10 && 11 !== $games % 100 ? $words['game_one'] : $words['game_many'];

		return sprintf( $words['best'], $subject, $date, $title, (int) $first['winrate'], sprintf( $form, $games ) );
	}

	/**
	 * Класс в родительном падеже по слагу словаря; пустая строка, если класс неизвестен.
	 *
	 * @param string $slug Слаг класса из Lexicon.
	 */
	public static function genitive( string $slug ): string {
		return self::GENITIVE[ $slug ] ?? '';
	}
}
