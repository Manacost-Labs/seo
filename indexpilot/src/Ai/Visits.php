<?php
/**
 * Заходы роботов ассистентов и переходы из ассистентов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai;

use IndexPilot\Http\BotDetector;
use IndexPilot\Http\Referrers;

/**
 * Чистая логика счётчика: кто пришёл (робот ассистента или читатель из ассистента), по какой странице,
 * и копилка, которая пишется в таблицу пачкой. IP-адреса и строки браузера не хранятся.
 * Не зависит от WordPress.
 */
final class Visits {

	public const BOT      = 'bot';
	public const REFERRAL = 'ref';

	/**
	 * Как часто копилка выгружается в таблицу, секунд.
	 */
	public const INTERVAL = 600;

	/**
	 * Сколько разных строк держит копилка; при заполнении она выгружается сразу, лишнее не копится.
	 */
	public const MAX_KEYS = 300;

	/**
	 * Роботы ассистентов. Длинные имена раньше коротких: «ChatGPT-User» не должен засчитаться как другой робот.
	 */
	public const BOTS = array(
		'OAI-SearchBot',
		'ChatGPT-User',
		'GPTBot',
		'PerplexityBot',
		'Perplexity-User',
		'Claude-SearchBot',
		'Claude-User',
		'ClaudeBot',
		'YandexAdditionalBot',
		'YandexAdditional',
		'DuckAssistBot',
		'MistralAI-User',
		'Applebot-Extended',
		'meta-externalfetcher',
		'Bytespider',
		'CCBot',
	);

	/**
	 * Источник захода или null, если считать нечего.
	 *
	 * @param string $user_agent    Строка браузера.
	 * @param string $referrer_host Хост из Referer.
	 * @return array{kind: string, agent: string}|null
	 */
	public static function source( string $user_agent, string $referrer_host ): ?array {
		$agent = strtolower( $user_agent );

		foreach ( self::BOTS as $bot ) {
			if ( str_contains( $agent, strtolower( $bot ) ) ) {
				return array(
					'kind'  => self::BOT,
					'agent' => $bot,
				);
			}
		}

		$assistant = '' !== $referrer_host ? Referrers::assistant( $referrer_host ) : '';

		if ( '' !== $assistant && ! BotDetector::is_bot( $user_agent ) ) {
			return array(
				'kind'  => self::REFERRAL,
				'agent' => $assistant,
			);
		}

		return null;
	}

	/**
	 * Путь страницы без параметров, не длиннее 190 символов.
	 *
	 * @param string $uri Адрес запроса.
	 */
	public static function path( string $uri ): string {
		$path = (string) strtok( $uri, '?#' );
		$path = '' === $path ? '/' : $path;

		return substr( $path, 0, 190 );
	}

	/**
	 * Добавляет заход в копилку.
	 *
	 * @param array<string, mixed> $buffer Копилка: since — время первого захода, hits — счётчики.
	 * @param string               $day    День UTC.
	 * @param string               $path   Путь страницы.
	 * @param string               $kind   bot или ref.
	 * @param string               $agent  Робот или ассистент.
	 * @param int                  $now    Текущее время.
	 * @return array<string, mixed>
	 */
	public static function add( array $buffer, string $day, string $path, string $kind, string $agent, int $now ): array {
		$hits = isset( $buffer['hits'] ) && is_array( $buffer['hits'] ) ? $buffer['hits'] : array();
		$key  = $day . "\t" . $kind . "\t" . $agent . "\t" . $path;

		if ( isset( $hits[ $key ] ) ) {
			++$hits[ $key ];
		} elseif ( count( $hits ) < self::MAX_KEYS ) {
			$hits[ $key ] = 1;
		}

		return array(
			'since' => isset( $buffer['since'] ) ? (int) $buffer['since'] : $now,
			'hits'  => $hits,
		);
	}

	/**
	 * Пора ли выгружать копилку в таблицу.
	 *
	 * @param array<string, mixed> $buffer Копилка.
	 * @param int                  $now    Текущее время.
	 */
	public static function due( array $buffer, int $now ): bool {
		$hits = isset( $buffer['hits'] ) && is_array( $buffer['hits'] ) ? $buffer['hits'] : array();

		if ( array() === $hits ) {
			return false;
		}

		return count( $hits ) >= self::MAX_KEYS || $now - (int) ( $buffer['since'] ?? $now ) >= self::INTERVAL;
	}

	/**
	 * Строки для таблицы.
	 *
	 * @param array<string, mixed> $buffer Копилка.
	 * @return list<array{day: string, path: string, kind: string, agent: string, hits: int}>
	 */
	public static function rows( array $buffer ): array {
		$rows = array();

		foreach ( isset( $buffer['hits'] ) && is_array( $buffer['hits'] ) ? $buffer['hits'] : array() as $key => $hits ) {
			$parts = explode( "\t", (string) $key, 4 );

			if ( 4 === count( $parts ) ) {
				$rows[] = array(
					'day'   => $parts[0],
					'path'  => $parts[3],
					'kind'  => $parts[1],
					'agent' => $parts[2],
					'hits'  => (int) $hits,
				);
			}
		}

		return $rows;
	}
}
