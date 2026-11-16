<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Links\LinkStore;

/**
 * Хранилище ссылок в памяти для тестов.
 */
final class ArrayLinkStore implements LinkStore {

	/**
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $by_post = array();

	public function replace( int $post_id, array $rows ): void {
		$this->by_post[ $post_id ] = $rows;
	}

	public function remove( int $post_id ): void {
		unset( $this->by_post[ $post_id ] );
	}

	public function incoming( array $post_ids ): array {
		$counts = array_fill_keys( $post_ids, 0 );

		foreach ( $this->by_post as $rows ) {
			$targets = array();

			foreach ( $rows as $row ) {
				if ( 'internal' === $row['type'] && ! $row['nofollow'] && $row['target_post_id'] > 0 ) {
					$targets[ $row['target_post_id'] ] = true;
				}
			}

			foreach ( array_keys( $targets ) as $target ) {
				if ( isset( $counts[ $target ] ) ) {
					++$counts[ $target ];
				}
			}
		}

		return $counts;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows( int $post_id ): array {
		return $this->by_post[ $post_id ] ?? array();
	}
}
