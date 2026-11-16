<?php
/**
 * Готовность статьи к цитированию.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Checks;

use IndexPilot\Analysis\CheckGroup;
use IndexPilot\Analysis\Facts;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Report;
use IndexPilot\Analysis\Result;
use IndexPilot\Frontend\FactBlocks;
use IndexPilot\Game\MaterialType;
use IndexPilot\Text\Cleaner;

/**
 * Что нужно, чтобы поисковик или ассистент мог сослаться на статью: краткий ответ, числа с привязкой ко времени,
 * источники данных, известный автор и свежая правка. Проверки входят в группу «Читаемость» и идут без баллов —
 * оценку SEO они не меняют. К колодам и к страницам без разметки статьи не применяются.
 */
final class CitationChecks implements CheckGroup {

	/**
	 * Запись, которую не правили дольше, считается несвежей: шесть месяцев.
	 */
	public const FRESH_DAYS = 183;

	private const MONTHS = 'январ|феврал|март|апрел|ма[йяе]|июн|июл|август|сентябр|октябр|ноябр|декабр';

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

		// Краткого ответа нет у колод и у страниц без разметки статьи: цитировать там нечего.
		if ( null === $paper->answer || MaterialType::DECK === $paper->material ) {
			return array();
		}

		$stats   = FactBlocks::stats( $paper->content );
		$results = array();

		$results[] = '' !== trim( $paper->answer )
			? $this->result( 'citation_answer', Result::GOOD, 'Цитирование: краткий ответ есть — ассистенту есть что взять в ответ целиком.' )
			: $this->result( 'citation_answer', Result::BAD, 'Цитирование: нет краткого ответа. Напишите его на вкладке «Содержание» — этот абзац цитируют чаще всего.' );

		$results[] = array() !== $stats || self::has_dated_number( $facts->text )
			? $this->result( 'citation_dated_numbers', Result::GOOD, 'Цитирование: в тексте есть числа с датой или номером патча.' )
			: $this->result( 'citation_dated_numbers', Result::OK, 'Цитирование: в тексте нет чисел с привязкой ко времени. Напишите, когда число верно («винрейт 54 % в патче 37.0»), или вставьте факт на вкладке «Содержание».' );

		$results[] = $facts->external_links > 0 || self::has_source( $stats )
			? $this->result( 'citation_sources', Result::GOOD, 'Цитирование: источники данных указаны.' )
			: $this->result( 'citation_sources', Result::OK, 'Цитирование: нет ссылок на источники данных. Сошлитесь на статистику или первоисточник — ссылкой в тексте либо полями source и url у факта.' );

		if ( null !== $paper->author_profile_complete ) {
			$results[] = $paper->author_profile_complete
				? $this->result( 'citation_author', Result::GOOD, 'Цитирование: профиль автора заполнен — видно, чьи это слова.' )
				: $this->result( 'citation_author', Result::OK, 'Цитирование: профиль автора пуст. Материал без известного автора цитируют реже — заполните биографию и ссылки в профиле пользователя.' );
		}

		if ( null !== $paper->days_since_update ) {
			$results[] = $paper->days_since_update <= self::FRESH_DAYS
				? $this->result( 'citation_fresh', Result::GOOD, 'Цитирование: запись обновлялась за последние 6 месяцев.' )
				: $this->result( 'citation_fresh', Result::OK, sprintf( 'Цитирование: запись не обновлялась %d дней — больше 6 месяцев. Проверьте числа и выводы и сохраните запись с правками.', $paper->days_since_update ) );
		}

		return $results;
	}

	/**
	 * Есть ли в тексте число рядом с датой или номером патча: «винрейт 54 % в патче 37.0», «на 15.11.2026 — 312 игр».
	 * Число и привязка должны стоять в одном предложении; сама дата или номер патча числом не считается.
	 *
	 * @param string $text Простой текст или HTML записи.
	 */
	public static function has_dated_number( string $text ): bool {
		$anchors = array(
			// Дата цифрами: 15.11.2026, 2026-11-15.
			'/(?<!\d)\d{1,2}\.\d{1,2}\.\d{2,4}(?!\d)|(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/u',
			// Дата словами: 15 ноября 2026, ноябрь 2026, в ноябре 2026 года.
			'/(?:(?<!\d)\d{1,2}\s+)?(?<!\p{L})(?:' . self::MONTHS . ')\p{L}*(?:\s+\d{4})?(?:\s+(?:года?|г\.))?/iu',
			// Год: 2026 год, в 2026 году, 2026 г.
			'/(?<!\d)(?:19|20)\d{2}\s*(?:года?|году|г\.)/iu',
			// Номер патча или обновления: патч 37.0, в патче 37.0.1, обновление 37.2.
			'/(?<!\p{L})(?:патч|обновлени|верси)\p{L}*\s+\d+(?:\.\d+)*/iu',
		);

		foreach ( preg_split( '/(?<=[.!?…])\s+|\n+/u', Cleaner::to_plain_text( $text, false ) ) as $sentence ) {
			$rest = (string) preg_replace( $anchors, ' ', $sentence, -1, $found );

			if ( $found > 0 && 1 === preg_match( '/\d/', $rest ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Указан ли источник хотя бы у одного факта: название или адрес.
	 *
	 * @param list<array<string, string>> $stats Факты из FactBlocks::stats().
	 */
	private static function has_source( array $stats ): bool {
		foreach ( $stats as $stat ) {
			if ( '' !== trim( (string) ( $stat['source'] ?? '' ) ) || '' !== trim( (string) ( $stat['url'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Собирает результат проверки: без баллов, в группе «Читаемость».
	 *
	 * @param string $id      Идентификатор проверки.
	 * @param string $status  Статус.
	 * @param string $message Пояснение.
	 */
	private function result( string $id, string $status, string $message ): Result {
		return new Result( $id, Report::READABILITY, $status, 0, $message );
	}
}
