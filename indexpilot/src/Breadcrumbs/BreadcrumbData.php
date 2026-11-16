<?php
/**
 * Данные для хлебных крошек.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Breadcrumbs;

use IndexPilot\Resolver\Context;

/**
 * Отделяет построение цепочки от WordPress, чтобы цепочку можно было проверять модульными тестами.
 * Если объекта нет, методы возвращают пустые название и адрес.
 */
interface BreadcrumbData {

	/**
	 * Главная страница.
	 *
	 * @return array{name: string, url: string}
	 */
	public function home(): array;

	/**
	 * Запись.
	 *
	 * @param int $post_id ID записи.
	 * @return array{name: string, url: string, type: string, parent: int}
	 */
	public function post( int $post_id ): array;

	/**
	 * Архив типа записи; пустое название — архива нет.
	 *
	 * @param string $post_type Тип записи.
	 * @return array{name: string, url: string}
	 */
	public function archive( string $post_type ): array;

	/**
	 * Главный термин записи для цепочки; id 0 — термина нет.
	 *
	 * @param int    $post_id   ID записи.
	 * @param string $post_type Тип записи.
	 * @return array{id: int, taxonomy: string}
	 */
	public function post_term( int $post_id, string $post_type ): array;

	/**
	 * Термин.
	 *
	 * @param int    $term_id  ID термина.
	 * @param string $taxonomy Таксономия.
	 * @return array{name: string, url: string, parent: int}
	 */
	public function term( int $term_id, string $taxonomy ): array;

	/**
	 * Автор.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array{name: string, url: string}
	 */
	public function author( int $user_id ): array;

	/**
	 * Название текущей страницы без собственного объекта: поиск, архив по дате, 404.
	 *
	 * @param Context $context Контекст страницы.
	 * @return array{name: string, url: string}
	 */
	public function current( Context $context ): array;
}
