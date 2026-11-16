<?php
/**
 * Оценка ширины текста в пикселях.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Text;

/**
 * Приближённая ширина строки заголовка в выдаче (шрифт Arial, 20 px).
 *
 * Точную ширину меряет браузер в панели редактора и присылает на сервер; эта оценка нужна там,
 * где браузера нет: при импорте, в WP-CLI и в массовых проверках.
 */
final class TextWidth {

	/**
	 * Ширина символов по группам, px.
	 */
	private const NARROW     = 5.6;
	private const LOWER      = 10.6;
	private const UPPER      = 13.6;
	private const WIDE       = 16.0;
	private const DIGIT      = 11.1;
	private const DASH       = 20.0;
	private const NARROW_SET = " .,:;!|'`ijltfrI()[]";
	private const WIDE_SET   = 'mwMWжшщмыюЖШЩМЫЮ@%';
	private const NARROW_CYR = 'гт';

	/**
	 * Оценка ширины строки в пикселях.
	 *
	 * @param string $text Строка.
	 */
	public static function estimate( string $text ): int {
		$width = 0.0;

		foreach ( mb_str_split( $text ) as $char ) {
			if ( '—' === $char || '–' === $char ) {
				$width += self::DASH;
			} elseif ( false !== mb_strpos( self::NARROW_SET, $char ) ) {
				$width += self::NARROW;
			} elseif ( false !== mb_strpos( self::WIDE_SET, $char ) ) {
				$width += self::WIDE;
			} elseif ( false !== mb_strpos( self::NARROW_CYR, $char ) ) {
				$width += 8.6;
			} elseif ( preg_match( '/\d/', $char ) ) {
				$width += self::DIGIT;
			} elseif ( mb_strtoupper( $char ) === $char && mb_strtolower( $char ) !== $char ) {
				$width += self::UPPER;
			} else {
				$width += self::LOWER;
			}
		}

		return (int) round( $width );
	}
}
