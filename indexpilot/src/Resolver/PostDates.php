<?php
/**
 * Дата изменения записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Единственное место, где плагин узнаёт время изменения записи: теги article:modified_time и og:updated_time,
 * dateModified в разметке, подпись «Обновлено» и переменная %modified% берут его отсюда и потому совпадают.
 *
 * Время изменения не может быть раньше времени публикации. У записи, поставленной в план, WordPress хранит
 * время последней правки — до выхода записи; в этом случае временем изменения считается время публикации.
 */
final class PostDates {

	/**
	 * Время изменения, не раньше времени публикации.
	 *
	 * @param int $published Время публикации, метка Unix; 0 — неизвестно.
	 * @param int $modified  Время последней правки, метка Unix.
	 */
	public static function effective( int $published, int $modified ): int {
		return max( $published, $modified );
	}

	/**
	 * Время изменения записи в заданном формате.
	 *
	 * @param string       $format    Формат даты PHP.
	 * @param bool         $gmt       По Гринвичу (true) или по часам сайта (false).
	 * @param int|\WP_Post $post      Запись или её ID.
	 * @param bool         $translate Перевести названия месяцев и дней недели.
	 */
	public static function modified( string $format, bool $gmt, $post, bool $translate = false ): string {
		$published = (int) get_post_time( 'U', true, $post );
		$modified  = (int) get_post_modified_time( 'U', true, $post );

		return self::effective( $published, $modified ) === $modified
			? (string) get_post_modified_time( $format, $gmt, $post, $translate )
			: (string) get_post_time( $format, $gmt, $post, $translate );
	}
}
