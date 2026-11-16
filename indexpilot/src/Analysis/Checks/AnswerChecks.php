<?php
/**
 * Проверка краткого ответа.
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
use IndexPilot\Frontend\AnswerBlock;
use IndexPilot\Game\MaterialType;

/**
 * Есть ли у записи краткий ответ и укладывается ли он в 40–60 слов: абзац такого объёма
 * поисковики и ассистенты чаще всего берут в ответ целиком.
 */
final class AnswerChecks implements CheckGroup {

	private const GROUP = 'content';
	private const MAX   = 3;

	/**
	 * Выполняет проверку. У страниц без поля «Краткий ответ» (рубрики, служебные страницы) её нет.
	 * У колод тоже: текста у колоды нет, и красный пункт о кратком ответе там лишний.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $facts, $matcher );

		if ( null === $paper->answer || MaterialType::DECK === $paper->material ) {
			return array();
		}

		$words = AnswerBlock::word_count( $paper->answer );

		if ( 0 === $words ) {
			return array( new Result( 'answer', self::GROUP, Result::BAD, self::MAX, sprintf( 'Краткого ответа нет. Напишите на вкладке «Содержание» суть статьи в %d–%d словах: её увидит читатель в начале текста, её же берут в ответ поисковики и ассистенты.', AnswerBlock::MIN_WORDS, AnswerBlock::MAX_WORDS ) ) );
		}

		if ( $words < AnswerBlock::MIN_WORDS ) {
			return array( new Result( 'answer', self::GROUP, Result::OK, self::MAX, sprintf( 'Краткий ответ короткий: %d слов при рекомендуемых %d–%d. Добавьте главное число или вывод.', $words, AnswerBlock::MIN_WORDS, AnswerBlock::MAX_WORDS ) ) );
		}

		if ( $words > AnswerBlock::MAX_WORDS ) {
			return array( new Result( 'answer', self::GROUP, Result::OK, self::MAX, sprintf( 'Краткий ответ длинный: %d слов при рекомендуемых %d–%d. Оставьте только суть.', $words, AnswerBlock::MIN_WORDS, AnswerBlock::MAX_WORDS ) ) );
		}

		return array( new Result( 'answer', self::GROUP, Result::GOOD, self::MAX, sprintf( 'Краткий ответ есть и укладывается в объём: %d слов.', $words ) ) );
	}
}
