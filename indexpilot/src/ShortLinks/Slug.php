<?php
/**
 * Коды коротких ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\ShortLinks;

/**
 * Код — это часть короткого адреса после префикса: /go/КОД.
 *
 * У каждой записи код есть сразу и нигде не хранится: это буква «p» и номер записи в компактной записи
 * (цифры и латинские буквы). Свои ссылки получают заданный вручную или случайный код.
 */
final class Slug {

	private const RANDOM_LENGTH = 6;

	/**
	 * Код записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function for_post( int $post_id ): string {
		return 'p' . base_convert( (string) max( 0, $post_id ), 10, 36 );
	}

	/**
	 * ID записи по её коду; 0 — это не код записи.
	 *
	 * @param string $slug Код.
	 */
	public static function post_id( string $slug ): int {
		if ( 1 !== preg_match( '/^p([0-9a-z]{1,10})$/', $slug, $matches ) ) {
			return 0;
		}

		return (int) base_convert( $matches[1], 36, 10 );
	}

	/**
	 * Код в едином виде: нижний регистр, без пробелов по краям.
	 *
	 * @param string $slug Код, как его ввели.
	 */
	public static function normalize( string $slug ): string {
		return strtolower( trim( $slug ) );
	}

	/**
	 * Подходит ли код для своей ссылки: латиница, цифры, дефис и подчёркивание, от 2 до 64 символов.
	 *
	 * @param string $slug Код в едином виде.
	 */
	public static function valid( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{1,63}$/', $slug );
	}

	/**
	 * Случайный код.
	 */
	public static function random(): string {
		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
		$slug     = '';

		for ( $i = 0; $i < self::RANDOM_LENGTH; $i++ ) {
			$slug .= $alphabet[ random_int( 0, 35 ) ];
		}

		return $slug;
	}
}
