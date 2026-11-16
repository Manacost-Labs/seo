<?php
/**
 * Сводка переходов для отчёта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Сводит строки счётчика переходов по площадкам, записям, вариантам блока и дням.
 * Считаются переходы, а не люди: посетителей плагин не различает и не запоминает.
 */
final class Report {

	public const PERIODS        = array( 7, 30, 90 );
	public const DEFAULT_PERIOD = 30;

	/**
	 * Период отчёта в днях: один из разрешённых.
	 *
	 * @param int $days Запрошенное число дней.
	 */
	public static function period( int $days ): int {
		return in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_PERIOD;
	}

	/**
	 * Границы периода, сегодняшний день входит.
	 *
	 * @param int    $days  Число дней.
	 * @param string $today Сегодня, ГГГГ-ММ-ДД.
	 * @return array{from: string, to: string}
	 */
	public static function range( int $days, string $today ): array {
		$from = ( new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) ) )->modify( '-' . ( max( 1, $days ) - 1 ) . ' days' );

		return array(
			'from' => $from->format( 'Y-m-d' ),
			'to'   => $today,
		);
	}

	/**
	 * Всего переходов.
	 *
	 * @param list<array<string, mixed>> $rows Строки счётчика.
	 */
	public static function total( array $rows ): int {
		return (int) array_sum( array_column( $rows, 'hits' ) );
	}

	/**
	 * Переходы по площадкам, от большего к меньшему.
	 *
	 * @param list<array<string, mixed>> $rows Строки счётчика.
	 * @return list<array{destination: string, hits: int}>
	 */
	public static function by_destination( array $rows ): array {
		return self::sorted( self::group( $rows, array( 'destination' ) ) );
	}

	/**
	 * Переходы по записям, от большего к меньшему. Переходы без записи (прямые ссылки) сюда не входят.
	 *
	 * @param list<array<string, mixed>> $rows  Строки счётчика.
	 * @param int                        $limit Сколько записей вернуть; 0 — все.
	 * @return list<array{post_id: int, hits: int}>
	 */
	public static function by_post( array $rows, int $limit = 0 ): array {
		$posts = self::sorted( self::group( array_filter( $rows, static fn( array $row ): bool => $row['post_id'] > 0 ), array( 'post_id' ) ) );

		return $limit > 0 ? array_slice( $posts, 0, $limit ) : $posts;
	}

	/**
	 * Переходы по вариантам текста блоков, от большего к меньшему. У каждого варианта свой счётчик.
	 *
	 * @param list<array<string, mixed>> $rows Строки счётчика.
	 * @return list<array{rule_id: int, variant: int, destination: string, hits: int}>
	 */
	public static function by_variant( array $rows ): array {
		return self::sorted( self::group( array_filter( $rows, static fn( array $row ): bool => $row['rule_id'] > 0 ), array( 'rule_id', 'variant', 'destination' ) ) );
	}

	/**
	 * Переходы по дням, свежие первыми.
	 *
	 * @param list<array<string, mixed>> $rows Строки счётчика.
	 * @return list<array{day: string, hits: int}>
	 */
	public static function by_day( array $rows ): array {
		$days = self::group( $rows, array( 'day' ) );

		usort( $days, static fn( array $a, array $b ): int => strcmp( $b['day'], $a['day'] ) );

		return $days;
	}

	/**
	 * Записи, которые привели больше всего людей на выбранные площадки.
	 *
	 * @param list<array<string, mixed>> $rows  Строки счётчика.
	 * @param list<string>               $codes Коды площадок.
	 * @param int                        $limit Сколько записей на площадку.
	 * @return array<string, list<array{post_id: int, hits: int}>> Площадки без переходов с записей в ответ не попадают.
	 */
	public static function top_posts( array $rows, array $codes, int $limit ): array {
		$result = array();

		foreach ( $codes as $code ) {
			$posts = self::by_post( array_filter( $rows, static fn( array $row ): bool => $code === $row['destination'] ), $limit );

			if ( array() !== $posts ) {
				$result[ $code ] = $posts;
			}
		}

		return $result;
	}

	/**
	 * Складывает переходы строк с одинаковыми значениями полей.
	 *
	 * @param iterable<array<string, mixed>> $rows Строки счётчика.
	 * @param list<string>                   $keys Поля, по которым идёт группировка.
	 * @return list<array<string, mixed>>
	 */
	private static function group( iterable $rows, array $keys ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			$values = array();

			foreach ( $keys as $key ) {
				$values[ $key ] = $row[ $key ];
			}

			$id = implode( '|', $values );

			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = $values + array( 'hits' => 0 );
			}

			$groups[ $id ]['hits'] += (int) $row['hits'];
		}

		return array_values( $groups );
	}

	/**
	 * Сортирует группы по числу переходов, от большего к меньшему; при равенстве порядок появления сохраняется.
	 *
	 * @param list<array<string, mixed>> $groups Группы.
	 * @return list<array<string, mixed>>
	 */
	private static function sorted( array $groups ): array {
		usort( $groups, static fn( array $a, array $b ): int => $b['hits'] <=> $a['hits'] );

		return $groups;
	}
}
