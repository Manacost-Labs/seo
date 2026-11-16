<?php
/**
 * Загрузка страниц для сравнения тегов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tools\CompareHead;

// phpcs:disable WordPress.WP.AlternativeFunctions -- инструмент запускается без WordPress, его HTTP-функций здесь нет.

/**
 * Загружает адреса по одному: свой User-Agent, `Cache-Control: no-cache`, таймаут, переход по перенаправлениям.
 * Пауза между запросами бережёт боевой сайт.
 */
final class Fetcher {

	public const USER_AGENT = 'IndexPilot-compare/1.0';

	/**
	 * Время последнего запроса (секунды с плавающей точкой).
	 *
	 * @var float
	 */
	private float $last = 0.0;

	/**
	 * Конструктор.
	 *
	 * @param float  $delay   Пауза между запросами, секунды.
	 * @param int    $timeout Таймаут запроса, секунды.
	 * @param string $auth    Логин и пароль базовой авторизации «user:pass» (закрытая тестовая площадка); пусто — без неё.
	 *                        Значение никуда не выводится и не записывается.
	 */
	public function __construct( private float $delay = 1.0, private int $timeout = 20, private string $auth = '' ) {}

	/**
	 * Параметры curl для одного запроса.
	 *
	 * Логин и пароль curl отправляет только на исходный хост: при перенаправлении на другой сайт они не уходят.
	 *
	 * @param int    $timeout Таймаут запроса, секунды.
	 * @param string $auth    «user:pass» или пусто.
	 * @return array<int, mixed>
	 */
	public static function options( int $timeout, string $auth = '' ): array {
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_USERAGENT      => self::USER_AGENT,
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache', 'Pragma: no-cache' ),
			CURLOPT_ENCODING       => '',
		);

		if ( '' !== $auth && str_contains( $auth, ':' ) ) {
			$options[ CURLOPT_HTTPAUTH ]          = CURLAUTH_BASIC;
			$options[ CURLOPT_USERPWD ]           = $auth;
			$options[ CURLOPT_UNRESTRICTED_AUTH ] = false;
		}

		return $options;
	}

	/**
	 * Загружает адрес.
	 *
	 * @param string $url Адрес.
	 * @return array{status: int, final_url: string, body: string, error: string}
	 */
	public function get( string $url ): array {
		$wait = $this->last + $this->delay - microtime( true );

		if ( $this->last > 0 && $wait > 0 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}

		$curl = curl_init( $url );
		curl_setopt_array( $curl, self::options( $this->timeout, $this->auth ) );

		$body  = curl_exec( $curl );
		$error = curl_error( $curl );

		$result = array(
			'status'    => (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ),
			'final_url' => (string) curl_getinfo( $curl, CURLINFO_EFFECTIVE_URL ),
			'body'      => is_string( $body ) ? $body : '',
			'error'     => $error,
		);

		$this->last = microtime( true );

		return $result;
	}
}
