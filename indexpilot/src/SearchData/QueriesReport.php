<?php
/**
 * Отчёт по запросам: что улучшить по данным поиска.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Считает разделы отчёта «Запросы» по строкам таблицы indexpilot_queries:
 * страницы на позициях 5–15, страницы, теряющие клики, один запрос — две страницы
 * и запросы с показами, под которые на сайте нет подходящей страницы.
 * Фразы сравниваются через словарь игры: «дека приста» и «колода жреца» — один запрос.
 */
final class QueriesReport {

	public const NEAR_FROM = 5.0;
	public const NEAR_TO   = 15.0;

	/**
	 * С какого числа показов запрос интересен.
	 */
	public const MIN_IMPRESSIONS = 10;

	/**
	 * С какого числа показов запрос без страницы становится темой для материала.
	 */
	public const TOPIC_IMPRESSIONS = 20;

	/**
	 * Нормализация фразы.
	 *
	 * @var callable(string): string
	 */
	private $normalize;

	/**
	 * Конструктор.
	 *
	 * @param callable(string): string $normalize Фраза => ключ сравнения (словарь игры, основы слов).
	 */
	public function __construct( callable $normalize ) {
		$this->normalize = $normalize;
	}

	/**
	 * Страницы, которым немного не хватает до первой пятёрки.
	 *
	 * @param list<array<string, mixed>> $rows  Строки таблицы.
	 * @param int                        $limit Сколько страниц.
	 * @return list<array<string, mixed>>
	 */
	public function near_top( array $rows, int $limit = 50 ): array {
		$pages = array();

		foreach ( $this->current_pages( $rows ) as $row ) {
			$position = (float) $row['position'];

			if ( $position < self::NEAR_FROM || $position > self::NEAR_TO || (int) $row['impressions'] < self::MIN_IMPRESSIONS ) {
				continue;
			}

			$key = (string) $row['page'];

			if ( ! isset( $pages[ $key ] ) ) {
				$pages[ $key ] = self::page( $row ) + array(
					'query'       => (string) $row['query'],
					'position'    => $position,
					'impressions' => 0,
					'clicks'      => 0,
					'best'        => -1,
				);
			}

			$pages[ $key ]['impressions'] += (int) $row['impressions'];
			$pages[ $key ]['clicks']      += (int) $row['clicks'];

			if ( (int) $row['impressions'] > $pages[ $key ]['best'] ) {
				$pages[ $key ]['best']     = (int) $row['impressions'];
				$pages[ $key ]['query']    = (string) $row['query'];
				$pages[ $key ]['position'] = $position;
			}
		}

		$pages = array_map(
			static function ( array $page ): array {
				unset( $page['best'] );
				return $page;
			},
			array_values( $pages )
		);

		return self::top( $pages, 'impressions', $limit );
	}

	/**
	 * Страницы, у которых кликов стало заметно меньше, чем в прошлом периоде.
	 *
	 * @param list<array<string, mixed>> $rows  Строки таблицы.
	 * @param int                        $limit Сколько страниц.
	 * @return list<array<string, mixed>>
	 */
	public function losing( array $rows, int $limit = 50 ): array {
		$pages = array();

		foreach ( $rows as $row ) {
			if ( 'google' !== $row['engine'] || '' === (string) $row['page'] || ! in_array( $row['period'], array( 'current', 'previous' ), true ) ) {
				continue;
			}

			$key            = (string) $row['page'];
			$pages[ $key ] ??= self::page( $row ) + array(
				'before' => 0,
				'after'  => 0,
			);

			$pages[ $key ][ 'current' === $row['period'] ? 'after' : 'before' ] += (int) $row['clicks'];
		}

		$losing = array();

		foreach ( $pages as $page ) {
			if ( $page['before'] >= 10 && $page['after'] <= $page['before'] * 0.7 ) {
				$page['change'] = $page['after'] - $page['before'];
				$losing[]       = $page;
			}
		}

		usort( $losing, static fn( array $a, array $b ): int => $a['change'] <=> $b['change'] );

		return array_slice( $losing, 0, $limit );
	}

	/**
	 * Один запрос — несколько страниц сайта: страницы мешают друг другу в выдаче.
	 *
	 * @param list<array<string, mixed>> $rows  Строки таблицы.
	 * @param int                        $limit Сколько запросов.
	 * @return list<array{query: string, impressions: int, pages: list<array<string, mixed>>}>
	 */
	public function cannibal( array $rows, int $limit = 50 ): array {
		$groups = array();

		foreach ( $this->current_pages( $rows ) as $row ) {
			if ( (int) $row['impressions'] < 5 ) {
				continue;
			}

			$key = ( $this->normalize )( (string) $row['query'] );

			if ( '' === $key ) {
				continue;
			}

			$groups[ $key ][ (string) $row['page'] ] = self::page( $row ) + array(
				'query'       => (string) $row['query'],
				'position'    => (float) $row['position'],
				'impressions' => (int) $row['impressions'] + (int) ( $groups[ $key ][ (string) $row['page'] ]['impressions'] ?? 0 ),
				'clicks'      => (int) $row['clicks'],
			);
		}

		$pairs = array();

		foreach ( $groups as $pages ) {
			if ( count( $pages ) < 2 ) {
				continue;
			}

			$pages = self::top( array_values( $pages ), 'impressions', 10 );

			$pairs[] = array(
				'query'       => (string) $pages[0]['query'],
				'impressions' => array_sum( array_column( $pages, 'impressions' ) ),
				'pages'       => $pages,
			);
		}

		return self::top( $pairs, 'impressions', $limit );
	}

	/**
	 * Запросы с показами, под которые на сайте нет подходящей страницы: темы для новых материалов.
	 * Подходящей страницы нет, если запрос пришёл без страницы (Яндекс считает по сайту), показал главную
	 * или страницу ниже 20-го места, и ни заголовок, ни основной ключ ни одной записи его не содержат.
	 *
	 * @param list<array<string, mixed>> $rows     Строки таблицы.
	 * @param callable(string): bool     $has_page Есть ли на сайте страница под запрос.
	 * @param int                        $limit    Сколько запросов.
	 * @return list<array{query: string, impressions: int, clicks: int, engines: list<string>}>
	 */
	public function without_page( array $rows, callable $has_page, int $limit = 50 ): array {
		$topics = array();
		$served = array();

		foreach ( $rows as $row ) {
			if ( 'current' !== $row['period'] ) {
				continue;
			}

			$key = ( $this->normalize )( (string) $row['query'] );

			if ( '' === $key ) {
				continue;
			}

			$weak = '' === (string) $row['page'] || (float) $row['position'] > 20 || in_array( $row['object_type'], array( 'unknown', 'home', 'site' ), true );

			if ( ! $weak ) {
				$served[ $key ] = true;
				continue;
			}

			if ( ! isset( $topics[ $key ] ) ) {
				$topics[ $key ] = array(
					'query'       => (string) $row['query'],
					'impressions' => 0,
					'clicks'      => 0,
					'engines'     => array(),
				);
			}

			$topics[ $key ]['impressions'] += (int) $row['impressions'];
			$topics[ $key ]['clicks']      += (int) $row['clicks'];
			$topics[ $key ]['engines'][]    = (string) $row['engine'];
		}

		$result = array();

		foreach ( $topics as $key => $topic ) {
			if ( isset( $served[ $key ] ) || $topic['impressions'] < self::TOPIC_IMPRESSIONS || $has_page( $topic['query'] ) ) {
				continue;
			}

			$topic['engines'] = array_values( array_unique( $topic['engines'] ) );
			$result[]         = $topic;
		}

		return self::top( $result, 'impressions', $limit );
	}

	/**
	 * Строки Google за последние 28 дней со страницей.
	 *
	 * @param list<array<string, mixed>> $rows Строки.
	 * @return list<array<string, mixed>>
	 */
	private function current_pages( array $rows ): array {
		return array_values( array_filter( $rows, static fn( array $row ): bool => 'google' === $row['engine'] && 'current' === $row['period'] && '' !== (string) $row['page'] ) );
	}

	/**
	 * Страница строки.
	 *
	 * @param array<string, mixed> $row Строка.
	 * @return array{page: string, object_type: string, object_id: int}
	 */
	private static function page( array $row ): array {
		return array(
			'page'        => (string) $row['page'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
		);
	}

	/**
	 * Первые по убыванию поля.
	 *
	 * @param list<array<string, mixed>> $items Строки.
	 * @param string                     $field Поле.
	 * @param int                        $limit Сколько.
	 * @return list<array<string, mixed>>
	 */
	private static function top( array $items, string $field, int $limit ): array {
		usort( $items, static fn( array $a, array $b ): int => $b[ $field ] <=> $a[ $field ] );

		return array_slice( $items, 0, $limit );
	}
}
