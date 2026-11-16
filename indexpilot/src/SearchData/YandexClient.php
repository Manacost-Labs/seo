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

final class YandexClient {
	private string $token;
	private Http $http;
	private ?string $uid = null;
	public function __construct( string $token, Http $http ) {
		$this->token = $token;
		$this->http  = $http;}
	private function get( string $path ): array {
		$response = $this->http->request( 'GET', 'https://api.webmaster.yandex.net/v4/user' . $path, array( 'Authorization' => 'OAuth ' . $this->token ), '' );
		if ( in_array( $response['code'], array( 401, 403 ), true ) ) {
			throw new SearchDataException( 'Нет доступа к Яндекс Вебмастеру: проверьте токен и права на сайт' );}
		if ( 429 === $response['code'] ) {
			throw new SearchDataException( 'Превышен лимит запросов Яндекса, повторим позже' );}
		$data = json_decode( $response['body'], true );
		if ( 200 > $response['code'] || 300 <= $response['code'] || ! is_array( $data ) ) {
			throw new SearchDataException( 'Не удалось получить данные Яндекс Вебмастера' );}
		return $data;
	}
	public function user_id(): string {
		if ( null === $this->uid ) {
			$data = $this->get( '' );
			if ( ! isset( $data['user_id'] ) ) {
				throw new SearchDataException( 'Яндекс не вернул пользователя' );
			}$this->uid = (string) $data['user_id'];}
		return $this->uid;
	}
	public function host_id( string $site_url ): string {
		$normalize = static fn( $url )=>preg_replace( '/^www\./', '', strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) );
		$hosts     = $this->get( '/' . rawurlencode( $this->user_id() ) . '/hosts' );
		foreach ( $hosts['hosts'] ?? array() as $host ) {
			if ( '' !== $normalize( $site_url ) && $normalize( $site_url ) === $normalize( $host['unicode_host_url'] ?? $host['ascii_host_url'] ?? '' ) ) {
				return $host['host_id'];}
		}
		throw new SearchDataException( 'Сайт не найден в Яндекс Вебмастере' );
	}
	public function popular_queries( string $host_id, string $start, string $end, int $limit = 500 ): array {
		$data   = $this->get(
			'/' . rawurlencode( $this->user_id() ) . '/hosts/' . rawurlencode( $host_id ) . '/search-queries/popular?order_by=TOTAL_SHOWS&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS&query_indicator=AVG_SHOW_POSITION&' . http_build_query(
				array(
					'date_from' => $start,
					'date_to'   => $end,
					'limit'     => $limit,
				)
			)
		);
		$result = array();
		foreach ( $data['queries'] ?? array() as $row ) {
			$i        = $row['indicators'] ?? array();
			$result[] = array(
				'page'        => '',
				'query'       => $row['query_text'],
				'clicks'      => (int) ( $i['TOTAL_CLICKS'] ?? 0 ),
				'impressions' => (int) ( $i['TOTAL_SHOWS'] ?? 0 ),
				'position'    => (float) ( $i['AVG_SHOW_POSITION'] ?? 0 ),
			);}
		return $result;
	}
}
