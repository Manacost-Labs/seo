<?php
/**
 * Группа проверок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

/**
 * Набор проверок одной темы. Сторонний код может добавить свою группу в анализатор.
 */
interface CheckGroup {

	/**
	 * Выполняет проверки. Неприменимые к странице проверки в результат не попадают.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array;
}
