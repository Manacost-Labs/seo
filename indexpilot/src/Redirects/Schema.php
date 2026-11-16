<?php
/**
 * Таблицы своих редиректов и журнала 404.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Создаёт и обновляет таблицы indexpilot_redirects и indexpilot_404.
 */
final class Schema {

	public const OPTION  = 'indexpilot_redirects_db_version';
	public const VERSION = '1';

	/**
	 * Таблица правил.
	 *
	 * @param \wpdb $db База данных.
	 */
	public static function redirects( \wpdb $db ): string {
		return $db->prefix . 'indexpilot_redirects';
	}

	/**
	 * Таблица журнала 404.
	 *
	 * @param \wpdb $db База данных.
	 */
	public static function not_found( \wpdb $db ): string {
		return $db->prefix . 'indexpilot_404';
	}

	/**
	 * Готовы ли таблицы. Опция версии загружается вместе с остальными, запроса к базе нет.
	 */
	public static function installed(): bool {
		return self::VERSION === (string) get_option( self::OPTION, '' );
	}

	/**
	 * Создаёт таблицы, если их версия устарела.
	 *
	 * @param \wpdb $db База данных.
	 */
	public static function maybe_install( \wpdb $db ): void {
		if ( self::installed() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $db->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::redirects( $db ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source text NOT NULL,
				source_key char(32) NOT NULL DEFAULT '',
				regex tinyint(1) NOT NULL DEFAULT 0,
				target text NOT NULL,
				code smallint(5) unsigned NOT NULL DEFAULT 301,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				last_hit datetime DEFAULT NULL,
				note varchar(100) NOT NULL DEFAULT '',
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY source_key (source_key)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::not_found( $db ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url varchar(255) NOT NULL,
				url_hash char(32) NOT NULL,
				hits bigint(20) unsigned NOT NULL DEFAULT 1,
				referrer varchar(255) NOT NULL DEFAULT '',
				first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (url_hash),
				KEY last_seen (last_seen),
				KEY hits (hits)
			) {$charset};"
		);

		// Индекс поиска правил загружается вместе с остальными опциями: обычная страница не стоит запроса к базе.
		if ( false === get_option( WpStore::INDEX_OPTION ) ) {
			update_option( WpStore::INDEX_OPTION, Matcher::index( array() ), true );
		}

		// С автозагрузкой: версия читается на каждой странице и не должна стоить отдельного запроса.
		update_option( self::OPTION, self::VERSION, true );
	}
}
