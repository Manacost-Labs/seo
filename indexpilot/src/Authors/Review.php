<?php
/**
 * Отметка «Проверил» и её версия.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Authors;

/**
 * Второй автор подтверждает, что гайд актуален. Отметка хранит отпечаток проверенной версии: состав колоды
 * (коды колод) и основные выводы (краткий ответ и раздел «Выводы» или последний абзац). Если они изменились,
 * отметка перестаёт действовать — «нужна повторная проверка». Опечатка в середине текста отметку не снимает.
 * Не зависит от WordPress.
 */
final class Review {

	public const REVIEWER = '_indexpilot_reviewed_by';
	public const VERSION  = '_indexpilot_reviewed_version';
	public const DATE     = '_indexpilot_reviewed_at';

	public const NONE  = 'none';
	public const VALID = 'valid';
	public const STALE = 'stale';

	/**
	 * Отпечаток того, что подтверждает проверяющий.
	 *
	 * @param string       $content    HTML текста записи.
	 * @param list<string> $deck_codes Коды колод из полей записи.
	 * @param string       $answer     Краткий ответ.
	 */
	public static function fingerprint( string $content, array $deck_codes = array(), string $answer = '' ): string {
		preg_match_all( '#\bAA[A-Za-z0-9+/]{18,}={0,2}#', $content, $found );

		$codes = array_values( array_unique( array_filter( array_map( 'trim', array_merge( $deck_codes, $found[0] ) ), 'strlen' ) ) );
		sort( $codes );

		return md5( implode( '|', $codes ) . "\n" . self::plain( $answer ) . "\n" . self::conclusion( $content ) );
	}

	/**
	 * Состояние отметки.
	 *
	 * @param int    $reviewer ID проверяющего; 0 — отметки нет.
	 * @param string $stored   Отпечаток проверенной версии.
	 * @param string $current  Отпечаток текущей версии.
	 */
	public static function status( int $reviewer, string $stored, string $current ): string {
		if ( $reviewer <= 0 ) {
			return self::NONE;
		}

		return '' !== $stored && hash_equals( $stored, $current ) ? self::VALID : self::STALE;
	}

	/**
	 * Основные выводы: раздел под заголовком «Вывод», «Итог», «Заключение», иначе последний абзац.
	 *
	 * @param string $content HTML текста записи.
	 */
	public static function conclusion( string $content ): string {
		$parts = preg_split( '#(<h[2-6]\b[^>]*>.*?</h[2-6]>)#isu', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$parts = is_array( $parts ) ? $parts : array( $content );

		foreach ( $parts as $index => $part ) {
			if ( 1 === preg_match( '#^<h[2-6]#i', $part ) && 1 === preg_match( '/вывод|итог|заключени|резюме|verdict|conclusion|summary/iu', self::plain( $part ) ) ) {
				return self::plain( (string) ( $parts[ $index + 1 ] ?? '' ) );
			}
		}

		preg_match_all( '#<p\b[^>]*>(.*?)</p>#isu', $content, $paragraphs );

		if ( array() !== $paragraphs[1] ) {
			return self::plain( (string) end( $paragraphs[1] ) );
		}

		$lines = preg_split( '/\n\s*\n/u', trim( $content ) );

		return self::plain( is_array( $lines ) ? (string) end( $lines ) : '' );
	}

	/**
	 * Текст без разметки, лишних пробелов и регистра.
	 *
	 * @param string $html HTML.
	 */
	private static function plain( string $html ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress.
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
	}
}
