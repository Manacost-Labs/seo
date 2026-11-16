<?php
/**
 * Источник данных о странице.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Всё, что резолверу нужно знать о записи, термине или архиве. Реализация для WordPress — WpDataSource.
 */
interface DataSource {

	/**
	 * Мета объекта; пустая строка, если значения нет.
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $key     Ключ меты.
	 */
	public function meta( Context $context, string $key ): mixed;

	/**
	 * Исходный текст для автоописания.
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $source  Источник: excerpt, content, term_description, author_bio, tagline или cf:КЛЮЧ.
	 */
	public function text( Context $context, string $source ): string;

	/**
	 * Адрес первой страницы объекта; пустая строка, если у страницы нет постоянного адреса.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function url( Context $context ): string;

	/**
	 * Адрес с учётом номера страницы пагинации.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function paged_url( Context $context ): string;

	/**
	 * Закрыта ли запись паролем или статусом «личное».
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function is_private( Context $context ): bool;

	/**
	 * Число записей в архиве термина; -1, если неприменимо.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function count( Context $context ): int;

	/**
	 * ID картинки объекта (миниатюра записи); 0, если её нет.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function image_id( Context $context ): int;
}
