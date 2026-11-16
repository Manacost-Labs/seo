<?php
/**
 * Разбор списка запросов к базе.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Doctor;

/**
 * Считает, сколько запросов к базе на странице сделал плагин и сколько времени они заняли.
 *
 * При включённой константе SAVEQUERIES WordPress хранит для каждого запроса его текст, время и цепочку вызовов.
 * Запрос относится к плагину, если в цепочке есть класс или функция из пространства имён IndexPilot.
 */
final class QueryLog {

	/**
	 * Предел: столько запросов плагин может добавить на страницу записи или рубрики.
	 */
	public const LIMIT = 4;

	private const MARKER = 'IndexPilot\\';

	/**
	 * Запросы самого замера в счёт не идут.
	 */
	private const OWN = 'IndexPilot\\Doctor\\';

	/**
	 * Работа, которую WordPress делает на каждой странице и без плагина: узнаёт язык сайта и текущего пользователя.
	 * Если плагин лишь первым её вызвал (например, переводом строки), запрос плагину не засчитывается.
	 */
	private const CORE_WORK = array( 'determine_locale', 'wp_get_current_user' );

	private const SQL_LENGTH = 300;

	/**
	 * Сводка по списку запросов.
	 *
	 * @param array<int, mixed> $queries Список из $wpdb->queries: текст запроса, время в секундах, цепочка вызовов.
	 * @return array{total: int, total_ms: float, plugin: int, plugin_ms: float, queries: list<array{sql: string, ms: float, caller: string}>}
	 */
	public static function summarize( array $queries ): array {
		$summary = array(
			'total'     => 0,
			'total_ms'  => 0.0,
			'plugin'    => 0,
			'plugin_ms' => 0.0,
			'queries'   => array(),
		);

		foreach ( $queries as $query ) {
			if ( ! is_array( $query ) || ! isset( $query[0] ) ) {
				continue;
			}

			$trace = (string) ( $query[2] ?? '' );

			if ( str_contains( $trace, self::OWN ) ) {
				continue;
			}

			$seconds = (float) ( $query[1] ?? 0 );
			$caller  = self::caller( $trace );

			++$summary['total'];
			$summary['total_ms'] += $seconds * 1000;

			if ( '' === $caller ) {
				continue;
			}

			++$summary['plugin'];
			$summary['plugin_ms'] += $seconds * 1000;
			$summary['queries'][]  = array(
				'sql'    => mb_substr( trim( (string) preg_replace( '/\s+/', ' ', (string) $query[0] ) ), 0, self::SQL_LENGTH ),
				'ms'     => round( $seconds * 1000, 2 ),
				'caller' => $caller,
			);
		}

		$summary['total_ms']  = round( $summary['total_ms'], 2 );
		$summary['plugin_ms'] = round( $summary['plugin_ms'], 2 );

		return $summary;
	}

	/**
	 * Метод плагина, из которого сделан запрос: последний вызов плагина в цепочке. Пустая строка — запрос не плагина
	 * либо общая работа WordPress, которую плагин только вызвал первым.
	 *
	 * @param string $trace Цепочка вызовов через запятую, как её пишет WordPress.
	 */
	public static function caller( string $trace ): string {
		$caller = '';

		foreach ( explode( ', ', $trace ) as $frame ) {
			// Имя начинается с пространства имён плагина; у безымянных функций оно стоит после «{closure:».
			if ( 1 === preg_match( '/(?:^|[{:>])' . preg_quote( self::MARKER, '/' ) . '/', $frame ) ) {
				$caller = $frame;
			} elseif ( '' !== $caller && in_array( $frame, self::CORE_WORK, true ) ) {
				// После вызова плагина началась общая работа WordPress: дальше запрос уже не его.
				$caller = '';
			}
		}

		return $caller;
	}

	/**
	 * Укладывается ли число запросов плагина в предел.
	 *
	 * @param int $count Число запросов плагина.
	 * @param int $limit Предел.
	 */
	public static function within_limit( int $count, int $limit = self::LIMIT ): bool {
		return $count <= $limit;
	}
}
