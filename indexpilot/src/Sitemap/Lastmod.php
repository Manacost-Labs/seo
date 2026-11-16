<?php
/**
 * Дата последнего изменения в карте сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

/**
 * У архива термина или автора дата изменения — это дата последней изменённой опубликованной записи в нём.
 */
final class Lastmod {

	/**
	 * Время в формате W3C из времени MySQL по Гринвичу; пустая строка, если времени нет.
	 *
	 * @param string $mysql_gmt Время из базы.
	 */
	public static function w3c( string $mysql_gmt ): string {
		$time = '' === $mysql_gmt ? false : strtotime( $mysql_gmt . ' UTC' );

		return false !== $time && $time > 0 ? gmdate( 'Y-m-d\TH:i:s+00:00', $time ) : '';
	}

	/**
	 * Самое позднее время из списка. Значения одного формата сравниваются как строки.
	 *
	 * @param array<int|string, string> $values Время в одном формате; пустые строки пропускаются.
	 */
	public static function latest( array $values ): string {
		$values = array_filter( array_map( 'strval', $values ), static fn( string $value ): bool => '' !== $value );

		return array() === $values ? '' : (string) max( $values );
	}

	/**
	 * Дата термина с учётом дочерних: в архив рубрики входят и записи её подрубрик.
	 *
	 * @param array<int, string> $own     ID термина => время последней записи самого термина (пустая строка — записей нет).
	 * @param array<int, int>    $parents ID термина => ID родителя (0 — родителя нет).
	 * @return array<int, string> ID термина => время; термины без записей в результат не входят.
	 */
	public static function with_descendants( array $own, array $parents ): array {
		$result = array();

		foreach ( $own as $term_id => $modified ) {
			$modified = (string) $modified;

			if ( '' === $modified ) {
				continue;
			}

			$current = (int) $term_id;
			$seen    = array();

			// Дата поднимается от термина ко всем его предкам.
			while ( $current > 0 && ! isset( $seen[ $current ] ) ) {
				$seen[ $current ] = true;

				if ( ! isset( $result[ $current ] ) || $modified > $result[ $current ] ) {
					$result[ $current ] = $modified;
				}

				$current = (int) ( $parents[ $current ] ?? 0 );
			}
		}

		return $result;
	}
}
