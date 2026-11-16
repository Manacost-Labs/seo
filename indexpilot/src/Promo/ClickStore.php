<?php
/**
 * Хранилище счётчика переходов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Считает переходы на площадки по дням. Хранит только число переходов:
 * ни адресов посетителей, ни строк User-Agent, ни адресов источников здесь нет.
 */
interface ClickStore {

	/**
	 * Засчитывает один переход.
	 *
	 * @param string $destination Код площадки.
	 * @param int    $post_id     Запись, с которой пришёл читатель; 0 — запись неизвестна.
	 * @param int    $rule_id     Правило блока перехода; 0 — блок подписки или прямая ссылка.
	 * @param int    $variant     Номер варианта текста.
	 * @param string $day         День в виде ГГГГ-ММ-ДД.
	 */
	public function hit( string $destination, int $post_id, int $rule_id, int $variant, string $day ): void;

	/**
	 * Строки счётчика за период, обе границы включены.
	 *
	 * @param string $from Первый день, ГГГГ-ММ-ДД.
	 * @param string $to   Последний день, ГГГГ-ММ-ДД.
	 * @return list<array{destination: string, post_id: int, rule_id: int, variant: int, day: string, hits: int}>
	 */
	public function rows( string $from, string $to ): array;
}
