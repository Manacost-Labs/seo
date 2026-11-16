<?php
/**
 * Проверка индексации важных страниц.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Разбирает ответ Google URL Inspection API в понятную причину и выбирает, какие страницы проверить сегодня.
 * У Google дневной предел запросов, поэтому проверяются только важные страницы: опорные материалы,
 * страницы классов и свежие записи. Проиндексированная страница перепроверяется раз в две недели,
 * непроиндексированная — раз в сутки.
 */
final class Inspection {

	public const RECHECK_INDEXED = 14 * 86400;
	public const RECHECK_MISSING = 86400;

	/**
	 * Состояния Google => причина по-русски и что сделать.
	 */
	private const REASONS = array(
		'Crawled - currently not indexed'           => 'Google просканировал, но не добавил страницу в индекс: счёл её недостаточно полезной или похожей на другие. Дополните текст, поставьте на неё ссылки с опорных материалов.',
		'Discovered - currently not indexed'        => 'Google знает адрес, но ещё не сканировал страницу: обычно не хватает ссылок на неё или сайт отвечает медленно.',
		'URL is unknown to Google'                  => 'Google не знает этот адрес: проверьте, что он есть в карте сайта и на него ведут ссылки.',
		"Excluded by 'noindex' tag"                 => 'Страница закрыта от индексации тегом noindex.',
		'Excluded by ‘noindex’ tag'                 => 'Страница закрыта от индексации тегом noindex.',
		'Blocked by robots.txt'                     => 'Страница закрыта в robots.txt.',
		'Duplicate, Google chose different canonical than user' => 'Google считает страницу копией другой и показывает ту, другую. Сделайте текст непохожим или поставьте редирект.',
		'Duplicate without user-selected canonical' => 'Google считает страницу копией другой: укажите канонический адрес или объедините страницы.',
		'Alternate page with proper canonical tag'  => 'Каноническим адресом страницы указан другой адрес — так и задумано, если это копия.',
		'Page with redirect'                        => 'С адреса стоит перенаправление.',
		'Not found (404)'                           => 'Страница отвечает «не найдено» (404).',
		'Soft 404'                                  => 'Google считает страницу пустой («мягкая» 404): добавьте содержимое.',
		'Server error (5xx)'                        => 'Сервер ответил ошибкой при сканировании.',
		'Blocked due to access forbidden (403)'     => 'Сервер запретил Google доступ к странице (403).',
	);

	/**
	 * Понятный результат проверки.
	 *
	 * @param array<string, mixed> $response Ответ API.
	 * @return array{indexed: bool, verdict: string, coverage: string, reason: string}
	 */
	public static function interpret( array $response ): array {
		$status   = (array) ( $response['inspectionResult']['indexStatusResult'] ?? array() );
		$verdict  = (string) ( $status['verdict'] ?? '' );
		$coverage = (string) ( $status['coverageState'] ?? '' );
		$indexed  = 'PASS' === $verdict || ( str_contains( strtolower( $coverage ), 'indexed' ) && ! str_contains( strtolower( $coverage ), 'not indexed' ) );

		$reason = '';

		if ( ! $indexed ) {
			$reason = self::REASONS[ $coverage ] ?? ( 'Страница не в индексе Google. Состояние: ' . ( '' !== $coverage ? $coverage : $verdict ) );
		}

		return array(
			'indexed'  => $indexed,
			'verdict'  => $verdict,
			'coverage' => $coverage,
			'reason'   => $reason,
		);
	}

	/**
	 * Адреса для проверки сегодня: сначала ни разу не проверенные (важные раньше), затем те, кому подошёл срок.
	 *
	 * @param array<string, int>                                $candidates Адрес => важность (больше — важнее).
	 * @param array<string, array{checked: int, indexed: bool}> $checked    Прошлые результаты.
	 * @param int                                               $now        Текущее время.
	 * @param int                                               $limit      Сколько адресов.
	 * @return list<string>
	 */
	public static function pick( array $candidates, array $checked, int $now, int $limit ): array {
		$fresh = array();
		$due   = array();

		foreach ( $candidates as $url => $priority ) {
			$url = (string) $url;

			if ( ! isset( $checked[ $url ] ) ) {
				$fresh[ $url ] = (int) $priority;
				continue;
			}

			$wait = ! empty( $checked[ $url ]['indexed'] ) ? self::RECHECK_INDEXED : self::RECHECK_MISSING;

			if ( (int) $checked[ $url ]['checked'] <= $now - $wait ) {
				$due[ $url ] = (int) $checked[ $url ]['checked'];
			}
		}

		arsort( $fresh );
		asort( $due );

		return array_slice( array_map( 'strval', array_merge( array_keys( $fresh ), array_keys( $due ) ) ), 0, max( 0, $limit ) );
	}
}
