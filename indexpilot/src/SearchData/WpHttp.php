<?php
/**
 * Сеть WordPress для клиентов поисковиков.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Запросы к Google и Яндексу через wp_safe_remote_request: не ходит во внутреннюю сеть,
 * подменяется фильтром pre_http_request в тестах.
 */
final class WpHttp implements Http {

	/**
	 * Конструктор.
	 *
	 * @param int $timeout Сколько ждать ответа, секунд.
	 */
	public function __construct( private int $timeout = 20 ) {
	}

	public function request( string $method, string $url, array $headers, string $body ): array {
		$args = array(
			'method'     => $method,
			'headers'    => $headers,
			'timeout'    => $this->timeout,
			'user-agent' => 'IndexPilot/' . \IndexPilot\Plugin::VERSION . '; WordPress',
		);

		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code' => 0,
				'body' => '',
			);
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
