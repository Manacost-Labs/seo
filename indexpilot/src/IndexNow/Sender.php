<?php
/**
 * Отправка адресов по протоколу IndexNow.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\IndexNow;

/**
 * Один запрос сообщает об изменённых страницах всем поисковикам, поддерживающим IndexNow (Яндекс, Bing и другие).
 */
final class Sender {

	public const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Код ответа последней отправки; 0 — ответа не было.
	 *
	 * @var int
	 */
	private int $last_code = 0;

	/**
	 * Код ответа последней отправки.
	 */
	public function last_code(): int {
		return $this->last_code;
	}

	/**
	 * Тело запроса.
	 *
	 * @param string       $home Адрес главной страницы сайта.
	 * @param string       $key  Ключ сайта.
	 * @param list<string> $urls Адреса страниц; адреса других доменов отбрасываются.
	 * @return array{host: string, key: string, keyLocation: string, urlList: list<string>}
	 */
	public static function payload( string $home, string $key, array $urls ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- метод не зависит от WordPress и проверяется модульными тестами.
		$host = (string) parse_url( $home, PHP_URL_HOST );
		$own  = array();

		foreach ( $urls as $url ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- см. выше.
			if ( strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) ) === strtolower( $host ) ) {
				$own[] = (string) $url;
			}
		}

		return array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => rtrim( $home, '/' ) . '/' . $key . '.txt',
			'urlList'     => $own,
		);
	}

	/**
	 * Отправляет адреса. Возвращает true, если поисковик принял запрос.
	 *
	 * @param string       $home Адрес главной страницы сайта.
	 * @param string       $key  Ключ сайта.
	 * @param list<string> $urls Адреса страниц.
	 */
	public function send( string $home, string $key, array $urls ): bool {
		$payload = self::payload( $home, $key, $urls );

		$this->last_code = 0;

		if ( array() === $payload['urlList'] ) {
			return true;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		$code            = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$this->last_code = $code;

		// 200 — принято, 202 — принято, ключ ещё проверяется.
		return 200 === $code || 202 === $code;
	}
}
