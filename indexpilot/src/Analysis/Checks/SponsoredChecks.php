<?php
/**
 * Рекламные ссылки в оплаченной статье.
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
use IndexPilot\Text\Cleaner;

/**
 * Когда у записи отмечено оплаченное размещение, ссылки на рекламодателя должны быть помечены rel="sponsored",
 * а в тексте должна стоять видимая пометка о рекламе (книга, с. 329–330). Ссылки на свои площадки и свои сайты
 * рекламой не считаются. Без отметки проверка ничего не требует. Замечания без баллов.
 */
final class SponsoredChecks implements CheckGroup {

	private const GROUP = 'content';

	/**
	 * Сколько ссылок перечислять в замечании.
	 */
	private const EXAMPLES = 3;

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

		if ( ! $paper->sponsored ) {
			return array();
		}

		$unmarked = self::unmarked_links( $paper->content, array_merge( array( $paper->site_host ), $paper->own_hosts ) );
		$results  = array();

		$results[] = array() === $unmarked
			? new Result( 'sponsored_links', self::GROUP, Result::GOOD, 0, 'Ссылки на рекламодателя помечены rel="sponsored".' )
			: new Result( 'sponsored_links', self::GROUP, Result::BAD, 0, sprintf( 'В статье с оплаченным размещением ссылки на чужие сайты без пометки rel="sponsored": %d. Поисковики требуют помечать оплаченные ссылки — добавьте её в ссылку или уберите отметку размещения: %s.', count( $unmarked ), implode( '; ', array_slice( $unmarked, 0, self::EXAMPLES ) ) ) );

		$results[] = self::has_label( $paper->content )
			? new Result( 'sponsored_label', self::GROUP, Result::GOOD, 0, 'В тексте есть пометка о рекламе.' )
			: new Result( 'sponsored_label', self::GROUP, Result::BAD, 0, 'В статье с оплаченным размещением нет видимой пометки о рекламе. Напишите в тексте «Реклама», «На правах рекламы» или «Партнёрский материал» и, если есть, номер erid.' );

		return $results;
	}

	/**
	 * Внешние ссылки без rel="sponsored". Ссылки на свой сайт и на перечисленные хосты (с поддоменами) не учитываются.
	 *
	 * @param string       $html      HTML текста.
	 * @param list<string> $own_hosts Свои хосты.
	 * @return list<string> Адреса ссылок.
	 */
	public static function unmarked_links( string $html, array $own_hosts ): array {
		if ( ! preg_match_all( '#<a\b([^>]*)>#isu', $html, $tags ) ) {
			return array();
		}

		$own = array();
		foreach ( $own_hosts as $host ) {
			$host = self::bare_host( (string) $host );
			if ( '' !== $host ) {
				$own[] = $host;
			}
		}

		$found = array();

		foreach ( $tags[1] as $attributes ) {
			if ( ! preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#isu', $attributes, $href ) ) {
				continue;
			}

			$address = trim( html_entity_decode( $href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$host    = self::bare_host( (string) parse_url( $address, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress.

			if ( '' === $host || ! preg_match( '#^(?:https?:)?//#i', $address ) || self::is_own( $host, $own ) ) {
				continue;
			}

			$rel = preg_match( '#\brel\s*=\s*(["\'])(.*?)\1#isu', $attributes, $match ) ? preg_split( '/\s+/', strtolower( trim( $match[2] ) ) ) : array();

			if ( ! in_array( 'sponsored', (array) $rel, true ) && ! in_array( $address, $found, true ) ) {
				$found[] = $address;
			}
		}

		return $found;
	}

	/**
	 * Есть ли в видимом тексте пометка о рекламе: «Реклама», «На правах рекламы», «Партнёрский материал», «erid».
	 *
	 * @param string $html HTML текста.
	 */
	public static function has_label( string $html ): bool {
		return 1 === preg_match( '/(?<![\p{L}\p{N}])(?:реклама|на\s+правах\s+рекламы|партн[её]рский\s+материал|erid)(?![\p{L}])/iu', Cleaner::to_plain_text( $html, false ) );
	}

	/**
	 * Хост без «www.» в нижнем регистре.
	 *
	 * @param string $host Хост.
	 */
	private static function bare_host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( trim( $host ) ) );
	}

	/**
	 * Свой ли хост: совпадает со своим или является его поддоменом.
	 *
	 * @param string       $host Хост ссылки.
	 * @param list<string> $own  Свои хосты.
	 */
	private static function is_own( string $host, array $own ): bool {
		foreach ( $own as $candidate ) {
			if ( $host === $candidate || str_ends_with( $host, '.' . $candidate ) ) {
				return true;
			}
		}

		return false;
	}
}
