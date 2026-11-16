<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Import\Source;

/**
 * Источник импорта из массива: строки уже в формате полей IndexPilot.
 */
final class ArraySource implements Source {

	/**
	 * @param array<int, array<string, mixed>> $rows     Строки: ID записи => поля.
	 * @param bool                             $fallback Запасной источник.
	 */
	public function __construct( private array $rows, private bool $fallback = false ) {
		ksort( $this->rows );
	}

	public function id(): string {
		return 'array';
	}

	public function label(): string {
		return 'Array';
	}

	public function is_fallback(): bool {
		return $this->fallback;
	}

	public function available(): bool {
		return true;
	}

	public function total(): int {
		return count( $this->rows );
	}

	public function batch( int $after_id, int $limit ): array {
		$batch = array();

		foreach ( $this->rows as $post_id => $row ) {
			if ( $post_id > $after_id && count( $batch ) < $limit ) {
				$batch[ $post_id ] = $row;
			}
		}

		return $batch;
	}

	public function map( array $row ): array {
		return $row;
	}
}
