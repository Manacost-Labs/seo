<?php
/**
 * Хранилище строк поисковых запросов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Таблица indexpilot_queries: страница, объект сайта, запрос, поисковик, клики, показы, позиция, период.
 */
interface QueryStore {

	/**
	 * Заменяет все строки поисковика за период новыми: повторная загрузка не удваивает данные.
	 *
	 * @param string                           $engine Поисковик: google или yandex.
	 * @param string                           $period Период: current, previous или p90.
	 * @param list<array<string, string|int|float>> $rows   Строки: page, object_type, object_id, query, clicks, impressions, position.
	 * @param int                              $synced Время загрузки.
	 */
	public function replace( string $engine, string $period, array $rows, int $synced ): void;

	/**
	 * Удаляет строки, загруженные раньше указанного времени.
	 *
	 * @param int $before Граница.
	 */
	public function remove_older( int $before ): void;

	/**
	 * Удаляет строки поисковиков, которые больше не подключены.
	 *
	 * @param list<string> $engines Подключённые поисковики.
	 */
	public function keep_engines( array $engines ): void;
}
