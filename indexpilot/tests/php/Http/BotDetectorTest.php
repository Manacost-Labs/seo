<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Http;

use IndexPilot\Http\BotDetector;
use PHPUnit\Framework\TestCase;

final class BotDetectorTest extends TestCase {

	public function test_search_engines_and_link_previews_are_bots(): void {
		$bots = array(
			'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
			'TelegramBot (like TwitterBot)',
			'facebookexternalhit/1.1',
			'Mozilla/5.0 (compatible; vkShare; +http://vk.com/dev/Share)',
			'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
			'curl/8.4.0',
			'',
		);

		foreach ( $bots as $agent ) {
			self::assertTrue( BotDetector::is_bot( $agent ), $agent );
		}
	}

	public function test_browsers_are_not_bots(): void {
		$people = array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
			'Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 YaBrowser/24.10.0.0',
			'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
		);

		foreach ( $people as $agent ) {
			self::assertFalse( BotDetector::is_bot( $agent ), $agent );
		}
	}
}
