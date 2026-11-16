<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Promo\ClickStore;

/**
 * Счётчик переходов в памяти для тестов.
 */
final class ArrayClickStore implements ClickStore {

	/**
	 * @var array<string, array{destination: string, post_id: int, rule_id: int, variant: int, day: string, hits: int}>
	 */
	private array $rows = array();

	public function hit( string $destination, int $post_id, int $rule_id, int $variant, string $day ): void {
		$key = implode( '|', array( $day, $destination, $post_id, $rule_id, $variant ) );

		if ( ! isset( $this->rows[ $key ] ) ) {
			$this->rows[ $key ] = array(
				'destination' => $destination,
				'post_id'     => $post_id,
				'rule_id'     => $rule_id,
				'variant'     => $variant,
				'day'         => $day,
				'hits'        => 0,
			);
		}

		++$this->rows[ $key ]['hits'];
	}

	public function rows( string $from, string $to ): array {
		return array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['day'] >= $from && $row['day'] <= $to ) );
	}
}
