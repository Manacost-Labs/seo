<?php
/**
 * Журнал переходов без персональных данных.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\ShortLinks;

/** Контракт журнала. */
interface ClickLog {

	/**
	 * Записывает переход.
	 *
	 * @param string $slug Код.
	 * @param string $day День UTC.
	 * @param string $referrer Хост источника.
	 */
	public function add( string $slug, string $day, string $referrer ): void;
	/**
	 * Переходы по дням.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @return array<string,int>
	 */
	public function by_day( string $slug, int $days ): array;
	/**
	 * Переходы по источникам.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @return array<string,int>
	 */
	public function by_referrer( string $slug, int $days ): array;
}
