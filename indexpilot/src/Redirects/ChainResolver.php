<?php
/**
 * Поиск конечного адреса редиректа.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Проходит по редиректам до конечного адреса и находит цепочки и петли.
 *
 * Редиректы этого сайта известны из плагина Redirection. Переходы на других доменах
 * (например, kolodahearthstone.ru → kolodahearthstone.com) сообщает внешняя функция,
 * которая знает результаты проверки целей.
 */
final class ChainResolver {

	/**
	 * Редиректы по ключу исходного адреса.
	 *
	 * @var array<string, Redirect>
	 */
	private array $by_source = array();

	/**
	 * Функция «адрес → куда он перенаправляет» для адресов без редиректа на этом сайте.
	 *
	 * @var callable|null
	 */
	private $external;

	/**
	 * Конструктор.
	 *
	 * @param list<Redirect> $redirects Редиректы этого сайта.
	 * @param string         $home_host Хост этого сайта.
	 * @param callable|null  $external  Функция: адрес => следующий адрес или пустая строка.
	 * @param int            $max_hops  Предел переходов; длиннее — считается петлёй.
	 */
	public function __construct(
		private array $redirects,
		private string $home_host,
		?callable $external = null,
		private int $max_hops = 10
	) {
		$this->home_host = self::bare_host( $home_host );
		$this->external  = $external;

		foreach ( $redirects as $redirect ) {
			$this->by_source[ $this->key( $redirect->source ) ] = $redirect;
		}
	}

	/**
	 * Проходит по редиректам от адреса до конечного.
	 *
	 * @param string $url Адрес или путь на этом сайте.
	 * @return array{final: string, hops: list<string>, loop: bool} hops — адреса, на которые по очереди попадает посетитель.
	 */
	public function resolve( string $url ): array {
		$current = $this->absolute( $url );
		$seen    = array( $this->key( $current ) => true );
		$hops    = array();
		$loop    = false;

		while ( true ) {
			$next = $this->next( $current );

			if ( '' === $next ) {
				break;
			}

			$next   = $this->absolute( $next );
			$hops[] = $next;

			if ( isset( $seen[ $this->key( $next ) ] ) || count( $hops ) >= $this->max_hops ) {
				$loop = true;
				break;
			}

			$seen[ $this->key( $next ) ] = true;
			$current                     = $next;
		}

		return array(
			'final' => array() === $hops ? $current : $hops[ count( $hops ) - 1 ],
			'hops'  => $hops,
			'loop'  => $loop,
		);
	}

	/**
	 * Редиректы, которые ведут не сразу на конечный адрес: цепочки и петли.
	 *
	 * @return list<array{redirect: Redirect, final: string, hops: list<string>, loop: bool}>
	 */
	public function problems(): array {
		$problems = array();

		foreach ( $this->redirects as $redirect ) {
			$result = $this->resolve( $redirect->source );

			if ( $result['loop'] || count( $result['hops'] ) > 1 ) {
				$problems[] = array( 'redirect' => $redirect ) + $result;
			}
		}

		return $problems;
	}

	/**
	 * Полный адрес: путь дополняется хостом этого сайта.
	 *
	 * @param string $url Адрес или путь.
	 */
	public function absolute( string $url ): string {
		$url = trim( $url );

		return str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ? 'https://' . $this->home_host . $url : $url;
	}

	/**
	 * Относится ли адрес к этому сайту.
	 *
	 * @param string $url Адрес или путь.
	 */
	public function is_local( string $url ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress и проверяется модульными тестами.
		$host = parse_url( $this->absolute( $url ), PHP_URL_HOST );

		return is_string( $host ) && self::bare_host( $host ) === $this->home_host;
	}

	/**
	 * Куда перенаправляет адрес; пустая строка, если редиректа нет.
	 *
	 * @param string $url Полный адрес.
	 */
	private function next( string $url ): string {
		$redirect = $this->by_source[ $this->key( $url ) ] ?? null;

		if ( null !== $redirect ) {
			return $redirect->target;
		}

		return null !== $this->external ? (string) ( $this->external )( $url ) : '';
	}

	/**
	 * Ключ для сравнения адресов: без протокола, www, параметров, якоря, завершающего слэша и регистра.
	 *
	 * @param string $url Адрес или путь.
	 */
	private function key( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress и проверяется модульными тестами.
		$parts = parse_url( $this->absolute( $url ) );
		$parts = is_array( $parts ) ? $parts : array();
		$host  = self::bare_host( (string) ( $parts['host'] ?? $this->home_host ) );
		$path  = rtrim( rawurldecode( (string) ( $parts['path'] ?? '' ) ), '/' );

		return mb_strtolower( $host . $path );
	}

	/**
	 * Хост в нижнем регистре без www.
	 *
	 * @param string $host Хост.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
