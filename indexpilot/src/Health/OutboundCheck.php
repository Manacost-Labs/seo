<?php
/**
 * Проверка внешних ссылок, картинок и роликов из текстов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Health;

/**
 * Проверяет адреса пачками. Адрес мёртв после трёх неудач подряд: один сбой (сайт прилёг на минуту) — ещё не смерть.
 * Исправные адреса проверяются раз в неделю, со сбоями — раз в сутки, чтобы мёртвый адрес нашёлся за три дня.
 */
final class OutboundCheck {

	public const KINDS = array( 'link', 'image', 'video' );

	public const DEAD_AFTER     = 3;
	public const RECHECK_OK     = 7 * 86400;
	public const RECHECK_FAILED = 86400;

	/**
	 * Пауза между запросами, секунд.
	 */
	private const GAP = 1.0;

	/**
	 * @var callable(string, string): int
	 */
	private $fetch;

	/**
	 * @var callable(float): void
	 */
	private $sleep;

	/**
	 * @var callable(): float
	 */
	private $clock;

	/**
	 * @param Store                         $store Хранилище адресов.
	 * @param callable(string, string): int $fetch Код ответа адреса (адрес, вид); 0 — нет ответа.
	 * @param callable(float): void         $sleep Пауза в секундах.
	 * @param callable(): float             $clock Точное время для пауз.
	 */
	public function __construct( private Store $store, callable $fetch, callable $sleep, callable $clock ) {
		$this->fetch = $fetch;
		$this->sleep = $sleep;
		$this->clock = $clock;
	}

	/**
	 * Неудача ли это: true — да, false — адрес жив, null — сайт не пускает проверку (не считается ни тем, ни другим).
	 *
	 * @param int    $status Код ответа; 0 — нет ответа.
	 * @param string $kind   Вид адреса.
	 */
	public static function failed( int $status, string $kind ): ?bool {
		if ( $status >= 200 && $status < 400 ) {
			return false;
		}

		// Ролик проверяется через oEmbed: 401 и 403 значат, что ролик закрыт или его нельзя встроить.
		if ( 'video' !== $kind && in_array( $status, array( 401, 403, 405, 429 ), true ) ) {
			return null;
		}

		return true;
	}

	/**
	 * Новое состояние адреса после проверки.
	 *
	 * @param array<string, mixed>|null $row    Прежняя строка адреса.
	 * @param int                       $status Код ответа.
	 * @param string                    $kind   Вид адреса.
	 * @param int                       $now    Время проверки.
	 * @return array{status: int, fails: int, checked: int}
	 */
	public static function record( ?array $row, int $status, string $kind, int $now ): array {
		$fails  = (int) ( $row['fails'] ?? 0 );
		$failed = self::failed( $status, $kind );

		if ( true === $failed ) {
			++$fails;
		} elseif ( false === $failed ) {
			$fails = 0;
		}

		return array(
			'status'  => $status,
			'fails'   => min( 255, $fails ),
			'checked' => $now,
		);
	}

	/**
	 * Мёртв ли адрес.
	 *
	 * @param array<string, mixed>|null $row Строка адреса.
	 */
	public static function is_dead( ?array $row ): bool {
		return null !== $row && (int) ( $row['fails'] ?? 0 ) >= self::DEAD_AFTER;
	}

	/**
	 * Проверяет адреса, которым подошёл срок.
	 *
	 * @param int $limit Сколько адресов проверить.
	 * @param int $now   Текущее время.
	 * @return int Сколько проверено.
	 */
	public function check_due( int $limit, int $now ): int {
		return $this->check_rows( $this->store->due( self::KINDS, $now, $limit ), $now );
	}

	/**
	 * Проверяет указанные строки.
	 *
	 * @param list<array<string, mixed>> $rows Строки адресов.
	 * @param int                        $now  Текущее время.
	 * @return int Сколько проверено.
	 */
	public function check_rows( array $rows, int $now ): int {
		$last = null;

		foreach ( $rows as $row ) {
			$time = ( $this->clock )();

			if ( null !== $last && $time - $last < self::GAP ) {
				( $this->sleep )( self::GAP - ( $time - $last ) );
			}

			$last   = ( $this->clock )();
			$kind   = (string) $row['kind'];
			$status = (int) ( $this->fetch )( (string) $row['url'], $kind );

			$this->store->save( $kind, (string) $row['url'], self::record( $row, $status, $kind, $now ) );
		}

		return count( $rows );
	}
}
