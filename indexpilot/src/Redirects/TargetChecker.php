<?php
/**
 * Проверка целей редиректов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Запрашивает адрес цели и запоминает код ответа и следующий переход, если он есть.
 * Так находятся цепочки через другой домен и цели, которые больше не открываются.
 *
 * Проверка идёт пачками по кнопке на странице отчёта, а не при обычной загрузке страниц.
 */
final class TargetChecker {

	public const OPTION = 'indexpilot_redirect_targets';

	private const TTL = WEEK_IN_SECONDS;

	/**
	 * Результаты проверок.
	 *
	 * @var array<string, array{status: int, location: string, checked: int}>|null
	 */
	private ?array $results = null;

	/**
	 * Все сохранённые результаты: адрес => код ответа, следующий адрес, время проверки.
	 *
	 * @return array<string, array{status: int, location: string, checked: int}>
	 */
	public function results(): array {
		if ( null === $this->results ) {
			$saved         = get_option( self::OPTION, array() );
			$this->results = is_array( $saved ) ? $saved : array();
		}

		return $this->results;
	}

	/**
	 * Куда перенаправляет адрес по результатам проверки; пустая строка, если не перенаправляет или не проверялся.
	 *
	 * @param string $url Полный адрес.
	 */
	public function next_location( string $url ): string {
		return (string) ( $this->results()[ $url ]['location'] ?? '' );
	}

	/**
	 * Код ответа адреса: null — не проверялся, 0 — не ответил.
	 *
	 * @param string $url Полный адрес.
	 */
	public function status( string $url ): ?int {
		$result = $this->results()[ $url ] ?? null;

		return null === $result ? null : (int) $result['status'];
	}

	/**
	 * Адреса из списка, которые не проверялись или проверялись давно.
	 *
	 * @param list<string> $urls Полные адреса.
	 * @return list<string>
	 */
	public function pending( array $urls ): array {
		$results = $this->results();
		$pending = array();

		foreach ( array_unique( $urls ) as $url ) {
			if ( ! isset( $results[ $url ] ) || time() - (int) $results[ $url ]['checked'] > self::TTL ) {
				$pending[] = $url;
			}
		}

		return $pending;
	}

	/**
	 * Проверяет несколько адресов и сохраняет результаты.
	 *
	 * @param list<string> $urls  Полные адреса.
	 * @param int          $limit Сколько адресов проверить за вызов.
	 * @return int Сколько адресов проверено.
	 */
	public function check( array $urls, int $limit = 5 ): int {
		$results = $this->results();
		$checked = 0;

		foreach ( array_slice( $urls, 0, $limit ) as $url ) {
			$results[ $url ] = $this->request( $url ) + array( 'checked' => time() );
			++$checked;
		}

		if ( $checked > 0 ) {
			$this->results = $results;
			update_option( self::OPTION, $results, false );
		}

		return $checked;
	}

	/**
	 * Запрашивает адрес без следования редиректам.
	 *
	 * @param string $url Полный адрес.
	 * @return array{status: int, location: string}
	 */
	private function request( string $url ): array {
		$args = array(
			'redirection' => 0,
			'timeout'     => 5,
		);

		// wp_safe_remote_* не обращается к внутренним адресам сети.
		$response = wp_safe_remote_head( $url, $args );

		// Часть серверов не отвечает на HEAD — повторяем обычным запросом с малым объёмом ответа.
		if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 403, 405, 501 ), true ) ) {
			$response = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 1024 ) );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 0,
				'location' => '',
			);
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$location = $status >= 300 && $status < 400 ? (string) wp_remote_retrieve_header( $response, 'location' ) : '';

		return array(
			'status'   => $status,
			'location' => '' !== $location ? \WP_Http::make_absolute_url( $location, $url ) : '',
		);
	}
}
