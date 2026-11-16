<?php
/**
 * Глубина внутренних ссылок без зависимости от WordPress.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Links;

/** Обход ссылок по слоям. */
final class Depth {
	/**
	 * Вычисляет минимальную глубину, ограничивая расширение графа.
	 *
	 * @param array<int, list<int>> $edges Ссылки записей.
	 * @param array<int, int>       $start Начальные глубины.
	 * @param int                  $max_depth Максимальная глубина.
	 * @return array<int, int>
	 */
	public static function compute( array $edges, array $start, int $max_depth = 10 ): array {
		$layers = array();
		foreach ( $start as $id => $depth ) {
			if ( $depth <= $max_depth ) {
				$layers[ $depth ][] = $id;
			}
		}
		$result = array();
		if ( array() === $layers ) {
			return $result;
		}
		$seed_depths = array_keys( $layers );
		sort( $seed_depths, SORT_NUMERIC );
		$seed_index = 0;
		$depth      = $seed_depths[0];
		while ( array() !== $layers ) {
			$ids = $layers[ $depth ];
			unset( $layers[ $depth ] );
			foreach ( $ids as $id ) {
				if ( isset( $result[ $id ] ) ) {
					continue;
				}
				$result[ $id ] = $depth;
				if ( $depth >= $max_depth ) {
					continue;
				}
				foreach ( $edges[ $id ] ?? array() as $target ) {
					if ( $target !== $id && ! isset( $result[ $target ] ) ) {
						$layers[ $depth + 1 ][] = $target;
					}
				}
			}
			if ( isset( $layers[ $depth + 1 ] ) ) {
				++$depth;
			} elseif ( array() !== $layers ) {
				while ( $seed_depths[ $seed_index ] <= $depth ) {
					++$seed_index;
				}
				$depth = $seed_depths[ $seed_index ];
			}
		}
		uksort( $result, static fn( int $a, int $b ): int => $result[ $a ] === $result[ $b ] ? $a <=> $b : $result[ $a ] <=> $result[ $b ] );
		return $result;
	}

	/**
	 * Возвращает недостижимые записи в исходном порядке.
	 *
	 * @param array<int, int> $depths Найденные глубины.
	 * @param list<int>       $posts Записи.
	 * @return list<int>
	 */
	public static function unreachable( array $depths, array $posts ): array {
		return array_values( array_filter( $posts, static fn( int $id ): bool => ! array_key_exists( $id, $depths ) ) );
	}
}
