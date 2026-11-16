<?php
/**
 * Список дел для экрана «Обзор».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Overview;

/**
 * Выбирает из готовых цифр аудита, ссылок и редиректов несколько самых важных дел.
 *
 * Важность задаёт вид дела, а не число записей: три битые ссылки важнее четырёх тысяч описаний,
 * собранных автоматически.
 */
final class TaskList {

	public const LIMIT = 5;

	/**
	 * Дела по убыванию важности. Сначала то, без чего остальные цифры неизвестны, затем ошибки, которые видит
	 * посетитель или поисковик, затем улучшения.
	 */
	public const KEYS = array(
		'run_audit',
		'scan_links',
		'broken_links',
		'dead_redirects',
		'frequent_404',
		'dead_outbound',
		'health_problems',
		'duplicate_titles',
		'no_description',
		'redirect_chains',
		'orphans',
		'thin',
		'h1',
		'duplicate_descriptions',
		'auto_description',
		'stale',
	);

	/**
	 * Самые важные дела.
	 *
	 * @param array<string, int> $counts Ключ дела => сколько записей, ссылок или редиректов оно затрагивает.
	 * @param int                $limit  Сколько дел показать.
	 * @return list<array{key: string, count: int}>
	 */
	public static function pick( array $counts, int $limit = self::LIMIT ): array {
		$tasks = array();

		foreach ( self::KEYS as $key ) {
			$count = (int) ( $counts[ $key ] ?? 0 );

			if ( $count > 0 ) {
				$tasks[] = array(
					'key'   => $key,
					'count' => $count,
				);
			}
		}

		return array_slice( $tasks, 0, max( 0, $limit ) );
	}
}
