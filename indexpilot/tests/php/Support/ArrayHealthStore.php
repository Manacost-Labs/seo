<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Health\Store;

/**
 * Результаты самопроверки в памяти для тестов.
 */
final class ArrayHealthStore implements Store {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public array $states = array();

	public function get( string $kind, string $url ): ?array {
		return $this->rows[ $kind . ' ' . $url ] ?? null;
	}

	public function save( string $kind, string $url, array $fields ): void {
		$this->rows[ $kind . ' ' . $url ] = array_merge(
			$this->rows[ $kind . ' ' . $url ] ?? array(
				'kind'     => $kind,
				'url'      => $url,
				'post_id'  => 0,
				'status'   => 0,
				'problems' => '',
				'fails'    => 0,
				'ms'       => 0,
				'words'    => 0,
				'checked'  => 0,
			),
			$fields
		);
	}

	public function due( array $kinds, int $now, int $limit ): array {
		$rows = array_filter(
			$this->rows,
			static fn( array $row ): bool => in_array( $row['kind'], $kinds, true ) && (int) $row['checked'] <= $now - ( (int) $row['fails'] > 0 ? \IndexPilot\Health\OutboundCheck::RECHECK_FAILED : \IndexPilot\Health\OutboundCheck::RECHECK_OK )
		);

		usort( $rows, static fn( array $a, array $b ): int => $a['checked'] <=> $b['checked'] );

		return array_slice( array_values( $rows ), 0, $limit );
	}

	public function remove_older( string $kind, int $before ): void {
		$this->rows = array_filter( $this->rows, static fn( array $row ): bool => $row['kind'] !== $kind || (int) $row['checked'] >= $before );
	}

	public function state( string $name ): array {
		return $this->states[ $name ] ?? array();
	}

	public function save_state( string $name, array $state ): void {
		$this->states[ $name ] = $state;
	}
}
