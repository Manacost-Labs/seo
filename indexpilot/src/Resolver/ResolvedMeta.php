<?php
/**
 * Итоговые мета-данные страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Результат работы резолвера: то, что выводится в head и показывается в предпросмотре сниппета.
 */
final class ResolvedMeta {

	/**
	 * Конструктор.
	 *
	 * @param string       $title              Заголовок страницы.
	 * @param string       $title_source       Откуда взят заголовок: custom или template.
	 * @param string       $description        Метаописание.
	 * @param string       $description_source Откуда взято описание: custom, answer, template, auto или none.
	 * @param string       $canonical          Канонический адрес; пустая строка, если не выводится.
	 * @param list<string> $robots             Директивы robots.
	 * @param string       $og_title           Заголовок для соцсетей.
	 * @param string       $og_description     Описание для соцсетей.
	 * @param int          $og_image_id        ID картинки для соцсетей; 0, если нет.
	 * @param string       $og_type            Тип Open Graph: article или website.
	 * @param list<string> $keywords           Ключевые слова: основное и дополнительные.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $title_source,
		public readonly string $description,
		public readonly string $description_source,
		public readonly string $canonical,
		public readonly array $robots,
		public readonly string $og_title,
		public readonly string $og_description,
		public readonly int $og_image_id,
		public readonly string $og_type,
		public readonly array $keywords
	) {
	}

	/**
	 * Разрешена ли индексация.
	 */
	public function is_indexable(): bool {
		return ! in_array( 'noindex', $this->robots, true );
	}

	/**
	 * Директивы robots одной строкой.
	 */
	public function robots_string(): string {
		return implode( ', ', $this->robots );
	}

	/**
	 * Массив для REST и предпросмотра.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this ) + array( 'indexable' => $this->is_indexable() );
	}
}
