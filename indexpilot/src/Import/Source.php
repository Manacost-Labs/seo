<?php
/**
 * Источник данных для импорта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Другой SEO-плагин, из которого переносятся поля записей. Источник только читает: его данные не меняются.
 */
interface Source {

	/**
	 * Короткий идентификатор латиницей.
	 */
	public function id(): string;

	/**
	 * Название для интерфейса.
	 */
	public function label(): string;

	/**
	 * Запасной источник используется только для записей, у которых ещё нет заголовка и описания.
	 */
	public function is_fallback(): bool;

	/**
	 * Есть ли на сайте данные этого источника.
	 */
	public function available(): bool;

	/**
	 * Сколько записей с данными есть в источнике.
	 */
	public function total(): int;

	/**
	 * Очередная пачка строк источника по возрастанию ID записи.
	 *
	 * @param int $after_id Брать записи с ID больше этого.
	 * @param int $limit    Размер пачки.
	 * @return array<int, array<string, mixed>> ID записи => строка источника.
	 */
	public function batch( int $after_id, int $limit ): array;

	/**
	 * Переводит строку источника в поля IndexPilot. Пустые значения в результат не попадают.
	 *
	 * @param array<string, mixed> $row Строка источника.
	 * @return array<string, mixed> Ключ меты IndexPilot => значение; адрес картинки — под ключом Importer::OG_IMAGE_URL.
	 */
	public function map( array $row ): array;
}
