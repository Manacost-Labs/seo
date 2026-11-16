<?php
/**
 * Состояние импорта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Запоминает, до какой записи дошёл импорт каждого источника, чтобы его можно было продолжить после обрыва.
 */
final class ImportState {

	public const OPTION = 'indexpilot_import_state';

	private const EMPTY_STATE = array(
		'last_id'   => 0,
		'processed' => 0,
		'imported'  => 0,
		'skipped'   => 0,
		'fields'    => 0,
		'done'      => false,
		'updated'   => 0,
	);

	/**
	 * Состояние источника.
	 *
	 * @param string $source_id Идентификатор источника.
	 * @return array{last_id: int, processed: int, imported: int, skipped: int, fields: int, done: bool, updated: int}
	 */
	public function get( string $source_id ): array {
		$all   = get_option( self::OPTION, array() );
		$state = is_array( $all ) && is_array( $all[ $source_id ] ?? null ) ? $all[ $source_id ] : array();
		$state = array_merge( self::EMPTY_STATE, array_intersect_key( $state, self::EMPTY_STATE ) );

		foreach ( $state as $key => $value ) {
			$state[ $key ] = 'done' === $key ? (bool) $value : (int) $value;
		}

		return $state;
	}

	/**
	 * Добавляет результат обработанной пачки к состоянию источника.
	 *
	 * @param string               $source_id Идентификатор источника.
	 * @param array<string, mixed> $result    Результат Importer::run().
	 * @return array{last_id: int, processed: int, imported: int, skipped: int, fields: int, done: bool, updated: int}
	 */
	public function add( string $source_id, array $result ): array {
		$state = $this->get( $source_id );

		foreach ( array( 'processed', 'imported', 'skipped', 'fields' ) as $counter ) {
			$state[ $counter ] += (int) ( $result[ $counter ] ?? 0 );
		}

		$state['last_id'] = (int) ( $result['last_id'] ?? $state['last_id'] );
		$state['done']    = (bool) ( $result['done'] ?? false );
		$state['updated'] = time();

		$all               = get_option( self::OPTION, array() );
		$all               = is_array( $all ) ? $all : array();
		$all[ $source_id ] = $state;

		update_option( self::OPTION, $all, false );

		return $state;
	}

	/**
	 * Сбрасывает состояние источника: следующий запуск начнётся с первой записи.
	 *
	 * @param string $source_id Идентификатор источника.
	 */
	public function reset( string $source_id ): void {
		$all = get_option( self::OPTION, array() );

		if ( is_array( $all ) && isset( $all[ $source_id ] ) ) {
			unset( $all[ $source_id ] );
			update_option( self::OPTION, $all, false );
		}
	}
}
