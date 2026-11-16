<?php
/**
 * Контекст страницы, для которой считаются мета-данные.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Неизменяемое описание страницы: что это за объект и какие несохранённые значения прислал редактор.
 */
final class Context {

	public const POST              = 'post';
	public const TERM              = 'term';
	public const AUTHOR            = 'author';
	public const POST_TYPE_ARCHIVE = 'post_type_archive';
	public const HOME              = 'home';
	public const SEARCH            = 'search';
	public const DATE              = 'date';
	public const NOT_FOUND         = '404';

	/**
	 * Конструктор.
	 *
	 * @param string               $type      Вид страницы, одна из констант класса.
	 * @param int                  $object_id ID записи, термина или автора; 0, если объекта нет.
	 * @param string               $subtype   Тип записи или таксономия.
	 * @param int                  $page      Номер страницы пагинации, начиная с 1.
	 * @param array<string, mixed> $overrides Несохранённые значения из редактора (title, excerpt, content, slug, cf).
	 * @param bool                 $preview   Предпросмотр в админке: неизвестные переменные шаблона остаются в тексте,
	 *                                        чтобы редактор их увидел. На сайте (false) они убираются.
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $object_id = 0,
		public readonly string $subtype = '',
		public readonly int $page = 1,
		public readonly array $overrides = array(),
		public readonly bool $preview = false
	) {
	}

	/**
	 * Значение, присланное редактором, или null.
	 *
	 * @param string $key Имя поля.
	 */
	public function override( string $key ): mixed {
		return $this->overrides[ $key ] ?? null;
	}

	/**
	 * Ключ для мемоизации: одинаковый у одинаковых контекстов.
	 */
	public function cache_key(): string {
		$key = $this->type . ':' . $this->object_id . ':' . $this->subtype . ':' . $this->page;

		if ( array() !== $this->overrides ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- класс не зависит от WordPress и проверяется модульными тестами.
			$key .= ':' . md5( (string) json_encode( $this->overrides ) );
		}

		return $key;
	}
}
