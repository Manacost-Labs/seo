<?php
/**
 * Подписанный параметр замера.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Doctor;

/**
 * Замер включается только запросом с параметром, подписанным ключом сайта. Подпись проверяется без обращения
 * к базе: запрос с чужим или просроченным значением не стоит сайту ничего.
 */
final class Token {

	/**
	 * Сколько секунд действует параметр.
	 */
	public const TTL = 120;

	/**
	 * Выпускает параметр: идентификатор замера, срок действия и подпись.
	 *
	 * @param string $id     Идентификатор замера: латинские буквы и цифры.
	 * @param int    $now    Текущее время.
	 * @param string $secret Ключ сайта.
	 */
	public static function issue( string $id, int $now, string $secret ): string {
		$expires = $now + self::TTL;

		return $id . '.' . $expires . '.' . self::sign( $id, $expires, $secret );
	}

	/**
	 * Проверяет параметр. Возвращает идентификатор замера или null, если подпись неверна или срок вышел.
	 *
	 * @param string $token  Значение параметра.
	 * @param int    $now    Текущее время.
	 * @param string $secret Ключ сайта.
	 */
	public static function verify( string $token, int $now, string $secret ): ?string {
		if ( 1 !== preg_match( '/^([a-z0-9]{8,32})\.(\d{1,12})\.([a-f0-9]{64})$/', $token, $parts ) ) {
			return null;
		}

		$expires = (int) $parts[2];

		if ( $expires < $now || ! hash_equals( self::sign( $parts[1], $expires, $secret ), $parts[3] ) ) {
			return null;
		}

		return $parts[1];
	}

	/**
	 * Подпись идентификатора и срока.
	 *
	 * @param string $id      Идентификатор замера.
	 * @param int    $expires Время, до которого параметр действует.
	 * @param string $secret  Ключ сайта.
	 */
	private static function sign( string $id, int $expires, string $secret ): string {
		return hash_hmac( 'sha256', $id . '.' . $expires, $secret );
	}
}
