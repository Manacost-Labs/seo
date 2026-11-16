<?php
/**
 * Выгрузка и загрузка правил редиректов в CSV.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Столбцы: source, target, code, regex, enabled, note, hits. Первая строка — заголовок.
 */
final class Csv {

	public const COLUMNS = array( 'source', 'target', 'code', 'regex', 'enabled', 'note', 'hits' );

	/**
	 * Правила в виде текста CSV.
	 *
	 * @param list<array<string, mixed>> $rules Правила.
	 */
	public static function build( array $rules ): string {
		$lines = array( implode( ',', self::COLUMNS ) );

		foreach ( $rules as $rule ) {
			$cells = array();

			foreach ( self::COLUMNS as $column ) {
				$value   = $rule[ $column ] ?? '';
				$value   = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
				$cells[] = self::cell( $value );
			}

			$lines[] = implode( ',', $cells );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Разбирает текст CSV в правила. Столбцы определяются по заголовку; без заголовка — по порядку COLUMNS.
	 *
	 * @param string $text Текст файла.
	 * @return list<array<string, mixed>>
	 */
	public static function parse( string $text ): array {
		$text  = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) );
		$lines = is_array( $lines ) ? $lines : array();
		$head  = self::COLUMNS;
		$rules = array();

		if ( array() !== $lines && in_array( 'source', array_map( 'strtolower', array_map( 'trim', str_getcsv( $lines[0], ',', '"', '' ) ) ), true ) ) {
			$head = array_map( 'strtolower', array_map( 'trim', str_getcsv( (string) array_shift( $lines ), ',', '"', '' ) ) );
		}

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$cells = str_getcsv( $line, ',', '"', '' );
			$row   = array();

			foreach ( $head as $index => $name ) {
				$row[ $name ] = trim( (string) ( $cells[ $index ] ?? '' ) );
			}

			if ( '' === ( $row['source'] ?? '' ) ) {
				continue;
			}

			$rules[] = array(
				'source'  => $row['source'],
				'target'  => $row['target'] ?? '',
				'code'    => '' !== ( $row['code'] ?? '' ) ? (int) $row['code'] : 301,
				'regex'   => in_array( strtolower( $row['regex'] ?? '' ), array( '1', 'yes', 'true', 'да' ), true ),
				'enabled' => ! in_array( strtolower( $row['enabled'] ?? '1' ), array( '0', 'no', 'false', 'нет' ), true ),
				'note'    => $row['note'] ?? '',
			);
		}

		return $rules;
	}

	/**
	 * Ячейка CSV: в кавычках, если нужно. Значение, похожее на формулу, экранируется апострофом.
	 *
	 * @param string $value Значение.
	 */
	private static function cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}

		return 1 === preg_match( '/[",\r\n]/', $value ) ? '"' . str_replace( '"', '""', $value ) . '"' : $value;
	}
}
