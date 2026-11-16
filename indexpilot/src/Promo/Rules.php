<?php
/**
 * Сохранённые правила блоков перехода.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Читает и сохраняет правила. Опция лежит в автозагрузке: на странице записи чтение правил не стоит запроса к базе.
 */
final class Rules {

	/**
	 * Конструктор.
	 *
	 * @param Destinations $destinations Площадки: правило без существующей площадки не действует.
	 */
	public function __construct( private Destinations $destinations ) {
	}

	/**
	 * Правила по порядку: первые подходящие записи и выводятся.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		$raw = (array) get_option( Rule::OPTION, array() );

		return array() === $raw ? array() : Rule::sanitize_all( $raw, $this->codes() );
	}

	/**
	 * Сохраняет правила из формы.
	 *
	 * @param array<int, mixed> $raw Правила в том виде, в каком их прислала форма.
	 */
	public function save( array $raw ): void {
		update_option( Rule::OPTION, Rule::sanitize_all( $raw, $this->codes() ), true );
	}

	/**
	 * Коды существующих площадок.
	 *
	 * @return list<string>
	 */
	private function codes(): array {
		return array_column( $this->destinations->all(), 'code' );
	}
}
