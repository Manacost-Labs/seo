<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\DataSource;

/**
 * Источник данных для тестов: всё задаётся массивами, WordPress не нужен.
 */
final class ArrayDataSource implements DataSource {

	/**
	 * @param array<string, mixed>  $meta    Мета объекта: ключ => значение.
	 * @param array<string, string> $texts   Тексты: excerpt, content, term_description, author_bio, tagline.
	 * @param string                $url     Адрес первой страницы объекта.
	 * @param bool                  $private Закрыта ли запись паролем.
	 * @param int                   $count   Число записей в архиве термина.
	 * @param int                   $image   ID картинки записи.
	 */
	public function __construct(
		public array $meta = array(),
		public array $texts = array(),
		public string $url = 'https://example.test/item/',
		public bool $private = false,
		public int $count = -1,
		public int $image = 0
	) {
	}

	public function meta( Context $context, string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function text( Context $context, string $source ): string {
		return $this->texts[ $source ] ?? '';
	}

	public function url( Context $context ): string {
		return $this->url;
	}

	public function paged_url( Context $context ): string {
		if ( '' === $this->url || $context->page < 2 ) {
			return $this->url;
		}

		return $this->url . 'page/' . $context->page . '/';
	}

	public function is_private( Context $context ): bool {
		return $this->private;
	}

	public function count( Context $context ): int {
		return $this->count;
	}

	public function image_id( Context $context ): int {
		return $this->image;
	}
}
