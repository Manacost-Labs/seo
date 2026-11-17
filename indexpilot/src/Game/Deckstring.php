<?php
/**
 * Разбор кода колоды Hearthstone.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Код колоды — это base64 от последовательности чисел. В её начале записаны режим игры и герой,
 * по которому определяется класс. Этого достаточно; список карт здесь не разбирается.
 */
final class Deckstring {

	/**
	 * Режимы: номер в коде колоды => название.
	 */
	private const MODES = array(
		1 => 'Вольный',
		2 => 'Стандартный',
		3 => 'Классика',
		4 => 'Твист',
	);

	/**
	 * Режим и герой из кода колоды; null — строка не является кодом колоды.
	 *
	 * @param string $code Код колоды.
	 * @return array{format: int, hero: int}|null
	 */
	public static function decode( string $code ): ?array {
		$code = trim( $code );

		if ( '' === $code || 1 !== preg_match( '#^[A-Za-z0-9+/]+=*$#', $code ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- так устроен формат кода колоды Hearthstone.
		$bytes = base64_decode( $code, false );

		if ( false === $bytes || strlen( $bytes ) < 5 ) {
			return null;
		}

		$offset   = 0;
		$reserved = self::varint( $bytes, $offset );
		$version  = self::varint( $bytes, $offset );
		$format   = self::varint( $bytes, $offset );
		$heroes   = self::varint( $bytes, $offset );
		$hero     = self::varint( $bytes, $offset );

		if ( 0 !== $reserved || 1 !== $version || ! isset( self::MODES[ $format ] ) || 1 !== $heroes || null === $hero ) {
			return null;
		}

		return array(
			'format' => $format,
			'hero'   => $hero,
		);
	}

	/**
	 * Класс по номеру героя; пустая строка для неизвестного героя.
	 *
	 * @param int $hero Номер карты героя.
	 */
	public static function class_name( int $hero ): string {
		return implode( ' / ', array_map( static fn( string $slug ): string => Lexicon::NAMES[ $slug ], self::class_slugs( $hero ) ) );
	}

	/**
	 * Все классы героя, включая героев Потасовки.
	 *
	 * @param int $hero Номер карты героя.
	 * @return list<string>
	 */
	public static function class_slugs( int $hero ): array {
		return isset( HeroCatalog::CLASSES[ $hero ] ) ? explode( ' ', HeroCatalog::CLASSES[ $hero ] ) : array();
	}

	/**
	 * Название режима по его номеру.
	 *
	 * @param int $format Номер режима.
	 */
	public static function mode_name( int $format ): string {
		return self::MODES[ $format ] ?? '';
	}

	/**
	 * Читает число переменной длины и сдвигает позицию; null — данные кончились.
	 *
	 * @param string $bytes  Данные.
	 * @param int    $offset Позиция чтения.
	 */
	private static function varint( string $bytes, int &$offset ): ?int {
		$value  = 0;
		$shift  = 0;
		$length = strlen( $bytes );

		while ( $offset < $length && $shift < 35 ) {
			$byte   = ord( $bytes[ $offset ] );
			$value |= ( $byte & 0x7F ) << $shift;
			++$offset;

			if ( 0 === ( $byte & 0x80 ) ) {
				return $value;
			}

			$shift += 7;
		}

		return null;
	}
}
