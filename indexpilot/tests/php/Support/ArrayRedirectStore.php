<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Redirects\Matcher;
use IndexPilot\Redirects\Store;

/**
 * Правила редиректов в памяти для тестов.
 */
final class ArrayRedirectStore implements Store {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $rules = array();

	/**
	 * Индекс, собранный последним вызовом rebuild_index().
	 *
	 * @var array{exact: array<string, int>, patterns: array<int, string>}|null
	 */
	public ?array $index = null;

	private int $next = 1;

	public function all(): array {
		return array_values( $this->rules );
	}

	public function find( int $id ): ?array {
		return $this->rules[ $id ] ?? null;
	}

	public function find_by_source( string $key ): ?array {
		foreach ( $this->rules as $rule ) {
			if ( ! $rule['regex'] && Matcher::key( $rule['source'] ) === $key ) {
				return $rule;
			}
		}

		return null;
	}

	public function insert( array $rule ): int {
		$id                 = $this->next++;
		$this->rules[ $id ] = array( 'id' => $id ) + $rule + array(
			'regex'    => false,
			'target'   => '',
			'code'     => 301,
			'enabled'  => true,
			'hits'     => 0,
			'last_hit' => '',
			'note'     => '',
			'created'  => '',
		);

		return $id;
	}

	public function update( int $id, array $fields ): void {
		if ( isset( $this->rules[ $id ] ) ) {
			$this->rules[ $id ] = array_merge( $this->rules[ $id ], $fields );
		}
	}

	public function delete( int $id ): void {
		unset( $this->rules[ $id ] );
	}

	public function rebuild_index(): void {
		$this->index = Matcher::index( $this->all() );
	}
}
