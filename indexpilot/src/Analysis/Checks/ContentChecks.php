<?php
/**
 * Проверки текста записи.
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
use IndexPilot\Sitemap\EntryPolicy;

/**
 * Объём текста, ссылки, картинки и структура заголовков, а также два сигнала для поисковиков и соцсетей:
 * картинка для карточки и согласованность noindex с каноническим адресом.
 */
final class ContentChecks implements CheckGroup {

	private const GROUP = 'content';

	/**
	 * Для текста короче этого ссылки и картинки не оцениваются: это заметка или страница без текста.
	 */
	private const MIN_WORDS_FOR_STRUCTURE = 150;

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

		$results = array();

		if ( $paper->min_words > 0 ) {
			if ( $facts->word_count >= $paper->min_words ) {
				$results[] = $this->result( 'content_length', Result::GOOD, 6, sprintf( 'Объём текста достаточный: %d слов.', $facts->word_count ) );
			} elseif ( $facts->word_count * 2 >= $paper->min_words ) {
				$results[] = $this->result( 'content_length', Result::OK, 6, sprintf( 'Текст короче рекомендуемого: %d слов из %d.', $facts->word_count, $paper->min_words ) );
			} else {
				$results[] = $this->result( 'content_length', Result::BAD, 6, sprintf( 'Текста мало: %d слов при рекомендуемых %d. Раскройте тему подробнее.', $facts->word_count, $paper->min_words ) );
			}
		}

		if ( $facts->word_count >= self::MIN_WORDS_FOR_STRUCTURE ) {
			$results[] = $facts->internal_links > 0
				? $this->result( 'internal_links', Result::GOOD, 5, sprintf( 'В тексте есть внутренние ссылки: %d.', $facts->internal_links ) )
				: $this->result( 'internal_links', Result::BAD, 5, 'В тексте нет ссылок на другие страницы сайта. Добавьте ссылки на связанные гайды и колоды.' );

			$results[] = $facts->external_links > 0
				? $this->result( 'external_links', Result::GOOD, 2, sprintf( 'В тексте есть ссылки на источники: %d.', $facts->external_links ) )
				: $this->result( 'external_links', Result::OK, 2, 'В тексте нет ссылок на источники. Сошлитесь на статистику или первоисточник, если они есть.' );

			$results[] = array() !== $facts->image_alts
				? $this->result( 'images', Result::GOOD, 3, sprintf( 'В тексте есть картинки: %d.', count( $facts->image_alts ) ) )
				: $this->result( 'images', Result::OK, 3, 'В тексте нет картинок.' );
		}

		if ( null !== $paper->redirected_links ) {
			$results[] = array() === $paper->redirected_links
				? $this->result( 'redirected_links', Result::GOOD, 3, 'Ссылок на адреса с редиректом в тексте нет.' )
				: $this->result( 'redirected_links', Result::BAD, 3, $this->redirected_links_message( $paper->redirected_links ) );
		}

		// Без картинки карточка ссылки в Telegram и ВКонтакте выходит пустой, а у разметки статьи нет изображения.
		if ( false === $paper->has_social_image ) {
			$results[] = $this->result( 'social_image', Result::OK, 2, 'У записи нет картинки для карточки в соцсетях: задайте миниатюру записи, картинку на вкладке «Соцсети» или общую картинку по умолчанию в настройках. Лучший размер — 1200 × 630.' );
		}

		if ( self::conflicting_canonical( $paper ) ) {
			$results[] = $this->result( 'noindex_canonical', Result::BAD, 3, sprintf( 'Страница закрыта от индексации (noindex) и одновременно указывает каноническим адресом на другую страницу: %s. Эти сигналы исключают друг друга — оставьте что-то одно на вкладке «Дополнительно».', $paper->canonical ) );
		}

		if ( '' !== $paper->redirect_target ) {
			$results[] = $this->result( 'redirected_page', Result::BAD, 6, sprintf( 'С адреса этой страницы стоит редирект на %s — читатели и поисковики её не видят. Уберите редирект или снимите страницу с публикации.', $paper->redirect_target ) );
		}

		if ( null !== $paper->author_profile_complete ) {
			$results[] = $paper->author_profile_complete
				? $this->result( 'author_profile', Result::GOOD, 2, 'У автора заполнен профиль: поисковик видит, кто написал материал.' )
				: $this->result( 'author_profile', Result::OK, 2, 'У автора не заполнен профиль. Добавьте в профиле пользователя биографию и ссылки на Twitch, YouTube или Telegram: так видно, что материал написал реальный игрок.' );
		}

		$results[] = 0 === $facts->h1_count
			? $this->result( 'single_h1', Result::GOOD, 3, 'В тексте нет лишних заголовков H1.' )
			: $this->result( 'single_h1', Result::BAD, 3, sprintf( 'В тексте есть заголовки H1: %d. H1 — это название записи, внутри текста используйте H2 и H3.', $facts->h1_count ) );

		return $results;
	}

	/**
	 * Закрыта ли страница от индексации и при этом ссылается каноническим адресом на другую страницу.
	 * Адреса сравниваются без учёта протокола, регистра хоста и слэша в конце.
	 *
	 * @param Paper $paper Данные страницы.
	 */
	public static function conflicting_canonical( Paper $paper ): bool {
		return $paper->noindex && '' !== trim( $paper->url ) && '' !== trim( $paper->canonical ) && ! EntryPolicy::same_address( $paper->url, $paper->canonical );
	}

	/**
	 * Сообщение о ссылках на адреса с редиректом: первые три с адресами для замены.
	 *
	 * @param list<array{href: string, final: string}> $links Ссылки.
	 */
	private function redirected_links_message( array $links ): string {
		$examples = array();

		foreach ( array_slice( $links, 0, 3 ) as $link ) {
			$examples[] = $link['href'] . ' → ' . $link['final'];
		}

		return sprintf( 'Ссылки ведут на адреса с редиректом: %d. Замените их конечными адресами: %s.', count( $links ), implode( '; ', $examples ) );
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
