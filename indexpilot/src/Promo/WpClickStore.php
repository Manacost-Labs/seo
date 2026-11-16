<?php
/**
 * Счётчик переходов в базе WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Хранит переходы в таблице indexpilot_promo_clicks: одна строка на площадку, запись, правило, вариант и день.
 */
final class WpClickStore implements ClickStore {

	public const DB_OPTION  = 'indexpilot_promo_db';
	public const DB_VERSION = '1';

	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Имя таблицы.
	 */
	public function table(): string {
		return $this->db->prefix . 'indexpilot_promo_clicks';
	}

	/**
	 * Создаёт таблицу при первом запуске.
	 *
	 * @return bool Создана ли таблица этим вызовом.
	 */
	public function maybe_install(): bool {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$this->table()} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				day date NOT NULL,
				destination varchar(64) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
				variant smallint(5) unsigned NOT NULL DEFAULT 0,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY click (day,destination,post_id,rule_id,variant)
			) {$this->db->get_charset_collate()};"
		);

		// Версия читается на каждой странице, поэтому лежит в автозагрузке: отдельного запроса к базе не будет.
		update_option( self::DB_OPTION, self::DB_VERSION, true );

		return true;
	}

	/**
	 * Засчитывает один переход.
	 *
	 * @param string $destination Код площадки.
	 * @param int    $post_id     Запись, с которой пришёл читатель.
	 * @param int    $rule_id     Правило блока перехода.
	 * @param int    $variant     Номер варианта текста.
	 * @param string $day         День в виде ГГГГ-ММ-ДД.
	 */
	public function hit( string $destination, int $post_id, int $rule_id, int $variant, string $day ): void {
		if ( $this->increment( $destination, $post_id, $rule_id, $variant, $day ) ) {
			return;
		}

		// Строки за этот день ещё нет. Если её в тот же миг создал соседний запрос, вставка не пройдёт
		// по уникальному ключу — тогда счётчик увеличивается повторным обновлением.
		$suppress = $this->db->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- собственная таблица плагина.
		$inserted = $this->db->insert(
			$this->table(),
			array(
				'day'         => $day,
				'destination' => $destination,
				'post_id'     => $post_id,
				'rule_id'     => $rule_id,
				'variant'     => $variant,
				'hits'        => 1,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d' )
		);

		$this->db->suppress_errors( $suppress );

		if ( false === $inserted ) {
			$this->increment( $destination, $post_id, $rule_id, $variant, $day );
		}
	}

	/**
	 * Строки счётчика за период, обе границы включены.
	 *
	 * @param string $from Первый день, ГГГГ-ММ-ДД.
	 * @param string $to   Последний день, ГГГГ-ММ-ДД.
	 * @return list<array{destination: string, post_id: int, rule_id: int, variant: int, day: string, hits: int}>
	 */
	public function rows( string $from, string $to ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT destination, post_id, rule_id, variant, day, hits FROM %i WHERE day >= %s AND day <= %s ORDER BY id ASC', $this->table(), $from, $to ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'destination' => (string) $row['destination'],
				'post_id'     => (int) $row['post_id'],
				'rule_id'     => (int) $row['rule_id'],
				'variant'     => (int) $row['variant'],
				'day'         => substr( (string) $row['day'], 0, 10 ),
				'hits'        => (int) $row['hits'],
			),
			(array) $rows
		);
	}

	/**
	 * Увеличивает счётчик существующей строки.
	 *
	 * @param string $destination Код площадки.
	 * @param int    $post_id     Запись.
	 * @param int    $rule_id     Правило.
	 * @param int    $variant     Вариант текста.
	 * @param string $day         День.
	 * @return bool Нашлась ли строка.
	 */
	private function increment( string $destination, int $post_id, int $rule_id, int $variant, string $day ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- счётчик в собственной таблице.
		$updated = $this->db->query( $this->db->prepare( 'UPDATE %i SET hits = hits + 1 WHERE day = %s AND destination = %s AND post_id = %d AND rule_id = %d AND variant = %d', $this->table(), $day, $destination, $post_id, $rule_id, $variant ) );

		return is_int( $updated ) && $updated > 0;
	}
}
