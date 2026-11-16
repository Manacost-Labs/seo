<?php
/**
 * Результат одной проверки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

/**
 * Что проверили, насколько хорошо и что исправить.
 */
final class Result {

	public const GOOD = 'good';
	public const OK   = 'ok';
	public const BAD  = 'bad';

	/**
	 * Заработанные баллы.
	 *
	 * @var int
	 */
	public readonly int $score;

	/**
	 * Конструктор.
	 *
	 * @param string $id      Идентификатор проверки.
	 * @param string $group   Группа: keyword, snippet, content или readability.
	 * @param string $status  Статус: good, ok или bad.
	 * @param int    $max     Наибольшее число баллов за проверку; 0 — замечание без баллов.
	 * @param string $message Пояснение для редактора.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $group,
		public readonly string $status,
		public readonly int $max,
		public readonly string $message
	) {
		// «Хорошо» — все баллы, «приемлемо» — половина, «плохо» — ноль.
		$this->score = self::GOOD === $status ? $max : ( self::OK === $status ? intdiv( $max, 2 ) : 0 );
	}

	/**
	 * Массив для REST и хранения.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
