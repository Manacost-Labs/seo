<?php
/**
 * Источники переходов по адресу Referer.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Http;

/**
 * Сводит адрес источника перехода к короткому списку известных площадок.
 * Счётчики хранят только известные источники, всё остальное — одной строкой «other»:
 * так посторонний не раздует таблицу переходов выдуманными адресами в заголовке Referer.
 */
final class Referrers {

	/**
	 * Метка для всех неизвестных источников.
	 */
	public const OTHER = 'other';

	/**
	 * ИИ-ассистенты: домен => название.
	 */
	public const ASSISTANTS = array(
		'chatgpt.com'           => 'ChatGPT',
		'chat.openai.com'       => 'ChatGPT',
		'perplexity.ai'         => 'Perplexity',
		'copilot.microsoft.com' => 'Copilot',
		'gemini.google.com'     => 'Gemini',
		'alice.yandex.ru'       => 'Алиса',
		'ya.ru'                 => 'Алиса',
		'claude.ai'             => 'Claude',
		'chat.deepseek.com'     => 'DeepSeek',
	);

	/**
	 * Поисковики, соцсети и площадки, которые остаются в отчёте под своим именем.
	 */
	private const KNOWN = array(
		'google.com',
		'google.ru',
		'yandex.ru',
		'yandex.com',
		'yandex.by',
		'yandex.kz',
		'bing.com',
		'duckduckgo.com',
		'go.mail.ru',
		'search.yahoo.com',
		'vk.com',
		'vk.ru',
		'vkvideo.ru',
		'ok.ru',
		't.me',
		'telegram.org',
		'youtube.com',
		'twitch.tv',
		'discord.com',
		'x.com',
		't.co',
		'twitter.com',
		'facebook.com',
		'instagram.com',
		'reddit.com',
		'boosty.to',
		'dzen.ru',
		'pikabu.ru',
		'hs-manacost.ru',
		'kolodahearthstone.com',
		'hearthpulse.net',
	);

	/**
	 * Хост из полного адреса источника, в нижнем регистре и без «www.».
	 *
	 * @param string $url Адрес из заголовка Referer.
	 */
	public static function host( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress.
		$host = strtolower( (string) parse_url( trim( $url ), PHP_URL_HOST ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Строка для счётчика: известный хост как есть, неизвестный — «other», пустой — пустая строка.
	 *
	 * @param string $host     Хост источника.
	 * @param string $own_host Хост своего сайта: переходы со своих страниц тоже интересны.
	 */
	public static function bucket( string $host, string $own_host = '' ): string {
		$host = self::bare( $host );

		if ( '' === $host ) {
			return '';
		}

		$own = self::bare( $own_host );

		if ( '' !== $own && $host === $own ) {
			return $host;
		}

		foreach ( array_merge( self::KNOWN, array_keys( self::ASSISTANTS ) ) as $domain ) {
			if ( self::matches( $host, $domain ) ) {
				return $domain;
			}
		}

		return self::OTHER;
	}

	/**
	 * Название ассистента по хосту источника или пустая строка.
	 *
	 * @param string $host Хост источника.
	 */
	public static function assistant( string $host ): string {
		$host = self::bare( $host );

		foreach ( self::ASSISTANTS as $domain => $name ) {
			if ( self::matches( $host, $domain ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Хост в нижнем регистре без «www.».
	 *
	 * @param string $host Хост.
	 */
	private static function bare( string $host ): string {
		$host = strtolower( trim( $host ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Совпадает ли хост с доменом или его поддоменом.
	 *
	 * @param string $host   Хост.
	 * @param string $domain Домен.
	 */
	private static function matches( string $host, string $domain ): bool {
		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}
}
