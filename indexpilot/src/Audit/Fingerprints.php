<?php
/**
 * Отпечатки текстов для поиска похожих записей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Audit;

/**
 * Хранение отпечатка NearDuplicates в столбце таблицы аудита: числа через запятую.
 * У колод и коротких текстов (меньше 50 слов) отпечатка нет — они не сравниваются.
 * Класс не зависит от WordPress.
 */
final class Fingerprints {

	/**
	 * Отпечаток записи для хранения; пусто — запись не сравнивается.
	 *
	 * @param string $html    Текст записи.
	 * @param bool   $is_deck Колода: её текст — список карт, а не статья.
	 */
	public static function for_post( string $html, bool $is_deck ): string {
		return $is_deck ? '' : implode( ',', NearDuplicates::fingerprint( $html ) );
	}

	/**
	 * Отпечаток из столбца таблицы.
	 *
	 * @param string $stored Числа через запятую.
	 * @return list<int>
	 */
	public static function decode( string $stored ): array {
		return '' === $stored ? array() : array_map( 'intval', explode( ',', $stored ) );
	}

	/**
	 * Пары похожих записей по сохранённым отпечаткам.
	 *
	 * @param array<int, string> $stored ID записи => отпечаток из таблицы.
	 * @param int                $limit  Сколько пар вернуть.
	 * @return list<array{a: int, b: int, similarity: float}>
	 */
	public static function pairs( array $stored, int $limit = 100 ): array {
		$fingerprints = array();

		foreach ( $stored as $id => $value ) {
			$fingerprint = self::decode( (string) $value );

			if ( array() !== $fingerprint ) {
				$fingerprints[ (int) $id ] = $fingerprint;
			}
		}

		return array_slice( NearDuplicates::pairs( $fingerprints ), 0, $limit );
	}
}
