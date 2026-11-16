<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\IndexNow;

use IndexPilot\Frontend\Verification;
use IndexPilot\IndexNow\Queue;
use IndexPilot\Llms\LlmsTxt;
use IndexPilot\IndexNow\Sender;
use IndexPilot\Robots\RobotsTxt;
use PHPUnit\Framework\TestCase;

final class IndexNowTest extends TestCase {

	/**
	 * Очередь с хранением в памяти.
	 *
	 * @param array<string, int> $stored Начальное содержимое.
	 */
	private function queue( array &$stored ): Queue {
		return new Queue(
			static function () use ( &$stored ): array {
				return $stored;
			},
			static function ( array $urls ) use ( &$stored ): void {
				$stored = $urls;
			}
		);
	}

	public function test_three_saves_of_one_post_give_one_address(): void {
		$stored = array();
		$queue  = $this->queue( $stored );

		$queue->add( 'https://site.test/gajd/' );
		$queue->add( 'https://site.test/gajd/' );
		$queue->add( 'https://site.test/gajd/' );
		$queue->add( 'https://site.test/koloda/' );
		$queue->add( '' );

		self::assertSame( 2, $queue->count() );
		self::assertSame( array( 'https://site.test/gajd/', 'https://site.test/koloda/' ), $queue->take( 10 ) );
		self::assertSame( 0, $queue->count() );
	}

	public function test_batch_is_limited_and_the_rest_stays_in_the_queue(): void {
		$stored = array();
		$queue  = $this->queue( $stored );

		foreach ( array( 'a', 'b', 'c' ) as $slug ) {
			$queue->add( 'https://site.test/' . $slug . '/' );
		}

		self::assertSame( array( 'https://site.test/a/', 'https://site.test/b/' ), $queue->take( 2 ) );
		self::assertSame( 1, $queue->count() );

		// Неотправленная пачка возвращается в очередь и уходит первой при следующей попытке.
		$queue->restore( array( 'https://site.test/a/', 'https://site.test/b/' ) );
		self::assertSame( array( 'https://site.test/a/', 'https://site.test/b/', 'https://site.test/c/' ), $queue->take( 10 ) );
	}

	public function test_queue_does_not_grow_without_limit(): void {
		$stored = array();
		$queue  = $this->queue( $stored );

		for ( $i = 0; $i < Queue::MAX_SIZE + 5; $i++ ) {
			$queue->add( 'https://site.test/' . $i . '/' );
		}

		self::assertSame( Queue::MAX_SIZE, $queue->count() );
		self::assertSame( 'https://site.test/5/', $queue->take( 1 )[0], 'Вытесняются самые старые адреса.' );
	}

	public function test_request_body_follows_the_protocol(): void {
		$body = Sender::payload( 'https://hs-manacost.ru/', 'abc123', array( 'https://hs-manacost.ru/gajd/', 'https://other.test/x/' ) );

		self::assertSame(
			array(
				'host'        => 'hs-manacost.ru',
				'key'         => 'abc123',
				'keyLocation' => 'https://hs-manacost.ru/abc123.txt',
				'urlList'     => array( 'https://hs-manacost.ru/gajd/' ),
			),
			$body,
			'Адреса чужих доменов в запрос не попадают.'
		);
	}

	public function test_training_bots_are_blocked_without_touching_search_bots(): void {
		$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$txt  = RobotsTxt::build( $core, 'https://site.test/sitemap.xml', true );

		self::assertStringStartsWith( $core, $txt );
		self::assertStringContainsString( "User-agent: GPTBot\n", $txt );
		self::assertSame( 1, preg_match( '/User-agent: CCBot\n(?:User-agent: [^\n]+\n)*Disallow: \/\n/', $txt ) );
		self::assertStringContainsString( "\nSitemap: https://site.test/sitemap.xml\n", $txt );

		foreach ( array( 'Googlebot', 'Yandex', 'bingbot', 'OAI-SearchBot', 'PerplexityBot', 'ChatGPT-User', 'Claude-SearchBot', 'Google-Extended' ) as $search_bot ) {
			self::assertStringNotContainsString( 'User-agent: ' . $search_bot . "\n", $txt, $search_bot );
		}
	}

	public function test_google_extended_is_blocked_only_by_its_own_switch(): void {
		$core = "User-agent: *\nDisallow: /wp-admin/\n";

		$only_google = RobotsTxt::build( $core, '', false, RobotsTxt::TRAINING_BOTS, '', true );
		self::assertSame( 1, preg_match( '/User-agent: Google-Extended\nDisallow: \/\n/', $only_google ) );
		self::assertStringNotContainsString( 'GPTBot', $only_google );

		$both = RobotsTxt::build( $core, '', true, RobotsTxt::TRAINING_BOTS, '', true );
		self::assertStringContainsString( "User-agent: GPTBot\n", $both );
		self::assertStringContainsString( "User-agent: Google-Extended\n", $both );
	}

	public function test_assistant_search_bots_stay_open_even_if_a_filter_lists_them(): void {
		$txt = RobotsTxt::build( "User-agent: *\nDisallow:\n", '', true, array( 'GPTBot', 'OAI-SearchBot', 'perplexitybot', 'Google-Extended' ) );

		self::assertStringContainsString( "User-agent: GPTBot\n", $txt );
		self::assertStringNotContainsString( 'OAI-SearchBot', $txt );
		self::assertStringNotContainsString( 'perplexitybot', $txt );
		self::assertStringNotContainsString( 'Google-Extended', $txt, 'Google-Extended управляется отдельной настройкой.' );
	}

	public function test_robots_txt_without_blocking_only_gets_the_sitemap_line(): void {
		$core = "User-agent: *\nDisallow: /wp-admin/\n";

		self::assertSame( $core . "\nSitemap: https://site.test/sitemap.xml\n", RobotsTxt::build( $core, 'https://site.test/sitemap.xml', false ) );
		self::assertSame( $core, RobotsTxt::build( $core, '', false ) );
		self::assertSame( 1, substr_count( RobotsTxt::build( $core . "Sitemap: https://site.test/sitemap.xml\n", 'https://site.test/sitemap.xml', false ), 'Sitemap:' ) );
	}

	public function test_verification_code_is_taken_from_a_code_or_a_whole_tag(): void {
		self::assertSame( 'AbC-123_x=', Verification::code( ' AbC-123_x= ' ) );
		self::assertSame( '1a2b3c', Verification::code( '<meta name="yandex-verification" content="1a2b3c" />' ) );
		self::assertSame( '', Verification::code( '"><script>alert(1)</script>' ) );
	}

	public function test_llms_txt_lists_key_pages_in_markdown(): void {
		$text = LlmsTxt::build(
			'Manacost',
			"Всё о <b>Hearthstone</b>
гайды и колоды",
			array(
				'Опорные материалы' => array(
					array(
						'title'       => 'Гайд [для новичков]',
						'url'         => 'https://site.test/gajd (1)/',
						'description' => "С чего начать
в Hearthstone.",
					),
					array(
						'title' => '',
						'url'   => 'https://site.test/empty/',
					),
				),
				'Пустой раздел'     => array(),
			)
		);

		self::assertSame(
			"# Manacost

> Всё о Hearthstone гайды и колоды

## Опорные материалы

- [Гайд (для новичков)](https://site.test/gajd%20(1%29/): С чего начать в Hearthstone.
",
			$text
		);
		self::assertSame( "# Manacost
", LlmsTxt::build( 'Manacost', '', array() ) );
	}
}
