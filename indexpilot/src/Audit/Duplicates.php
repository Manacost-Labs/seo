<?php
/**
 * Группы страниц с одинаковым текстом.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Audit;

/**
 * Собирает страницы с одинаковым отпечатком заголовка или описания в группы.
 */
final class Duplicates {

	/**
	 * Группы из двух и более страниц; сначала самые большие.
	 *
	 * @param array<int, string> $hashes ID страницы => отпечаток; пустой отпечаток пропускается.
	 * @return list<list<int>>
	 */
	public static function groups( array $hashes ): array {
		$by_hash = array();

		foreach ( $hashes as $id => $hash ) {
			if ( '' !== $hash ) {
				$by_hash[ $hash ][] = (int) $id;
			}
		}

		$groups = array_values( array_filter( $by_hash, static fn( array $ids ): bool => count( $ids ) > 1 ) );

		usort( $groups, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );

		return $groups;
	}
}
