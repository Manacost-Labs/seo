<?php
/**
 * Очередь адресов для IndexNow.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\IndexNow;

/**
 * Адреса изменившихся страниц копятся в очереди и уходят поисковикам пачкой по расписанию.
 * Сколько бы раз запись ни сохраняли, её адрес в очереди один.
 */
final class Queue {

	public const OPTION   = 'indexpilot_indexnow_queue';
	public const MAX_SIZE = 5000;

	/**
	 * Чтение хранилища.
	 *
	 * @var callable
	 */
	private $load;

	/**
	 * Запись в хранилище.
	 *
	 * @var callable
	 */
	private $save;

	/**
	 * Конструктор. Без аргументов очередь хранится в опции WordPress.
	 *
	 * @param callable|null $load Функция, возвращающая массив «адрес => время добавления».
	 * @param callable|null $save Функция, сохраняющая такой массив.
	 */
	public function __construct( ?callable $load = null, ?callable $save = null ) {
		$this->load = $load ?? static function (): array {
			$stored = get_option( self::OPTION, array() );

			return is_array( $stored ) ? $stored : array();
		};
		$this->save = $save ?? static function ( array $urls ): void {
			update_option( self::OPTION, $urls, false );
		};
	}

	/**
	 * Добавляет адрес. Повторное добавление ничего не меняет.
	 *
	 * @param string $url Адрес страницы.
	 */
	public function add( string $url ): void {
		$url  = trim( $url );
		$urls = ( $this->load )();

		if ( '' === $url || isset( $urls[ $url ] ) ) {
			return;
		}

		$urls[ $url ] = time();

		// Очередь не растёт бесконечно, если отправка долго не удаётся: вытесняются самые старые адреса.
		if ( count( $urls ) > self::MAX_SIZE ) {
			$urls = array_slice( $urls, count( $urls ) - self::MAX_SIZE, null, true );
		}

		( $this->save )( $urls );
	}

	/**
	 * Забирает из очереди пачку адресов.
	 *
	 * @param int $limit Размер пачки.
	 * @return list<string>
	 */
	public function take( int $limit ): array {
		$urls  = ( $this->load )();
		$batch = array_slice( array_keys( $urls ), 0, max( 0, $limit ) );

		if ( array() !== $batch ) {
			( $this->save )( array_diff_key( $urls, array_flip( $batch ) ) );
		}

		return array_map( 'strval', $batch );
	}

	/**
	 * Возвращает неотправленную пачку в начало очереди.
	 *
	 * @param list<string> $batch Адреса.
	 */
	public function restore( array $batch ): void {
		$urls = array_fill_keys( $batch, time() ) + ( $this->load )();

		( $this->save )( array_slice( $urls, 0, self::MAX_SIZE, true ) );
	}

	/**
	 * Число адресов в очереди.
	 */
	public function count(): int {
		return count( ( $this->load )() );
	}
}
