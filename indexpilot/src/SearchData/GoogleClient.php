<?php
/**
 * Р”Р°РЅРЅС‹Рµ РїРѕРёСЃРєРѕРІС‹С… СЃРёСЃС‚РµРј Р±РµР· Р·Р°РІРёСЃРёРјРѕСЃС‚Рё РѕС‚ WordPress.
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
			throw new SearchDataException( 'Р¤Р°Р№Р» РєР»СЋС‡Р° РЅРµ РїРѕС…РѕР¶ РЅР° РєР»СЋС‡ СЃР»СѓР¶РµР±РЅРѕР№ СѓС‡С‘С‚РЅРѕР№ Р·Р°РїРёСЃРё Google' );}
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
			throw new SearchDataException( 'РќРµ СѓРґР°Р»РѕСЃСЊ РїРѕРґРїРёСЃР°С‚СЊ Р·Р°РїСЂРѕСЃ Google' );}
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
			throw new SearchDataException( 'Google РІРµСЂРЅСѓР» РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ С‚РѕРєРµРЅ' );}
		$this->access_token = $data['access_token'];
		$this->expires      = $now + (int) $data['expires_in'];
		return $this->access_token;
	}
	private function send( string $url, array $headers, string $body ): array {
		$response = $this->http->request( 'POST', $url, $headers, $body );
		if ( in_array( $response['code'], array( 401, 403 ), true ) ) {
			throw new SearchDataException( 'РЈ СЃР»СѓР¶РµР±РЅРѕР№ СѓС‡С‘С‚РЅРѕР№ Р·Р°РїРёСЃРё РЅРµС‚ РґРѕСЃС‚СѓРїР° Рє СЃР°Р№С‚Сѓ РІ Search Console: РґРѕР±Р°РІСЊС‚Рµ РµС‘ Р°РґСЂРµСЃ ' . $this->key['client_email'] . ' РєР°Рє РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ' );}
		if ( 429 === $response['code'] ) {
			throw new SearchDataException( 'РџСЂРµРІС‹С€РµРЅ Р»РёРјРёС‚ Р·Р°РїСЂРѕСЃРѕРІ Google, РїРѕРІС‚РѕСЂРёРј РїРѕР·Р¶Рµ' );}
		$data = json_decode( $response['body'], true );
		if ( 200 > $response['code'] || 300 <= $response['code'] || ! is_array( $data ) ) {
			throw new SearchDataException( 'РќРµ СѓРґР°Р»РѕСЃСЊ РїРѕР»СѓС‡РёС‚СЊ РґР°РЅРЅС‹Рµ Google' );}
		return $data;
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
