<?php
/**
 * Чтение редиректов плагина Redirection.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Только читает таблицу redirection_items. Редиректами по-прежнему управляет плагин Redirection.
 */
final class RedirectionAdapter {

	/**
	 * Редиректы, прочитанные в этом запросе.
	 *
	 * @var list<Redirect>|null
	 */
	private ?array $redirects = null;

	/**
	 * Есть ли таблица плагина.
	 *
	 * @var bool|null
	 */
	private ?bool $available = null;

	/**
	 * Конструктор.
	 *
	 * @param \wpdb $db База WordPress.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Установлен ли плагин Redirection (есть ли его таблица).
	 */
	public function available(): bool {
		if ( null === $this->available ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чужая таблица, результат запоминается на время запроса.
			$this->available = $this->table() === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->table() ) ) );
		}

		return $this->available;
	}

	/**
	 * Включённые редиректы с одного адреса на другой. Редиректы по регулярным выражениям
	 * и с условиями (вход, роль, заголовки) не учитываются: у них нет одного исходного адреса.
	 *
	 * @return list<Redirect>
	 */
	public function all(): array {
		if ( null !== $this->redirects ) {
			return $this->redirects;
		}

		$this->redirects = array();

		if ( ! $this->available() ) {
			return $this->redirects;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чужая таблица, результат запоминается на время запроса.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, url, action_data, action_code, last_count FROM %i
				WHERE status = 'enabled' AND action_type = 'url' AND match_type = 'url' AND regex = 0
				ORDER BY id ASC",
				$this->table()
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$source = trim( (string) $row['url'] );
			$target = trim( (string) $row['action_data'] );

			if ( '' !== $source && '' !== $target ) {
				$this->redirects[] = new Redirect( (int) $row['id'], $source, $target, (int) $row['action_code'], (int) $row['last_count'] );
			}
		}

		return $this->redirects;
	}

	/**
	 * Сколько включённых редиректов не учтено (регулярные выражения, условия, ответы без перехода).
	 */
	public function skipped(): int {
		if ( ! $this->available() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чужая таблица, разовый подсчёт на странице отчёта.
		$enabled = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'enabled'", $this->table() ) );

		return max( 0, $enabled - count( $this->all() ) );
	}

	/**
	 * Имя таблицы плагина.
	 */
	private function table(): string {
		return $this->db->prefix . 'redirection_items';
	}
}
