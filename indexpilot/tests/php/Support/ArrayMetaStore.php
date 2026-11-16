<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Import\MetaStore;

/**
 * Хранилище меты в памяти для тестов импорта.
 */
final class ArrayMetaStore implements MetaStore {

	/**
	 * @param array<int, array<string, mixed>> $posts Записи: ID => мета.
	 */
	public function __construct( private array $posts = array() ) {
	}

	public function has( int $post_id ): bool {
		return isset( $this->posts[ $post_id ] );
	}

	public function get( int $post_id, string $key ): mixed {
		return $this->posts[ $post_id ][ $key ] ?? '';
	}

	public function set( int $post_id, string $key, mixed $value ): void {
		$this->posts[ $post_id ][ $key ] = $value;
	}
}
