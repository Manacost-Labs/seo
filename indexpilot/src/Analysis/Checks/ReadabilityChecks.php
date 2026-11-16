<?php
/**
 * Замечания о читаемости текста.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Checks;

use IndexPilot\Analysis\CheckGroup;
use IndexPilot\Analysis\Facts;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Readability;
use IndexPilot\Analysis\Report;
use IndexPilot\Analysis\Result;
use IndexPilot\Game\MaterialType;

/**
 * Переводит замечания Readability::issues() в понятные фразы с числами. Группа «Читаемость» оценивается отдельно:
 * её замечания идут без баллов и оценку SEO не меняют. У колод текста нет — группа к ним не применяется.
 */
final class ReadabilityChecks implements CheckGroup {

	/**
	 * Первый абзац длиннее этого числа слов считается затянутым вступлением — тот же порог, что в Readability::issues().
	 */
	private const INTRO_WORDS = 80;

	/**
	 * Выполняет проверки.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $matcher );

		$measure = $facts->readability;

		if ( MaterialType::DECK === $paper->material || 0 === (int) ( $measure['words'] ?? 0 ) ) {
			return array();
		}

		$results = array();

		foreach ( Readability::issues( $measure ) as $issue ) {
			$results[] = new Result( 'readability_' . $issue, Report::READABILITY, Result::OK, 0, self::message( $issue, $measure ) );
		}

		if ( array() === $results ) {
			$results[] = new Result( 'readability', Report::READABILITY, Result::GOOD, 0, sprintf( 'Замечаний к читаемости нет: длинных предложений %d из %d, длинных абзацев нет.', $measure['long_sentences'], $measure['sentences'] ) );
		}

		return $results;
	}

	/**
	 * Фраза для редактора по идентификатору замечания.
	 *
	 * @param string               $issue   Идентификатор из Readability::issues().
	 * @param array<string, mixed> $measure Метрики из Readability::measure().
	 */
	public static function message( string $issue, array $measure ): string {
		switch ( $issue ) {
			case 'long_sentences':
				return sprintf( 'Длинных предложений %d из %d — разбейте те, что длиннее %d слов.', $measure['long_sentences'], $measure['sentences'], Readability::LONG_SENTENCE_WORDS );

			case 'long_paragraphs':
				return sprintf( 'Длинных абзацев %d из %d — разбейте те, что длиннее %d слов: один абзац — одна мысль.', $measure['long_paragraphs'], $measure['paragraphs'], Readability::LONG_PARAGRAPH_WORDS );

			case 'long_words':
				return sprintf( 'Длинных слов %d %% (длиннее %d букв) — замените часть короткими и привычными.', (int) round( $measure['long_word_share'] * 100 ), Readability::LONG_WORD_LETTERS );

			case 'filler':
				return sprintf( 'Канцелярит и лишние обороты (%d): %s — уберите их или скажите проще.', count( $measure['filler'] ), '«' . implode( '», «', $measure['filler'] ) . '»' );

			case 'no_lists':
				return sprintf( 'В тексте %d слов и ни одного списка или таблицы — оформите перечисления (карты, шаги, замены) списком.', $measure['words'] );

			case 'no_subheadings':
				return sprintf( 'В тексте %d слов и ни одного подзаголовка — разбейте его на разделы H2–H3.', $measure['words'] );

			case 'long_intro':
				return sprintf( 'Первый абзац длинный: %d слов — сократите вступление до %d, суть должна быть видна сразу.', $measure['first_paragraph_words'], self::INTRO_WORDS );

			default:
				return $issue;
		}
	}
}
