<?php
/**
 * Обрезка текста для метаописания.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Text;

/**
 * Режет текст по границе предложения, а если её нет — по границе слова.
 */
final class Truncator {

	/**
	 * Знаки, которыми заканчивается предложение.
	 */
	private const SENTENCE_ENDS = array( '.', '!', '?', '…' );

	/**
	 * Обрезает текст до заданной длины.
	 *
	 * @param string $text Простой текст без разметки.
	 * @param int    $max  Наибольшая длина результата в символах.
	 * @param int    $min  Наименьшая длина, при которой обрезка по концу предложения считается удачной.
	 */
	public static function by_sentence( string $text, int $max = 155, int $min = 70 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$chars = mb_str_split( $text );

		// Ищем с конца допустимого отрезка последний знак конца предложения, за которым идёт пробел.
		for ( $i = $max - 1; $i >= $min - 1; --$i ) {
			if ( in_array( $chars[ $i ], self::SENTENCE_ENDS, true ) && ' ' === ( $chars[ $i + 1 ] ?? ' ' ) ) {
				return implode( '', array_slice( $chars, 0, $i + 1 ) );
			}
		}

		// Предложение не помещается: режем по пробелу и ставим многоточие (один символ из лимита).
		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " ,;:—–-\u{00AB}(" ) . '…';
	}
}
