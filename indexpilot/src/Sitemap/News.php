<?php
/**
 * Правила карты новостей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * Google Новости принимают в карте новостей только записи за последние двое суток и не больше 1000 адресов.
 */
final class News {

	public const WINDOW = 48 * 3600;

	public const LIMIT = 1000;

	/**
	 * Время, раньше которого запись уже не новость.
	 *
	 * @param int $now Текущее время.
	 */
	public static function since( int $now ): int {
		return $now - self::WINDOW;
	}

	/**
	 * Записи, вышедшие за последние 48 часов: сначала свежие.
	 *
	 * @param list<array<string, mixed>> $rows  Записи; в каждой поле published — время публикации.
	 * @param int                        $now   Текущее время.
	 * @param int                        $limit Сколько записей оставить.
	 * @return list<array<string, mixed>>
	 */
	public static function recent( array $rows, int $now, int $limit = self::LIMIT ): array {
		$since  = self::since( $now );
		$recent = array();

		foreach ( $rows as $row ) {
			$published = (int) ( $row['published'] ?? 0 );

			if ( $published > $since && $published <= $now ) {
				$recent[] = $row;
			}
		}

		usort( $recent, static fn( array $a, array $b ): int => (int) $b['published'] <=> (int) $a['published'] );

		return array_slice( $recent, 0, max( 0, $limit ) );
	}

	/**
	 * Код языка издания по ISO 639: «ru» из «ru-RU». Для китайского Google ждёт «zh-cn» или «zh-tw».
	 *
	 * @param string $locale Язык сайта: «ru-RU», «en_US».
	 */
	public static function language( string $locale ): string {
		$locale = strtolower( str_replace( '_', '-', trim( $locale ) ) );

		if ( in_array( $locale, array( 'zh-cn', 'zh-tw' ), true ) ) {
			return $locale;
		}

		$code = (string) strtok( $locale, '-' );

		return 1 === preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : 'ru';
	}

	/**
	 * Сколько секунд можно хранить готовый файл: до момента, когда запись перестанет быть новостью.
	 *
	 * @param int $published Время публикации записи.
	 * @param int $now       Текущее время.
	 * @param int $max       Обычный срок хранения файлов карты.
	 */
	public static function ttl( int $published, int $now, int $max ): int {
		return max( 60, min( $max, $published + self::WINDOW - $now ) );
	}
}
