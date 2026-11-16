<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\SearchData\QueryStore;

/**
 * Таблица запросов в памяти для тестов.
 */
final class ArrayQueryStore implements QueryStore {

	/**
	 * @var list<array<string, mixed>>
	 */
	public array $rows = array();

	public function replace( string $engine, string $period, array $rows, int $synced ): void {
		$this->rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => ! ( $row['engine'] === $engine && $row['period'] === $period ) ) );

		foreach ( $rows as $row ) {
			$this->rows[] = array(
				'engine'      => $engine,
				'period'      => $period,
				'page'        => (string) $row['page'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'query'       => (string) $row['query'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
				'synced'      => $synced,
			);
		}
	}

	public function remove_older( int $before ): void {
		$this->rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['synced'] >= $before ) );
	}

	public function keep_engines( array $engines ): void {
		$this->rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => in_array( $row['engine'], $engines, true ) ) );
	}
}
