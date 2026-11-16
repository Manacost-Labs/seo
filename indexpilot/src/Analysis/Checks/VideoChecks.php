<?php
/**
 * Текст рядом с роликом.
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
use IndexPilot\Game\MaterialType;
use IndexPilot\Schema\VideoDetector;

/**
 * Поисковики и ассистенты ролик не смотрят — они читают текст вокруг него. Запись со встроенным роликом
 * и почти без текста им пуста (книга, с. 580–581, 689–691). Замечание без баллов; к колодам не применяется.
 */
final class VideoChecks implements CheckGroup {

	private const GROUP = 'content';

	/**
	 * Сколько слов текста достаточно рядом с роликом, если нет краткого ответа.
	 */
	public const MIN_WORDS = 150;

	/**
	 * Выполняет проверку.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $matcher );

		if ( MaterialType::DECK === $paper->material ) {
			return array();
		}

		$videos = VideoDetector::detect( $paper->content );

		if ( array() === $videos ) {
			return array();
		}

		if ( $facts->word_count >= self::MIN_WORDS || '' !== trim( (string) $paper->answer ) ) {
			return array( new Result( 'video_text', self::GROUP, Result::GOOD, 0, 'Рядом с роликом есть текст: поисковик понимает, о чём он.' ) );
		}

		return array(
			new Result(
				'video_text',
				self::GROUP,
				Result::OK,
				0,
				sprintf( 'В записи есть ролик, а текста почти нет: %1$d слов. Поисковики и ассистенты ролик не смотрят — напишите краткий ответ на вкладке «Содержание» или пересказ ролика хотя бы на %2$d слов: главное, состав колоды, ключевые ходы.', $facts->word_count, self::MIN_WORDS )
			),
		);
	}
}
