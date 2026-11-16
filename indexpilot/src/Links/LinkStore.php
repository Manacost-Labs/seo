<?php
/**
 * Хранилище ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Отделяет разбор ссылок от базы данных, чтобы его можно было проверять модульными тестами.
 */
interface LinkStore {

	/**
	 * Заменяет все ссылки записи новым набором.
	 *
	 * @param int                                                                                  $post_id ID записи-источника.
	 * @param list<array{target_post_id: int, url: string, type: string, nofollow: bool}> $rows    Ссылки записи.
	 */
	public function replace( int $post_id, array $rows ): void;

	/**
	 * Удаляет ссылки записи-источника.
	 *
	 * @param int $post_id ID записи.
	 */
	public function remove( int $post_id ): void;

	/**
	 * Число записей, которые ссылаются на каждую из заданных (открытыми для поисковика ссылками).
	 *
	 * @param list<int> $post_ids ID записей.
	 * @return array<int, int> ID записи => число ссылающихся на неё записей.
	 */
	public function incoming( array $post_ids ): array;
}
