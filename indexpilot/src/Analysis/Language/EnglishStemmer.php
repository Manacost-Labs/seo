<?php
/**
 * Упрощённый стеммер английского языка.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Language;

/**
 * Снимает самые частые окончания: множественное число, -ing, -ed.
 *
 * Этого достаточно для названий колод и терминов игры («decks» → «deck», «hunters» → «hunter»).
 * Полный алгоритм Портера здесь не нужен: английские слова на сайтах — в основном имена собственные.
 */
final class EnglishStemmer implements Stemmer {

	/**
	 * Основа слова.
	 *
	 * @param string $word Слово.
	 */
	public function stem( string $word ): string {
		$word   = strtolower( $word );
		$length = strlen( $word );

		if ( $length <= 3 ) {
			return $word;
		}

		if ( str_ends_with( $word, 'ies' ) && $length > 4 ) {
			return substr( $word, 0, -3 ) . 'y';
		}

		if ( str_ends_with( $word, 'sses' ) ) {
			return substr( $word, 0, -2 );
		}

		if ( str_ends_with( $word, 'ing' ) && $length > 5 ) {
			return substr( $word, 0, -3 );
		}

		if ( str_ends_with( $word, 'ed' ) && $length > 4 ) {
			return substr( $word, 0, -2 );
		}

		if ( str_ends_with( $word, 's' ) && ! preg_match( '/(ss|us|is)$/', $word ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}
}
