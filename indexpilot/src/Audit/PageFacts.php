<?php
/**
 * Факты о странице для аудита.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Audit;

/**
 * Сводит страницу к набору признаков, по которым аудит ищет проблемы. Не зависит от WordPress.
 */
final class PageFacts {

	/**
	 * Через сколько дней без правок текст считается давно не обновлявшимся.
	 */
	public const STALE_DAYS = 540;

	/**
	 * Признаки страницы.
	 *
	 * @param array<string, mixed> $page Данные: title, description, description_source, indexable, content, modified, min_words, now.
	 * @return array{title_hash: string, description_hash: string, indexable: int, no_description: int, auto_description: int, h1: int, words: int, thin: int, stale: int}
	 */
	public static function build( array $page ): array {
		$description = trim( (string) ( $page['description'] ?? '' ) );
		$source      = (string) ( $page['description_source'] ?? 'none' );
		$content     = (string) ( $page['content'] ?? '' );
		$min_words   = (int) ( $page['min_words'] ?? 0 );
		$now         = (int) ( $page['now'] ?? time() );
		$modified    = (int) ( $page['modified'] ?? $now );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress и проверяется модульными тестами.
		$text  = trim( html_entity_decode( strip_tags( str_replace( '<', ' <', $content ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$words = '' === $text ? 0 : count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );

		return array(
			'title_hash'       => self::hash( (string) ( $page['title'] ?? '' ) ),
			'description_hash' => self::hash( $description ),
			'indexable'        => empty( $page['indexable'] ) ? 0 : 1,
			'no_description'   => '' === $description ? 1 : 0,
			'auto_description' => 'auto' === $source && '' !== $description ? 1 : 0,
			'h1'               => (int) preg_match_all( '#<h1\b#i', $content ),
			'words'            => $words,
			'thin'             => $min_words > 0 && $words < $min_words ? 1 : 0,
			'stale'            => $now - $modified > self::STALE_DAYS * 86400 ? 1 : 0,
		);
	}

	/**
	 * Отпечаток текста для поиска повторов: без учёта регистра и пробелов по краям. У пустого текста отпечатка нет.
	 *
	 * @param string $text Текст.
	 */
	private static function hash( string $text ): string {
		$text = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );

		return '' === $text ? '' : md5( $text );
	}
}
