<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Game\PostReader;

/**
 * Записи из массива для тестов игрового модуля.
 */
final class ArrayPostReader implements PostReader {

	/**
	 * @param array<int, array{title?: string, date?: string, meta?: array<string, string>, terms?: array<string, list<string>>}> $posts Записи.
	 */
	public function __construct( private array $posts ) {
	}

	public function title( int $post_id ): string {
		return $this->posts[ $post_id ]['title'] ?? '';
	}

	public function date( int $post_id ): string {
		return $this->posts[ $post_id ]['date'] ?? '';
	}

	public function meta( int $post_id, string $key ): string {
		return $this->posts[ $post_id ]['meta'][ $key ] ?? '';
	}

	public function terms( int $post_id, string $taxonomy ): array {
		return $this->posts[ $post_id ]['terms'][ $taxonomy ] ?? array();
	}
}
