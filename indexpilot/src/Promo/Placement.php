<?php
/**
 * Размещение готовых промоблоков.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Promo;

/** Вставляет блоки, сохраняя исходные байты. */
final class Placement {
	public const MAX_BLOCKS = 2;
	/**
	 * Вставляет готовые блоки.
	 * @param string $content Содержимое.
	 * @param array $blocks Блоки.
	 * @return string
	 */
	public static function insert( string $content, array $blocks ): string {
		preg_match_all( '/<h2\b/i', $content, $headings, PREG_OFFSET_CAPTURE );
		preg_match_all( '/<\/p\s*>/i', $content, $paragraphs, PREG_OFFSET_CAPTURE );
		$positions = array();
		$count     = 0;
		$end       = strlen( $content );
		foreach ( $blocks as $block ) {
			if ( '' === $block['html'] ) {
				continue;}
			if ( self::MAX_BLOCKS <= $count ) {
				break;}
			++$count;
			$position = $end;
			$place    = $block['place'];
			if ( 'end' !== $place && in_array( $place, array( 'after_first', 'middle' ), true ) ) {
				$n = count( $headings[0] );
				if ( 0 < $n ) {
					$section  = 'after_first' === $place ? 1 : max( 1, (int) floor( $n / 2 ) );
					$position = $headings[0][ $section ][1] ?? $end;
				} else {
							$n = count( $paragraphs[0] );
					if ( 0 < $n ) {
						$index    = 'after_first' === $place ? 0 : max( 1, (int) floor( $n / 2 ) ) - 1;
						$p        = $paragraphs[0][ $index ];
						$position = $p[1] + strlen( $p[0] );}
				}
			}
			$positions[ $position ] = ( $positions[ $position ] ?? '' ) . $block['html'];
		}
		krsort( $positions, SORT_NUMERIC );
		foreach ( $positions as $position => $html ) {
			$content = substr( $content, 0, $position ) . $html . substr( $content, $position );}
		return $content;
	}
}
