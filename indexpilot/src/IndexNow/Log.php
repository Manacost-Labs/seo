<?php
/**
 * Журнал отправок IndexNow.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\IndexNow;

/**
 * Последние отправки: когда, сколько адресов и что ответил поисковик. Нужен, чтобы видеть, что уведомления доходят.
 */
final class Log {

	public const OPTION      = 'indexpilot_indexnow_log';
	public const MAX_ENTRIES = 20;

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
	 * Конструктор. Без аргументов журнал хранится в опции WordPress.
	 *
	 * @param callable|null $load Функция, возвращающая список записей.
	 * @param callable|null $save Функция, сохраняющая список записей.
	 */
	public function __construct( ?callable $load = null, ?callable $save = null ) {
		$this->load = $load ?? static function (): array {
			$stored = get_option( self::OPTION, array() );

			return is_array( $stored ) ? $stored : array();
		};
		$this->save = $save ?? static function ( array $entries ): void {
			update_option( self::OPTION, $entries, false );
		};
	}

	/**
	 * Записывает отправку.
	 *
	 * @param int      $count  Сколько адресов отправлено.
	 * @param bool     $ok     Принял ли поисковик запрос.
	 * @param int      $code   Код ответа; 0 — ответа не было.
	 * @param string   $sample Первый адрес пачки.
	 * @param int|null $time   Время отправки; по умолчанию — сейчас.
	 */
	public function add( int $count, bool $ok, int $code, string $sample, ?int $time = null ): void {
		$entries = $this->entries();

		array_unshift(
			$entries,
			array(
				'time'   => $time ?? time(),
				'count'  => $count,
				'ok'     => $ok,
				'code'   => $code,
				'sample' => $sample,
			)
		);

		( $this->save )( array_slice( $entries, 0, self::MAX_ENTRIES ) );
	}

	/**
	 * Записи журнала, сначала самые свежие.
	 *
	 * @return list<array{time: int, count: int, ok: bool, code: int, sample: string}>
	 */
	public function entries(): array {
		$entries = array();

		foreach ( ( $this->load )() as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = array(
					'time'   => (int) ( $entry['time'] ?? 0 ),
					'count'  => (int) ( $entry['count'] ?? 0 ),
					'ok'     => ! empty( $entry['ok'] ),
					'code'   => (int) ( $entry['code'] ?? 0 ),
					'sample' => (string) ( $entry['sample'] ?? '' ),
				);
			}
		}

		return $entries;
	}
}
