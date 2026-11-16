<?php
/**
 * Журнал переходов без персональных данных.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Tests\Support;
use IndexPilot\ShortLinks\ClickLog;
/** Журнал в памяти. */
final class ArrayClickLog implements ClickLog {
	private array $rows = array();

	/**
	 * Записывает переход.
	 *
	 * @param string $slug Код.
	 * @param string $day День UTC.
	 * @param string $referrer Хост источника.
	 */
	public function add( string $slug, string $day, string $referrer ): void { $this->rows[] = array( $slug, $day, $referrer ); }
	/**
	 * Переходы по дням.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @return array<string,int>
	 */
	public function by_day( string $slug, int $days ): array { return $this->aggregate( $slug, $days, 1 ); }
	/**
	 * Переходы по источникам.
	 *
	 * @param string $slug Код.
	 * @param int $days Период.
	 * @return array<string,int>
	 */
	public function by_referrer( string $slug, int $days ): array { return $this->aggregate( $slug, $days, 2 ); }

	private function aggregate( string $slug, int $days, int $column ): array {
		$result = array();
		$since = gmdate( 'Y-m-d', strtotime( '-' . max( 0, $days - 1 ) . ' days' ) );
		foreach ( $this->rows as $row ) {
			if ( $days > 0 && $slug === $row[0] && $row[1] >= $since && $row[1] <= gmdate( 'Y-m-d' ) ) { $result[ $row[$column] ] = ( $result[ $row[$column] ] ?? 0 ) + 1; }
		}
		ksort( $result );
		return $result;
	}
}
