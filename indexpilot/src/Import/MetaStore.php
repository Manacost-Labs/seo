<?php
/**
 * Хранилище меты записей для импорта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Отделяет логику импорта от WordPress, чтобы её можно было проверять модульными тестами.
 */
interface MetaStore {

	/**
	 * Существует ли запись, в которую можно писать.
	 *
	 * @param int $post_id ID записи.
	 */
	public function has( int $post_id ): bool;

	/**
	 * Текущее значение меты; пустая строка, если значения нет.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ меты.
	 */
	public function get( int $post_id, string $key ): mixed;

	/**
	 * Записывает значение меты.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ меты.
	 * @param mixed  $value   Значение.
	 */
	public function set( int $post_id, string $key, mixed $value ): void;
}
