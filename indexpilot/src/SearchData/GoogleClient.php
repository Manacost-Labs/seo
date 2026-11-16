<?php
/**
 * Данные поисковых систем без зависимости от WordPress.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
/** Чистый слой: стандартные PHP-функции, сообщения экранирует интерфейс. */
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.Security.EscapeOutput.ExceptionNotEscaped
namespace IndexPilot\SearchData;

final class GoogleClient {
	private array $key;
	private Http $http;
	private $now;
	private string $access_token = '';
	private int $expires         = 0;
	public function __construct( string $service_account_json, Http $http, callable $now ) {
		$key = json_decode( $service_account_json, true );
		if ( ! is_array( $key ) || 'service_account' !== ( $key['type'] ?? '' ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) || false === openssl_pkey_get_private( $key['private_key'] ) ) {
			throw new SearchDataException( 'Файл ключа не похож на ключ служебной учётной записи Google' );}
		$this->key  = $key;
		$this->http = $http;
		$this->now  = $now;
	}
	public function token(): string {
		$now = (int) ( $this->now )();
		if ( $now < $this->expires - 60 ) {
			return $this->access_token;}
		$encode = static fn( $value )=>rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
		$jwt    = $encode(
			json_encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		) . '.' . $encode(
			json_encode(
				array(
					'iss'   => $this->key['client_email'],
					'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
					'aud'   => 'https://oauth2.googleapis.com/token',
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		if ( ! openssl_sign( $jwt, $signature, $this->key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			throw new SearchDataException( 'Не удалось подписать запрос Google' );}
		$data = $this->send(
			'https://oauth2.googleapis.com/token',
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt . '.' . $encode( $signature ),
				)
			)
		);
		if ( empty( $data['access_token'] ) || ! is_numeric( $data['expires_in'] ?? null ) ) {
			throw new SearchDataException( 'Google вернул некорректный токен' );}
		$this->access_token = $data['access_token'];
		$this->expires      = $now + (int) $data['expires_in'];
		return $this->access_token;
	}
	private function send( string $url, array $headers, string $body ): array {
		$response = $this->http->request( 'POST', $url, $headers, $body );
		if ( in_array( $response['code'], array( 401, 403 ), true ) ) {
			throw new SearchDataException( 'У служебной учётной записи нет доступа к сайту в Search Console: добавьте её адрес ' . $this->key['client_email'] . ' как пользователя' );}
		if ( 429 === $response['code'] ) {
			throw new SearchDataException( 'Превышен лимит запросов Google, повторим позже' );}
		$data = json_decode( $response['body'], true );
		if ( 200 > $response['code'] || 300 <= $response['code'] || ! is_array( $data ) ) {
			throw new SearchDataException( 'Не удалось получить данные Google' );}
		return $data;
	}
	/**
	 * Проверка адреса в индексе Google (URL Inspection API).
	 *
	 * @param string $site_url Ресурс в Search Console.
	 * @param string $url      Проверяемый адрес.
	 * @return array<string, mixed> Ответ API.
	 */
	public function inspect( string $site_url, string $url ): array {
		return $this->send(
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'Authorization' => 'Bearer ' . $this->token(),
				'Content-Type'  => 'application/json',
			),
			(string) json_encode(
				array(
					'inspectionUrl' => $url,
					'siteUrl'       => $site_url,
					'languageCode'  => 'ru',
				)
			)
		);
	}
	/**
	 * Адрес служебной учётной записи.
	 */
	public function email(): string {
		return (string) $this->key['client_email'];
	}
	public function query( string $site_url, string $start, string $end, array $dimensions = array( 'page', 'query' ), int $row_limit = 25000, int $start_row = 0 ): array {
		$data   = $this->send(
			'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query',
			array(
				'Authorization' => 'Bearer ' . $this->token(),
				'Content-Type'  => 'application/json',
			),
			json_encode(
				array(
					'startDate'  => $start,
					'endDate'    => $end,
					'dimensions' => $dimensions,
					'rowLimit'   => $row_limit,
					'startRow'   => $start_row,
				)
			)
		);
		$result = array();
		foreach ( $data['rows'] ?? array() as $row ) {
			$keys     = array_combine( $dimensions, $row['keys'] );
			$result[] = array(
				'page'        => $keys['page'] ?? '',
				'query'       => $keys['query'] ?? '',
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
			);}
		return $result;
	}
}
