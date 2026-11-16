<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Redirects\NotFoundStore;

/**
 * Журнал 404 в памяти для тестов.
 */
final class ArrayNotFoundStore implements NotFoundStore {

	/**
	 * @var array<string, array{id: int, url: string, hits: int, referrer: string}>
	 */
	public array $rows = array();

	/**
	 * До скольки строк урезался журнал при каждом урезании.
	 *
	 * @var list<int>
	 */
	public array $trims = array();

	private int $next = 1;

	public function add( string $url, string $referrer ): int {
		$key = mb_strtolower( $url );

		if ( isset( $this->rows[ $key ] ) ) {
			++$this->rows[ $key ]['hits'];
			$this->rows[ $key ]['referrer'] = '' !== $referrer ? $referrer : $this->rows[ $key ]['referrer'];

			return 0;
		}

		$this->rows[ $key ] = array(
			'id'       => $this->next,
			'url'      => $url,
			'hits'     => 1,
			'referrer' => $referrer,
		);

		return $this->next++;
	}

	public function trim( int $keep ): void {
		$this->trims[] = $keep;
		$this->rows    = array_slice( $this->rows, -$keep, null, true );
	}
}
