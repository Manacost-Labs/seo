<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Redirects;

use IndexPilot\Redirects\Matcher;
use IndexPilot\Tests\Support\ArrayRedirectStore;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase {

	private ArrayRedirectStore $store;

	protected function setUp(): void {
		$this->store = new ArrayRedirectStore();
	}

	private function matcher(): Matcher {
		return new Matcher( Matcher::index( $this->store->all() ), array( $this->store, 'find' ) );
	}

	public function test_case_trailing_slash_and_ad_label_find_the_rule(): void {
		$this->store->insert(
			array(
				'source' => '/Staryj-Gajd/',
				'target' => '/novyj-gajd/',
			)
		);

		$match = $this->matcher()->match( '/staryj-gajd?utm_source=vk&utm_medium=post' );

		self::assertNotNull( $match );
		self::assertSame( 301, $match['code'] );
		// Рекламная метка переходит на новый адрес: статистика кампании не теряется.
		self::assertSame( '/novyj-gajd/?utm_source=vk&utm_medium=post', $match['target'] );
		self::assertSame( Matcher::key( '/STARYJ-gajd' ), Matcher::key( 'https://hs-manacost.ru/staryj-gajd/?gclid=1' ) );
	}

	public function test_other_address_and_disabled_rule_do_not_match(): void {
		$this->store->insert(
			array(
				'source'  => '/vyklyuchen/',
				'target'  => '/x/',
				'enabled' => false,
			)
		);
		$this->store->insert(
			array(
				'source' => '/odin/',
				'target' => '/x/',
			)
		);

		self::assertNull( $this->matcher()->match( '/vyklyuchen/' ) );
		self::assertNull( $this->matcher()->match( '/odin/dva/' ) );
		self::assertNull( $this->matcher()->match( '/' ) );
	}

	public function test_pattern_rule_substitutes_the_captured_part(): void {
		$this->store->insert(
			array(
				'source' => '^/old-guides/(.+?)/?$',
				'regex'  => true,
				'target' => '/guides/$1/',
				'code'   => 302,
			)
		);

		$match = $this->matcher()->match( '/old-guides/pal-aggro/' );

		self::assertSame( '/guides/pal-aggro/', $match['target'] ?? null );
		self::assertSame( 302, $match['code'] );
	}

	public function test_exact_rule_wins_over_pattern(): void {
		$this->store->insert(
			array(
				'source' => '^/a/.*',
				'regex'  => true,
				'target' => '/pattern/',
			)
		);
		$this->store->insert(
			array(
				'source' => '/a/b/',
				'target' => '/exact/',
			)
		);

		self::assertSame( '/exact/', $this->matcher()->match( '/a/b/' )['target'] ?? null );
	}

	public function test_gone_rule_answers_410_without_redirect(): void {
		$this->store->insert(
			array(
				'source' => '/udalena/',
				'target' => '',
				'code'   => 410,
			)
		);

		$match = $this->matcher()->match( '/udalena/' );

		self::assertSame( 410, $match['code'] ?? null );
		self::assertSame( '', $match['target'] );
		self::assertTrue( Matcher::is_gone( 410 ) );
		self::assertFalse( Matcher::is_gone( 301 ) );
	}

	public function test_index_keeps_only_enabled_rules_and_patterns_separately(): void {
		$this->store->insert(
			array(
				'source' => '/a/',
				'target' => '/b/',
			)
		);
		$this->store->insert(
			array(
				'source'  => '/off/',
				'target'  => '/b/',
				'enabled' => false,
			)
		);
		$this->store->insert(
			array(
				'source' => '^/p/(.*)',
				'regex'  => true,
				'target' => '/q/$1',
			)
		);

		$index = Matcher::index( $this->store->all() );

		self::assertSame( array( Matcher::hash( '/a' ) => 1 ), $index['exact'] );
		self::assertSame( array( 3 => '^/p/(.*)' ), $index['patterns'] );
		self::assertTrue( Matcher::is_empty( Matcher::index( array() ) ) );
	}

	public function test_broken_pattern_is_rejected(): void {
		self::assertTrue( Matcher::valid_pattern( '^/x/(\d+)$' ) );
		self::assertFalse( Matcher::valid_pattern( '^/x/(' ) );
	}
}
