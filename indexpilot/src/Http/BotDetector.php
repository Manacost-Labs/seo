<?php
/**
 * Определение роботов по строке User-Agent.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Http;

/**
 * Отличает переход человека от обращения робота: поисковика, сборщика карточек ссылок, проверки доступности.
 * Нужен счётчикам переходов — робот не должен увеличивать число переходов.
 */
final class BotDetector {

	/**
	 * Части строки User-Agent, по которым узнаётся робот. Сравнение без учёта регистра.
	 */
	private const MARKERS = array(
		'bot',
		'crawl',
		'spider',
		'slurp',
		'preview',
		'fetch',
		'scan',
		'monitor',
		'check',
		'curl',
		'wget',
		'python',
		'java/',
		'go-http-client',
		'okhttp',
		'axios',
		'headless',
		'lighthouse',
		'facebookexternalhit',
		'whatsapp',
		'vkshare',
		'yandexmetrika',
		'mediapartners',
		'google-inspectiontool',
		'chatgpt-user',
		'feed',
	);

	/**
	 * Робот ли это. Пустая строка тоже считается роботом: браузер всегда называет себя.
	 *
	 * @param string $user_agent Строка User-Agent запроса.
	 */
	public static function is_bot( string $user_agent ): bool {
		$agent = strtolower( trim( $user_agent ) );

		if ( '' === $agent ) {
			return true;
		}

		foreach ( self::MARKERS as $marker ) {
			if ( str_contains( $agent, $marker ) ) {
				return true;
			}
		}

		return false;
	}
}
