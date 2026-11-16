<?php
/**
 * Данные поисковых систем без зависимости от WordPress.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
/** Чистый слой: стандартные PHP-функции, сообщения экранирует интерфейс. */
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.Security.EscapeOutput.ExceptionNotEscaped
namespace IndexPilot\SearchData;

final class Sync {
	public function merge( array $existing, array $incoming, string $engine, string $period ): array {
		$result = array();
		foreach ( array_merge(
			$existing,
			array_map(
				static function ( $row ) use ( $engine, $period ) {
					$row['engine'] = $engine;
					$row['period'] = $period;
					return $row;},
				$incoming
			)
		) as $row ) {
			$key            = json_encode( array( $row['engine'], $row['period'], $row['page'], $row['query'] ) );
			$result[ $key ] = $row;
		}
		return array_values( $result );
	}
	public function top_per_page( array $rows, int $limit = 50 ): array {
		usort( $rows, static fn( $a, $b )=>$b['impressions'] <=> $a['impressions'] );
		$counts = array();
		$result = array();
		foreach ( $rows as $row ) {
			$page            = $row['page'];
			$counts[ $page ] = ( $counts[ $page ] ?? 0 ) + 1;
			if ( $counts[ $page ] <= $limit ) {
				$result[] = $row;}
		}
		return $result;
	}
}
