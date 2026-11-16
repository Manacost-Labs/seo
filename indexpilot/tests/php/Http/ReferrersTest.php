<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Http;

use IndexPilot\Http\Referrers;
use PHPUnit\Framework\TestCase;

final class ReferrersTest extends TestCase {

	public function test_known_sources_keep_their_host_and_unknown_become_other(): void {
		self::assertSame( 'google.com', Referrers::bucket( 'www.google.com' ) );
		self::assertSame( 'yandex.ru', Referrers::bucket( 'Yandex.RU' ) );
		self::assertSame( 't.me', Referrers::bucket( 't.me' ) );
		self::assertSame( 'chatgpt.com', Referrers::bucket( 'chatgpt.com' ) );
		self::assertSame( 'site.test', Referrers::bucket( 'www.site.test', 'site.test' ) );
		self::assertSame( Referrers::OTHER, Referrers::bucket( 'r123456.example' ) );
		self::assertSame( Referrers::OTHER, Referrers::bucket( 'google.com.evil.example' ) );
		self::assertSame( '', Referrers::bucket( '' ) );
	}

	public function test_assistants_are_recognised_by_host(): void {
		self::assertSame( 'ChatGPT', Referrers::assistant( 'chatgpt.com' ) );
		self::assertSame( 'ChatGPT', Referrers::assistant( 'chat.openai.com' ) );
		self::assertSame( 'Perplexity', Referrers::assistant( 'www.perplexity.ai' ) );
		self::assertSame( 'Copilot', Referrers::assistant( 'copilot.microsoft.com' ) );
		self::assertSame( 'Gemini', Referrers::assistant( 'gemini.google.com' ) );
		self::assertSame( 'Алиса', Referrers::assistant( 'alice.yandex.ru' ) );
		self::assertSame( 'Алиса', Referrers::assistant( 'ya.ru' ) );
		self::assertSame( '', Referrers::assistant( 'google.com' ) );
		self::assertSame( '', Referrers::assistant( 'notchatgpt.com' ) );
	}

	public function test_host_is_taken_from_full_address(): void {
		self::assertSame( 'perplexity.ai', Referrers::host( 'https://www.Perplexity.ai/search?q=deck' ) );
		self::assertSame( '', Referrers::host( 'not a url' ) );
	}
}
