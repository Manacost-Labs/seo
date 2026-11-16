<?php
/**
 * Чтение данных записи для игрового модуля.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Отделяет разбор данных колоды от WordPress, чтобы его можно было проверять модульными тестами.
 */
interface PostReader {

	/**
	 * Название записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public function title( int $post_id ): string;

	/**
	 * Дата публикации в формате базы.
	 *
	 * @param int $post_id ID записи.
	 */
	public function date( int $post_id ): string;

	/**
	 * Значение произвольного поля; пустая строка, если поля нет.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $key     Ключ поля.
	 */
	public function meta( int $post_id, string $key ): string;

	/**
	 * Названия терминов записи в таксономии.
	 *
	 * @param int    $post_id  ID записи.
	 * @param string $taxonomy Таксономия.
	 * @return list<string>
	 */
	public function terms( int $post_id, string $taxonomy ): array;
}
