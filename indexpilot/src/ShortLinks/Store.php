<?php
/**
 * Хранилище коротких ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\ShortLinks;

/**
 * Отделяет правила коротких ссылок от базы данных, чтобы их можно было проверять модульными тестами.
 */
interface Store {

	/**
	 * Ссылка по коду или null.
	 *
	 * @param string $slug Код.
	 * @return array{id: int, slug: string, target: string, code: int, clicks: int}|null
	 */
	public function find( string $slug ): ?array;

	/**
	 * Создаёт ссылку и возвращает её ID.
	 *
	 * @param string $slug   Код.
	 * @param string $target Адрес, на который ведёт ссылка.
	 * @param int    $code   Код перенаправления.
	 * @param string $campaign Кампания.
	 * @param ?string $expires_at Срок действия.
	 * @param int    $author ID пользователя, создавшего ссылку; 0 — создана системой.
	 */
	public function insert( string $slug, string $target, int $code, string $campaign = '', ?string $expires_at = null, int $author = 0 ): int;

	/**
	 * Засчитывает переход.
	 *
	 * @param string $slug Код.
	 */
	public function hit( string $slug ): void;

	/**
	 * Ссылки, новые первыми.
	 *
	 * @param int $limit  Сколько вернуть.
	 * @param int $offset Сколько пропустить.
	 * @param ?string $campaign Фильтр кампании.
	 * @param ?string $search   Поиск по коду и адресу цели.
	 * @return list<array<string, mixed>>
	 */
	public function all( int $limit, int $offset, ?string $campaign = null, ?string $search = null ): array;

	/**
	 * Число ссылок.
	 */
	public function count(): int;

	/**
	 * Удаляет ссылку.
	 *
	 * @param int $id ID ссылки.
	 */
	public function delete( int $id ): void;

	/**
	 * Изменяет адрес, сохраняя код.
	 *
	 * @param int $id ID ссылки.
	 * @param string $target Новый адрес.
	 */
	public function update_target( int $id, string $target ): bool;

	/**
	 * Список непустых кампаний.
	 *
	 * @return list<string>
	 */
	public function campaigns(): array;

	/**
	 * Находит ссылку по ID.
	 *
	 * @param int $id ID ссылки.
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $id ): ?array;
}
