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
	 * @param float $delay   Пауза между запросами, секунды.
	 * @param int   $timeout Таймаут запроса, секунды.
	 */
	public function __construct( private float $delay = 1.0, private int $timeout = 20 ) {}

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
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 10,
				CURLOPT_TIMEOUT        => $this->timeout,
				CURLOPT_CONNECTTIMEOUT => $this->timeout,
				CURLOPT_USERAGENT      => self::USER_AGENT,
				CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache', 'Pragma: no-cache' ),
				CURLOPT_ENCODING       => '',
			)
		);

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
