<?php
/**
 * Идентификаторы узлов разметки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

/**
 * Один и тот же объект на всех страницах сайта получает один и тот же @id —
 * так поисковик понимает, что сайт, организация и автор везде одни и те же.
 */
final class IdFactory {

	/**
	 * Сайт.
	 *
	 * @param string $home Адрес главной страницы.
	 */
	public static function website( string $home ): string {
		return self::base( $home ) . '#website';
	}

	/**
	 * Организация — владелец сайта.
	 *
	 * @param string $home Адрес главной страницы.
	 */
	public static function organization( string $home ): string {
		return self::base( $home ) . '#organization';
	}

	/**
	 * Логотип организации.
	 *
	 * @param string $home Адрес главной страницы.
	 */
	public static function logo( string $home ): string {
		return self::base( $home ) . '#logo';
	}

	/**
	 * Автор: идентификатор строится от адреса его страницы.
	 *
	 * @param string $home   Адрес главной страницы.
	 * @param string $author Адрес страницы автора или его имя.
	 */
	public static function person( string $home, string $author ): string {
		return self::base( $home ) . '#/schema/person/' . md5( $author );
	}

	/**
	 * Игрок или стример без учётной записи на сайте: один и тот же узел в разметке колоды и на странице игрока.
	 *
	 * @param string $home Адрес главной страницы.
	 * @param string $name Имя игрока.
	 */
	public static function player( string $home, string $name ): string {
		return self::person( $home, 'player:' . mb_strtolower( trim( $name ) ) );
	}

	/**
	 * Часть страницы: webpage, breadcrumb, article, primaryimage и другие.
	 *
	 * @param string $url  Адрес страницы.
	 * @param string $part Название части.
	 */
	public static function part( string $url, string $part ): string {
		return $url . '#' . $part;
	}

	/**
	 * Адрес главной страницы с завершающим слэшем.
	 *
	 * @param string $home Адрес главной страницы.
	 */
	private static function base( string $home ): string {
		return rtrim( $home, '/' ) . '/';
	}
}
