<?php
/**
 * Определение сайта, на котором работает плагин.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Site;

/**
 * У двух сайтов разные роли и разные поля колод, поэтому шаблоны по умолчанию у них свои.
 */
final class SiteProfile {

	/**
	 * Общий сайт: hs-manacost.ru — все статьи по Hearthstone.
	 */
	public const MANACOST = 'manacost';

	/**
	 * Сайт глубоких разборов: kolodahearthstone.com.
	 */
	public const KOLODA = 'koloda';

	/**
	 * Любой другой сайт, включая локальный.
	 */
	public const GENERIC = 'generic';

	/**
	 * Профиль по имени хоста; тестовые поддомены получают профиль своего сайта.
	 *
	 * @param string $host Имя хоста, например test.hs-manacost.ru.
	 */
	public static function from_host( string $host ): string {
		$host = strtolower( $host );

		if ( str_contains( $host, 'manacost' ) ) {
			return self::MANACOST;
		}

		if ( str_contains( $host, 'koloda' ) ) {
			return self::KOLODA;
		}

		return self::GENERIC;
	}
}
