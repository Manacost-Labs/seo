<?php
/**
 * Загрузка данных поиска пачками.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Обход по шагам: один шаг — один поисковик за один период. За запуск выполняется несколько шагов,
 * остальные — в следующих запусках по расписанию. Ошибка поисковика записывается в состояние понятным текстом,
 * остальные шаги этого поисковика в том же обходе пропускаются, другой поисковик загружается как обычно.
 */
final class SyncJob {

	/**
	 * Сколько самых частых запросов хранится на одну страницу.
	 */
	public const TOP_PER_PAGE = 50;

	/**
	 * Сколько дней хранятся строки, которые перестали обновляться (поисковик долго отвечает ошибкой).
	 */
	public const KEEP_DAYS = 14;

	/**
	 * Отставание данных поисковиков: последние дни ещё не посчитаны.
	 */
	private const LAG_DAYS = 3;

	/**
	 * Конструктор.
	 *
	 * @param array<string, array<string, callable(string, string): list<array<string, mixed>>>> $sources Поисковик => период => загрузка строк за даты «с» и «по».
	 * @param QueryStore                                                                       $store   Таблица запросов.
	 * @param UrlMatcher                                                                       $matcher Адрес => запись, рубрика или автор.
	 * @param Sync                                                                             $sync    Слияние без повторов и отбор запросов.
	 */
	public function __construct(
		private array $sources,
		private QueryStore $store,
		private UrlMatcher $matcher,
		private Sync $sync = new Sync()
	) {
	}

	/**
	 * Периоды по дате: последние 28 дней, предыдущие 28 и последние 90 (для поиска страниц без жизни).
	 *
	 * @param string $today Сегодня, ГГГГ-ММ-ДД.
	 * @return array{current: array{0: string, 1: string}, previous: array{0: string, 1: string}, p90: array{0: string, 1: string}}
	 */
	public static function periods( string $today ): array {
		$day = static fn( int $offset ): string => gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - $offset * 86400 );
		$end = self::LAG_DAYS;

		return array(
			'current'  => array( $day( $end + 27 ), $day( $end ) ),
			'previous' => array( $day( $end + 55 ), $day( $end + 28 ) ),
			'p90'      => array( $day( $end + 89 ), $day( $end ) ),
		);
	}

	/**
	 * Шаги обхода по порядку.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public function steps(): array {
		$steps = array();

		foreach ( $this->sources as $engine => $periods ) {
			foreach ( array_keys( $periods ) as $period ) {
				$steps[] = array( (string) $engine, (string) $period );
			}
		}

		return $steps;
	}

	/**
	 * Начинает новый обход. Строки отключённых поисковиков удаляются сразу.
	 *
	 * @param string $today Сегодня, ГГГГ-ММ-ДД.
	 * @param int    $now   Текущее время.
	 * @return array{today: string, started: int, finished: int, step: int, total: int, done: bool, rows: int, errors: array<string, string>}
	 */
	public function start( string $today, int $now ): array {
		$this->store->keep_engines( array_map( 'strval', array_keys( $this->sources ) ) );

		return array(
			'today'    => $today,
			'started'  => $now,
			'finished' => 0,
			'step'     => 0,
			'total'    => count( $this->steps() ),
			'done'     => array() === $this->steps(),
			'rows'     => 0,
			'errors'   => array(),
		);
	}

	/**
	 * Выполняет очередные шаги обхода.
	 *
	 * @param array<string, mixed> $state     Состояние из start() или прошлого запуска.
	 * @param int                  $now       Текущее время.
	 * @param int                  $max_steps Сколько шагов выполнить.
	 * @return array<string, mixed> Новое состояние.
	 */
	public function run( array $state, int $now, int $max_steps = 1 ): array {
		$steps   = $this->steps();
		$periods = self::periods( (string) $state['today'] );
		$done    = 0;
		$total   = count( $steps );

		while ( $done < $max_steps && (int) $state['step'] < $total ) {
			list( $engine, $period ) = $steps[ (int) $state['step'] ];
			$state['step']           = (int) $state['step'] + 1;

			if ( isset( $state['errors'][ $engine ] ) ) {
				continue;
			}

			++$done;

			try {
				$range = $periods[ $period ] ?? $periods['current'];
				$rows  = ( $this->sources[ $engine ][ $period ] )( $range[0], $range[1] );
			} catch ( SearchDataException $error ) {
				$state['errors'][ $engine ] = $error->getMessage();
				continue;
			}

			$rows = $this->sync->merge( array(), $rows, $engine, $period );

			if ( 'p90' !== $period ) {
				$rows = $this->sync->top_per_page( $rows, self::TOP_PER_PAGE );
			}

			$this->store->replace( $engine, $period, $this->matched( $rows ), $now );
			$state['rows'] = (int) $state['rows'] + count( $rows );
		}

		if ( (int) $state['step'] >= $total ) {
			$state['done']     = true;
			$state['finished'] = $now;
			$this->store->remove_older( $now - self::KEEP_DAYS * 86400 );
		}

		return $state;
	}

	/**
	 * Дополняет строки объектом сайта: запись, рубрика, автор, главная. Строки без страницы
	 * (у Яндекса популярные запросы считаются по сайту целиком) относятся ко всему сайту.
	 *
	 * @param list<array<string, mixed>> $rows Строки.
	 * @return list<array<string, mixed>>
	 */
	private function matched( array $rows ): array {
		$cache = array();

		foreach ( $rows as $index => $row ) {
			$page = (string) $row['page'];

			if ( '' === $page ) {
				$cache[ $page ] = array(
					'type' => 'site',
					'id'   => 0,
				);
			}

			$cache[ $page ] ??= $this->matcher->match( $page );

			$rows[ $index ]['object_type'] = $cache[ $page ]['type'];
			$rows[ $index ]['object_id']   = $cache[ $page ]['id'];
		}

		return $rows;
	}
}
