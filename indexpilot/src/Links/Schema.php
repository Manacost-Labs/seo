<?php
/**
 * Таблица графа ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Создаёт и обновляет таблицу ссылок. Версия структуры хранится в опции: таблица пересоздаётся
 * только когда структура в коде изменилась, а не при каждой загрузке.
 */
final class Schema {

	public const OPTION  = 'indexpilot_db_version';
	public const VERSION = '1';

	/**
	 * Имя таблицы.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public static function table( \wpdb $db ): string {
		return $db->prefix . 'indexpilot_links';
	}

	/**
	 * Создаёт таблицу, если её нет или структура устарела.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public static function maybe_install( \wpdb $db ): void {
		if ( self::VERSION === (string) get_option( self::OPTION, '' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table( $db );
		$charset = $db->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(10) NOT NULL DEFAULT 'internal',
				nofollow tinyint(1) NOT NULL DEFAULT 0,
				url text NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY target_post_id (target_post_id),
				KEY type (type)
			) {$charset};"
		);

		// С автозагрузкой: версия читается на каждой странице и не должна стоить отдельного запроса.
		update_option( self::OPTION, self::VERSION, true );
	}
}
