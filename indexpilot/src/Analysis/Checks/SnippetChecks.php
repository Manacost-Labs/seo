<?php
/**
 * Проверки сниппета.
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
use IndexPilot\Text\TextWidth;

/**
 * Поместятся ли заголовок и описание в выдаче и не слишком ли длинный адрес.
 */
final class SnippetChecks implements CheckGroup {

	private const GROUP = 'snippet';

	/**
	 * Заголовок уже этой ширины выглядит в выдаче куцым.
	 */
	private const TITLE_MIN_PX = 200;

	/**
	 * Границы длины адреса.
	 */
	private const SLUG_MAX_CHARS = 75;
	private const SLUG_MAX_WORDS = 7;

	/**
	 * Выполняет проверки.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		unset( $facts, $matcher );

		$results = array( $this->title_width( $paper ), $this->description_length( $paper ) );

		if ( '' !== $paper->slug ) {
			$words     = count( array_filter( explode( '-', $paper->slug ), 'strlen' ) );
			$results[] = strlen( $paper->slug ) <= self::SLUG_MAX_CHARS && $words <= self::SLUG_MAX_WORDS
				? $this->result( 'slug_length', Result::GOOD, 3, 'Адрес страницы короткий.' )
				: $this->result( 'slug_length', Result::OK, 3, sprintf( 'Адрес страницы длинный: %d слов. У новой записи сократите слаг до главных слов.', $words ) );
		}

		return $results;
	}

	/**
	 * Ширина заголовка.
	 *
	 * @param Paper $paper Данные страницы.
	 */
	private function title_width( Paper $paper ): Result {
		if ( '' === $paper->title ) {
			return $this->result( 'title_width', Result::BAD, 6, 'У страницы нет SEO-заголовка.' );
		}

		$width = $paper->title_px > 0 ? $paper->title_px : TextWidth::estimate( $paper->title );

		if ( $width > $paper->title_max_px ) {
			return $this->result( 'title_width', Result::BAD, 6, sprintf( 'Заголовок не поместится в выдаче: %d px при допустимых %d px. Сократите его.', $width, $paper->title_max_px ) );
		}

		if ( $width < self::TITLE_MIN_PX ) {
			return $this->result( 'title_width', Result::OK, 6, 'Заголовок слишком короткий. Добавьте уточнение: о чём страница и для кого.' );
		}

		return $this->result( 'title_width', Result::GOOD, 6, sprintf( 'Заголовок помещается в выдаче: %d px из %d px.', $width, $paper->title_max_px ) );
	}

	/**
	 * Длина описания.
	 *
	 * @param Paper $paper Данные страницы.
	 */
	private function description_length( Paper $paper ): Result {
		$length = mb_strlen( $paper->description );

		if ( 0 === $length ) {
			return $this->result( 'description_length', Result::BAD, 6, 'У страницы нет метаописания. Напишите 1–2 предложения о том, что читатель найдёт на странице.' );
		}

		if ( 'auto' === $paper->description_source ) {
			return $this->result( 'description_length', Result::OK, 6, 'Описание собрано автоматически из текста. Ручное описание обычно точнее и привлекательнее.' );
		}

		if ( $length < $paper->description_min ) {
			return $this->result( 'description_length', Result::OK, 6, sprintf( 'Описание короткое: %d символов. Рекомендуется %d–%d.', $length, $paper->description_min, $paper->description_max ) );
		}

		if ( $length > $paper->description_max ) {
			return $this->result( 'description_length', Result::OK, 6, sprintf( 'Описание длинное: %d символов, в выдаче оно обрежется. Рекомендуется %d–%d.', $length, $paper->description_min, $paper->description_max ) );
		}

		return $this->result( 'description_length', Result::GOOD, 6, sprintf( 'Длина описания в норме: %d символов.', $length ) );
	}

	/**
	 * Собирает результат проверки.
	 *
	 * @param string $id      Идентификатор проверки.
	 * @param string $status  Статус.
	 * @param int    $max     Наибольшее число баллов.
	 * @param string $message Пояснение.
	 */
	private function result( string $id, string $status, int $max, string $message ): Result {
		return new Result( $id, self::GROUP, $status, $max, $message );
	}
}
