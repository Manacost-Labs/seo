<?php
/**
 * Проверки основного ключевого слова.
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
 * Где встречается Focus Keyword и не слишком ли часто.
 */
final class KeywordChecks implements CheckGroup {

	private const GROUP = 'keyword';

	/**
	 * Границы плотности ключа в процентах. Выше верхней — переспам, который вредит и в обычном, и в AI-поиске.
	 */
	private const DENSITY_MIN = 0.5;
	private const DENSITY_MAX = 2.5;

	/**
	 * Выполняет проверки.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @return list<Result>
	 */
	public function run( Paper $paper, Facts $facts, KeywordMatcher $matcher ): array {
		$keyword = trim( $paper->keyword );

		if ( '' === $keyword ) {
			return array( $this->result( 'keyword_set', Result::BAD, 6, 'Focus Keyword не задан. Укажите главный запрос, по которому страница должна находиться.' ) );
		}

		$results   = array( $this->result( 'keyword_set', Result::GOOD, 6, sprintf( 'Focus Keyword задан: «%s».', $keyword ) ) );
		$position  = $matcher->position( $paper->title, $keyword );
		$results[] = $position >= 0
			? $this->result( 'keyword_in_title', Result::GOOD, 9, 'Ключ есть в SEO-заголовке.' )
			: $this->result( 'keyword_in_title', Result::BAD, 9, 'Ключа нет в SEO-заголовке. Добавьте его, лучше ближе к началу.' );

		if ( $position >= 0 ) {
			$results[] = $position <= 2
				? $this->result( 'keyword_at_title_start', Result::GOOD, 3, 'Ключ стоит в начале заголовка.' )
				: $this->result( 'keyword_at_title_start', Result::OK, 3, 'Ключ есть в заголовке, но не в начале. Перенесите его ближе к первым словам.' );
		}

		$results[] = '' !== $paper->description && $matcher->contains( $paper->description, $keyword )
			? $this->result( 'keyword_in_description', Result::GOOD, 6, 'Ключ есть в метаописании.' )
			: $this->result( 'keyword_in_description', Result::BAD, 6, 'Ключа нет в метаописании. В выдаче совпавшие слова выделяются жирным — добавьте ключ.' );

		if ( '' !== $paper->slug ) {
			$results[] = $matcher->in_slug( $paper->slug, $keyword )
				? $this->result( 'keyword_in_slug', Result::GOOD, 6, 'Ключ есть в адресе страницы.' )
				: $this->result( 'keyword_in_slug', Result::BAD, 6, 'Ключа нет в адресе страницы. У новой записи поправьте слаг; у опубликованной не меняйте адрес без редиректа.' );
		}

		if ( $facts->word_count > 0 ) {
			$results[] = $matcher->contains( $facts->first_paragraph, $keyword )
				? $this->result( 'keyword_in_first_paragraph', Result::GOOD, 6, 'Ключ есть в первом абзаце.' )
				: $this->result( 'keyword_in_first_paragraph', Result::BAD, 6, 'Ключа нет в первом абзаце. Назовите тему статьи сразу, в первых предложениях.' );
		}

		$results = array_merge( $results, $this->subheadings( $facts, $matcher, $keyword ), $this->image_alt( $facts, $matcher, $keyword ), $this->density( $facts, $matcher, $keyword ) );

		$results[] = array() === $paper->duplicates
			? $this->result( 'keyword_unique', Result::GOOD, 6, 'Этот ключ не используется на других страницах сайта.' )
			: $this->result( 'keyword_unique', Result::BAD, 6, sprintf( 'Тот же ключ стоит на других страницах: %s. Страницы будут конкурировать между собой — разведите ключи или объедините материалы.', implode( '; ', array_slice( $paper->duplicates, 0, 3 ) ) ) );

		if ( null !== $paper->partner_matches ) {
			$results[] = array() === $paper->partner_matches
				? $this->result( 'keyword_cross_site', Result::GOOD, 3, 'На втором сайте этот ключ не используется.' )
				: $this->result( 'keyword_cross_site', Result::OK, 3, sprintf( 'Тот же ключ стоит на втором сайте: %s. Два ваших сайта будут конкурировать по одному запросу — дайте страницам разные ключи.', $this->partner_pages( $paper->partner_matches ) ) );
		}

		return $results;
	}

	/**
	 * Страницы второго сайта для сообщения: название и адрес, не больше трёх.
	 *
	 * @param list<array{title: string, url: string}> $matches Страницы.
	 */
	private function partner_pages( array $matches ): string {
		$pages = array();

		foreach ( array_slice( $matches, 0, 3 ) as $match ) {
			$pages[] = sprintf( '«%s» (%s)', (string) ( $match['title'] ?? '' ), (string) ( $match['url'] ?? '' ) );
		}

		return implode( '; ', $pages );
	}

	/**
	 * Ключ в подзаголовках.
	 *
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @param string         $keyword Ключ.
	 * @return list<Result>
	 */
	private function subheadings( Facts $facts, KeywordMatcher $matcher, string $keyword ): array {
		if ( array() === $facts->subheadings ) {
			// В коротком тексте подзаголовки не обязательны.
			return $facts->word_count < 300 ? array() : array( $this->result( 'keyword_in_subheadings', Result::OK, 3, 'В тексте нет подзаголовков. Разбейте его на разделы H2 и упомяните ключ в одном из них.' ) );
		}

		foreach ( $facts->subheadings as $subheading ) {
			if ( $matcher->contains( $subheading, $keyword ) ) {
				return array( $this->result( 'keyword_in_subheadings', Result::GOOD, 3, 'Ключ есть в подзаголовке.' ) );
			}
		}

		return array( $this->result( 'keyword_in_subheadings', Result::OK, 3, 'Ключа нет ни в одном подзаголовке H2–H4.' ) );
	}

	/**
	 * Ключ в alt картинок.
	 *
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @param string         $keyword Ключ.
	 * @return list<Result>
	 */
	private function image_alt( Facts $facts, KeywordMatcher $matcher, string $keyword ): array {
		if ( array() === $facts->image_alts ) {
			return array();
		}

		foreach ( $facts->image_alts as $alt ) {
			if ( $matcher->contains( $alt, $keyword ) ) {
				return array( $this->result( 'keyword_in_image_alt', Result::GOOD, 3, 'Ключ есть в описании (alt) картинки.' ) );
			}
		}

		return array( $this->result( 'keyword_in_image_alt', Result::OK, 3, 'Ключа нет в описаниях (alt) картинок. Добавьте его к подходящей по смыслу картинке.' ) );
	}

	/**
	 * Плотность ключа.
	 *
	 * @param Facts          $facts   Разобранный текст.
	 * @param KeywordMatcher $matcher Поиск ключа.
	 * @param string         $keyword Ключ.
	 * @return list<Result>
	 */
	private function density( Facts $facts, KeywordMatcher $matcher, string $keyword ): array {
		if ( $facts->word_count < 100 ) {
			return array();
		}

		$count   = $matcher->count( $facts->text, $keyword );
		$density = $count * $matcher->length( $keyword ) / $facts->word_count * 100;
		$label   = number_format( $density, 1, ',', '' );

		if ( 0 === $count ) {
			return array( $this->result( 'keyword_density', Result::BAD, 6, 'Ключ ни разу не встречается в тексте.' ) );
		}

		if ( $density > self::DENSITY_MAX ) {
			return array( $this->result( 'keyword_density', Result::BAD, 6, sprintf( 'Ключ встречается слишком часто: %d раз, плотность %s %%. Это переспам — замените часть упоминаний синонимами.', $count, $label ) ) );
		}

		if ( $density < self::DENSITY_MIN ) {
			return array( $this->result( 'keyword_density', Result::OK, 6, sprintf( 'Ключ встречается редко: %d раз, плотность %s %%. Упомяните его ещё там, где это естественно.', $count, $label ) ) );
		}

		return array( $this->result( 'keyword_density', Result::GOOD, 6, sprintf( 'Ключ встречается %d раз, плотность %s %%.', $count, $label ) ) );
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
