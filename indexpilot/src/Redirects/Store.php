<?php
/**
 * Хранилище своих правил редиректов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Правило — массив: id, source, regex (bool), target, code, enabled (bool), hits, last_hit, note, created.
 */
interface Store {

	/**
	 * Все правила.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array;

	/**
	 * Правило по номеру.
	 *
	 * @param int $id Номер правила.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array;

	/**
	 * Правило с точным адресом по ключу адреса (Matcher::key()).
	 *
	 * @param string $key Ключ адреса.
	 * @return array<string, mixed>|null
	 */
	public function find_by_source( string $key ): ?array;

	/**
	 * Добавляет правило.
	 *
	 * @param array<string, mixed> $rule Поля правила.
	 * @return int Номер нового правила.
	 */
	public function insert( array $rule ): int;

	/**
	 * Меняет поля правила.
	 *
	 * @param int                  $id     Номер правила.
	 * @param array<string, mixed> $fields Новые значения.
	 */
	public function update( int $id, array $fields ): void;

	/**
	 * Удаляет правило.
	 *
	 * @param int $id Номер правила.
	 */
	public function delete( int $id ): void;

	/**
	 * Пересобирает индекс поиска после изменений.
	 */
	public function rebuild_index(): void;
}
