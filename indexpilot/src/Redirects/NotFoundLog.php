<?php
/**
 * Журнал 404: адреса, которые не открылись.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Записывает обращения к несуществующим адресам. Шум (файлы, служебные адреса, попытки взлома)
 * в журнал не попадает; один адрес — одна строка со счётчиком; в журнале не больше LIMIT строк.
 */
final class NotFoundLog {

	public const LIMIT = 5000;

	/**
	 * Журнал урезается при каждой TRIM_EVERY-й новой строке — до LIMIT − TRIM_EVERY строк,
	 * поэтому строк никогда не становится больше LIMIT.
	 */
	public const TRIM_EVERY = 50;

	private const MAX_LENGTH = 255;

	/**
	 * Расширения файлов: обращения к ним — не страницы сайта.
	 */
	private const FILE_EXTENSIONS = 'php|php\d|phtml|asp|aspx|axd|ashx|asmx|cfm|jsp|py|rb|jar|war|cgi|pl|env|ini|cfg|conf|config|toml|properties|tfstate|tfvars|lock|pem|key|crt|db|sqlite|sql|bak|old|orig|swp|zip|gz|tgz|tar|rar|7z|log|txt|xml|json|yml|yaml|sh|exe|dll|png|jpe?g|gif|webp|avif|svg|ico|bmp|tiff?|css|js|mjs|map|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf';

	/**
	 * Служебные адреса и типичные цели сканеров уязвимостей: целая часть адреса, а не кусок ярлыка статьи.
	 */
	private const NOISE_PATHS = '#/\.|/__|^/api(/|$)|(^|/)(wp-admin|wp-login|wp-includes|wp-content|wp-json|xmlrpc|cgi-bin|phpmyadmin|pma|myadmin|vendor|node_modules|admin|administrator|backup|config|setup|install|actuator|_profiler|telescope|server-status|server-info|phpinfo|dockerfile|jenkinsfile|docker-compose|swagger|swagger-ui|api-docs|graphql|console|debug|metrics|proc|api-keys|webhooks|billing|_catalog|credentials|env|wordpress|wp)(/|$|\.)#i';

	public function __construct( private NotFoundStore $store ) {
	}

	/**
	 * Шум: файл, служебный адрес или попытка взлома.
	 *
	 * @param string $path Путь адреса (уже декодированный).
	 */
	public static function is_noise( string $path ): bool {
		if ( '' === $path || '/' === $path || strlen( $path ) > self::MAX_LENGTH ) {
			return true;
		}

		if ( str_contains( $path, '..' ) || 1 === preg_match( '/[<>"\'`\\\\\x00-\x1f]/', $path ) ) {
			return true;
		}

		if ( 1 === preg_match( '#\.(' . self::FILE_EXTENSIONS . ')$#i', $path ) ) {
			return true;
		}

		return 1 === preg_match( self::NOISE_PATHS, $path );
	}

	/**
	 * Записывает обращение к адресу, который ответил 404.
	 *
	 * @param string $request_uri Адрес запроса.
	 * @param string $referrer    Откуда пришли.
	 * @return bool Записано ли обращение.
	 */
	public function record( string $request_uri, string $referrer = '' ): bool {
		$path = rawurldecode( (string) explode( '?', explode( '#', $request_uri, 2 )[0], 2 )[0] );
		$path = '/' . ltrim( (string) preg_replace( '#/{2,}#', '/', $path ), '/' );

		if ( self::is_noise( $path ) || ! mb_check_encoding( $path, 'UTF-8' ) ) {
			return false;
		}

		$referrer = 1 === preg_match( '#^https?://#i', $referrer ) ? mb_substr( $referrer, 0, self::MAX_LENGTH ) : '';
		$id       = $this->store->add( $path, $referrer );

		if ( $id > 0 && 0 === $id % self::TRIM_EVERY ) {
			$this->store->trim( self::LIMIT - self::TRIM_EVERY );
		}

		return true;
	}
}
