<?php
/**
 * Поиск близких текстов без зависимости от WordPress.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Audit;

/** Отпечатки MinHash и кандидаты LSH. */
final class NearDuplicates {
	public const THRESHOLD = 0.8;
	public const MIN_WORDS = 50;

	/**
	 * Строит отпечаток очищенного текста по пятисловным шинглам.
	 *
	 * @param string $html Исходная разметка.
	 * @param int    $size Размер отпечатка.
	 * @return list<int>
	 */
	public static function fingerprint( string $html, int $size = 64 ): array {
		$text = preg_replace( '~<(script|style)\b[^>]*>.*?</\1\s*>~isu', ' ', $html );
		$text = preg_replace( '/\[[^\]]*\]/u', '', $text );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Чистая функция без WordPress.
		$text  = strip_tags( $text );
		$text  = str_replace( 'ё', 'е', mb_strtolower( $text, 'UTF-8' ) );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( self::MIN_WORDS > count( $words ) || 0 >= $size ) {
			return array();
		}
		$result = array_fill( 0, $size, PHP_INT_MAX );
		$limit  = count( $words ) - 5;
		for ( $offset = 0; $offset <= $limit; ++$offset ) {
			$shingle = implode( ' ', array_slice( $words, $offset, 5 ) );
			for ( $i = 0; $i < $size; ++$i ) {
				$result[ $i ] = min( $result[ $i ], crc32( $i . ':' . $shingle ) );
			}
		}
		return $result;
	}

	/**
	 * Возвращает долю совпадающих позиций.
	 *
	 * @param array $a Первый отпечаток.
	 * @param array $b Второй отпечаток.
	 * @return float
	 */
	public static function similarity( array $a, array $b ): float {
		if ( array() === $a || count( $a ) !== count( $b ) ) {
			return 0.0;
		}
		$equal = 0;
		foreach ( $a as $i => $value ) {
			if ( $value === $b[ $i ] ) {
				++$equal;
			}
		}
		return $equal / count( $a );
	}

	/**
	 * Проверяет только кандидатов из совпавших полос по четыре строки.
	 * Неполная последняя полоса также участвует для произвольных размеров.
	 *
	 * @param array<int, list<int>> $fingerprints Отпечатки документов.
	 * @param float                $threshold Минимальное сходство.
	 * @return list<array{a:int, b:int, similarity:float}>
	 */
	public static function pairs( array $fingerprints, float $threshold = self::THRESHOLD ): array {
		$buckets    = array();
		$candidates = array();
		$result     = array();
		foreach ( $fingerprints as $id => $fingerprint ) {
			$size = count( $fingerprint );
			for ( $offset = 0; $offset < $size; $offset += 4 ) {
				$key = $size . ':' . $offset . ':' . implode( ',', array_slice( $fingerprint, $offset, 4 ) );
				foreach ( $buckets[ $key ] ?? array() as $other ) {
					$a                      = min( $id, $other );
					$b                      = max( $id, $other );
					$candidates[ $a ][ $b ] = true;
				}
				$buckets[ $key ][] = $id;
			}
		}
		foreach ( $candidates as $a => $others ) {
			foreach ( $others as $b => $unused ) {
				$similarity = self::similarity( $fingerprints[ $a ], $fingerprints[ $b ] );
				if ( $similarity >= $threshold ) {
					$result[] = array(
						'a'          => $a,
						'b'          => $b,
						'similarity' => $similarity,
					);
				}
			}
		}
		usort( $result, static fn( array $a, array $b ): int => array( -$a['similarity'], $a['a'], $a['b'] ) <=> array( -$b['similarity'], $b['a'], $b['b'] ) );
		return $result;
	}
}
