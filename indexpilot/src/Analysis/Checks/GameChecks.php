<?php
/**
 * Проверка названия игры в заголовке.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Checks;

use IndexPilot\Analysis\CheckGroup;
use IndexPilot\Analysis\Facts;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;

/**
 * «Жрец», «маг» и «друид» есть и в Hearthstone, и в World of Warcraft. Если материал второй игры говорит о таком
 * классе, а в заголовке игра не названа, поисковик и читатель могут принять его за материал о первой.
 * Нужные названия заранее считает GameScope::missing_names(); здесь — только замечание.
 */
final class GameChecks implements CheckGroup {

	/**
	 * Выполняет проверку.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $facts, $matcher );

		if ( array() === $paper->missing_game_names ) {
			return array();
		}

		return array(
			new Result(
				'game_in_title',
				'snippet',
				Result::BAD,
				3,
				sprintf( 'Класс из заголовка есть в двух играх, а игра не названа. Добавьте %s: иначе материал примут за материал о другой игре.', implode( ' или ', $paper->missing_game_names ) )
			),
		);
	}
}
