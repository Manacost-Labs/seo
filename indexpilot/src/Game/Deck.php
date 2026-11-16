<?php
/**
 * Колода Hearthstone.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Данные колоды в едином виде для обоих сайтов. Неизвестное значение — пустая строка или ноль.
 */
final class Deck {

	/**
	 * Конструктор.
	 *
	 * @param int    $id        ID записи колоды.
	 * @param string $title     Название.
	 * @param string $hero_class Класс: Маг, Друид и так далее.
	 * @param string $mode      Режим: Стандартный, Вольный.
	 * @param string $archetype Архетип.
	 * @param int    $dust      Стоимость в пыли; 0 — неизвестна.
	 * @param string $player    Игрок или стример.
	 * @param string $code      Код колоды для копирования в игру.
	 * @param bool   $archived  Колода в архиве.
	 * @param string $group     Ключ группы версий: у версий одной колоды он одинаковый; пусто — версий нет.
	 * @param string $date      Дата публикации в формате базы.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $hero_class,
		public readonly string $mode,
		public readonly string $archetype,
		public readonly int $dust,
		public readonly string $player,
		public readonly string $code,
		public readonly bool $archived,
		public readonly string $group,
		public readonly string $date
	) {
	}

	/**
	 * Ключ группы версий из названия архетипа, класса и режима. Без архетипа группы нет.
	 *
	 * @param string $archetype  Архетип или его ключ.
	 * @param string $class_name Класс.
	 * @param string $mode       Режим.
	 */
	public static function group_key( string $archetype, string $class_name, string $mode ): string {
		$archetype = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $archetype ) ) );

		return '' === $archetype ? '' : $archetype . '|' . mb_strtolower( trim( $class_name ) ) . '|' . mb_strtolower( trim( $mode ) );
	}

	/**
	 * Значение без заглушек, которыми сайты заполняют пустые поля.
	 *
	 * @param string $value Значение поля.
	 */
	public static function clean( string $value ): string {
		$value = trim( $value );

		return in_array( mb_strtolower( $value ), array( 'unknown', 'n/a', 'none', 'null', '-', '—' ), true ) ? '' : $value;
	}
}
