<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\SearchData\Http;

/**
 * Подменённая сеть: ответы Google и Яндекса берутся из файлов-образцов tests/php/SearchData/fixtures.
 * Настоящие ключи и адреса не нужны.
 */
final class FixtureHttp implements Http {

	/**
	 * Сделанные запросы: метод, адрес, тело.
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	public array $calls = array();

	/**
	 * @param string             $home     Адрес сайта, подставляемый вместо {HOME}.
	 * @param array<string, int> $failures Часть адреса => код ответа с ошибкой.
	 */
	public function __construct( private string $home = 'https://example.org', public array $failures = array() ) {
	}

	/**
	 * Файл-образец по адресу запроса.
	 *
	 * @param string $url  Адрес.
	 * @param string $body Тело запроса.
	 */
	public static function fixture( string $url, string $body ): string {
		if ( str_contains( $url, 'oauth2.googleapis.com' ) ) {
			return 'google-token.json';
		}

		if ( str_contains( $url, 'urlInspection' ) ) {
			return str_contains( $body, 'staraya' ) ? 'inspection-not-indexed.json' : 'inspection-indexed.json';
		}

		if ( str_contains( $url, 'searchAnalytics' ) ) {
			return str_contains( $body, '"query"' ) ? 'google-queries.json' : 'google-pages.json';
		}

		if ( str_contains( $url, 'search-queries/popular' ) ) {
			return 'yandex-popular.json';
		}

		return str_ends_with( $url, '/hosts' ) ? 'yandex-hosts.json' : 'yandex-user.json';
	}

	public function request( string $method, string $url, array $headers, string $body ): array {
		$this->calls[] = array( $method, $url, $body );

		foreach ( $this->failures as $part => $code ) {
			if ( str_contains( $url, $part ) ) {
				return array(
					'code' => $code,
					'body' => '',
				);
			}
		}

		$file = dirname( __DIR__ ) . '/SearchData/fixtures/' . self::fixture( $url, $body );

		return array(
			'code' => 200,
			'body' => str_replace( '{HOME}', $this->home, (string) file_get_contents( $file ) ),
		);
	}
}
