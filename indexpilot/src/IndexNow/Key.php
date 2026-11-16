<?php
/**
 * Ключ сайта для IndexNow.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\IndexNow;

/**
 * Поисковик проверяет, что запрос отправил владелец сайта: по адресу /КЛЮЧ.txt должен лежать этот же ключ.
 * Ключ создаётся один раз и отдаётся плагином, файл на диск не пишется.
 */
final class Key {

	public const OPTION = 'indexpilot_indexnow_key';

	/**
	 * Ключ сайта; создаётся при первом обращении.
	 */
	public function value(): string {
		$key = (string) get_option( self::OPTION, '' );

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( self::OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * Отдаёт файл ключа, если запрошен его адрес. Вызывается на хуке parse_request.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- адрес только сравнивается с ожидаемым именем файла.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) strtok( $uri, '?' );

		// Ключ читается из базы, только когда адрес похож на файл ключа: обычные страницы обходятся без запроса.
		if ( 1 !== preg_match( '#/[a-f0-9]{32}\.txt$#', $path ) ) {
			return;
		}

		$key = (string) get_option( self::OPTION, '' );

		if ( '' === $key || (string) wp_parse_url( home_url( '/' . $key . '.txt' ), PHP_URL_PATH ) !== $path ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		echo esc_html( $key );
		exit;
	}
}
