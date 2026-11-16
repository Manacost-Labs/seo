<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Promo;

use IndexPilot\Promo\ClickRouter;
use IndexPilot\Promo\Destinations;
use IndexPilot\Tests\Support\ArrayClickStore;
use PHPUnit\Framework\TestCase;

final class ClickRouterTest extends TestCase {

	private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

	public function test_parses_bare_address(): void {
		self::assertSame(
			array(
				'code'    => 'boosty',
				'post_id' => 0,
				'rule_id' => 0,
				'variant' => 0,
			),
			ClickRouter::parse( '/to/boosty', '/', 'to' )
		);
	}

	public function test_parses_address_with_post_rule_and_variant(): void {
		self::assertSame(
			array(
				'code'    => 'hearthpulse-2',
				'post_id' => 152,
				'rule_id' => 7,
				'variant' => 2,
			),
			ClickRouter::parse( '/to/Hearthpulse-2/152-7-2/?from=mail', '/', 'to' )
		);
	}

	public function test_works_on_site_in_subdirectory(): void {
		self::assertSame( 'vk', ClickRouter::parse( '/blog/to/vk/5-0-0', '/blog/', 'to' )['code'] );
		self::assertNull( ClickRouter::parse( '/to/vk', '/blog/', 'to' ) );
	}

	public function test_foreign_and_broken_addresses_are_not_clicks(): void {
		self::assertNull( ClickRouter::parse( '/tools/', '/', 'to' ) );
		self::assertNull( ClickRouter::parse( '/to/', '/', 'to' ) );
		self::assertNull( ClickRouter::parse( '/to/boosty/abc', '/', 'to' ) );
		self::assertNull( ClickRouter::parse( '/to/boosty/1-2', '/', 'to' ) );
		self::assertNull( ClickRouter::parse( '/to/boosty/1-2-3/extra', '/', 'to' ) );
		self::assertNull( ClickRouter::parse( '/to/boosty', '/', '' ) );
	}

	public function test_builds_address_that_parses_back(): void {
		self::assertSame( '/to/boosty', ClickRouter::path( 'to', 'boosty' ) );
		self::assertSame( '/to/boosty/15-3-1', ClickRouter::path( 'to', 'boosty', 15, 3, 1 ) );
		self::assertSame( '/out/tg/15-0-0', ClickRouter::path( '/out/', 'tg', 15 ) );

		self::assertSame(
			array(
				'code'    => 'boosty',
				'post_id' => 15,
				'rule_id' => 3,
				'variant' => 1,
			),
			ClickRouter::parse( ClickRouter::path( 'to', 'boosty', 15, 3, 1 ), '/', 'to' )
		);
	}

	public function test_any_destination_code_fits_click_address(): void {
		$rows = Destinations::sanitize(
			array(
				array(
					'name' => str_repeat( 'very-long-name-', 10 ),
					'url'  => 'https://example.com/',
				),
				array(
					'name' => str_repeat( 'very-long-name-', 10 ),
					'url'  => 'https://example.org/',
				),
			)
		);

		foreach ( $rows as $row ) {
			self::assertSame( $row['code'], ClickRouter::parse( ClickRouter::path( 'to', $row['code'], 7 ), '/', 'to' )['code'] ?? '' );
		}

		self::assertNotSame( $rows[0]['code'], $rows[1]['code'] );
	}

	public function test_adds_marks_to_plain_address(): void {
		self::assertSame(
			'https://boosty.to/manacost?utm_source=hs-manacost.ru&utm_medium=article',
			ClickRouter::add_utm(
				'https://boosty.to/manacost',
				array(
					'utm_source' => 'hs-manacost.ru',
					'utm_medium' => 'article',
				)
			)
		);
	}

	public function test_keeps_parameters_and_anchor(): void {
		self::assertSame(
			'https://hearthpulse.net/pricing?plan=pro&utm_source=site#buy',
			ClickRouter::add_utm( 'https://hearthpulse.net/pricing?plan=pro#buy', array( 'utm_source' => 'site' ) )
		);
		self::assertSame(
			'https://hearthpulse.net/#buy',
			ClickRouter::add_utm( 'https://hearthpulse.net/#buy', array() )
		);
	}

	public function test_does_not_duplicate_existing_mark_and_skips_empty(): void {
		self::assertSame(
			'https://boosty.to/a?utm_source=own&utm_campaign=autumn%20sale',
			ClickRouter::add_utm(
				'https://boosty.to/a?utm_source=own',
				array(
					'utm_source'   => 'site',
					'utm_medium'   => '',
					'utm_campaign' => 'autumn sale',
				)
			)
		);
	}

	public function test_target_takes_marks_from_destination_or_defaults(): void {
		$plain = array(
			'code' => 'boosty',
			'url'  => 'https://boosty.to/manacost',
			'utm'  => array(
				'source'   => '',
				'medium'   => '',
				'campaign' => '',
			),
		);

		self::assertSame(
			'https://boosty.to/manacost?utm_source=hs-manacost.ru&utm_medium=article&utm_campaign=boosty&utm_content=post-15',
			ClickRouter::target( $plain, 'hs-manacost.ru', 15, false )
		);
		self::assertSame(
			'https://boosty.to/manacost?utm_source=hs-manacost.ru&utm_medium=follow&utm_campaign=boosty',
			ClickRouter::target( $plain, 'hs-manacost.ru', 0, true )
		);

		$own = array(
			'utm' => array(
				'source'   => 'manacost',
				'medium'   => 'site',
				'campaign' => 'guides',
			),
		) + $plain;

		self::assertSame(
			'https://boosty.to/manacost?utm_source=manacost&utm_medium=site&utm_campaign=guides&utm_content=post-15',
			ClickRouter::target( $own, 'hs-manacost.ru', 15, true )
		);
	}

	public function test_unknown_post_rule_and_variant_are_dropped(): void {
		$rules = array(
			array(
				'id'          => 3,
				'destination' => 'boosty',
				'variants'    => array( array( 'text' => 'a' ), array( 'text' => 'b' ) ),
			),
		);
		$click = array(
			'code'    => 'boosty',
			'post_id' => 15,
			'rule_id' => 3,
			'variant' => 1,
		);

		self::assertSame( $click, ClickRouter::checked( $click, $rules, true ) );
		self::assertSame( 0, ClickRouter::checked( array( 'variant' => 2 ) + $click, $rules, true )['variant'] );

		// Правило другой площадки или удалённое правило: переход остаётся за записью, но без правила.
		foreach ( array( array( 'rule_id' => 9 ), array( 'code' => 'vk' ) ) as $change ) {
			$checked = ClickRouter::checked( $change + $click, $rules, true );

			self::assertSame( array( 15, 0, 0 ), array( $checked['post_id'], $checked['rule_id'], $checked['variant'] ) );
		}

		// Записи нет или она не опубликована: адрес собран не сайтом.
		$checked = ClickRouter::checked( $click, $rules, false );

		self::assertSame( array( 0, 0, 0 ), array( $checked['post_id'], $checked['rule_id'], $checked['variant'] ) );
	}

	public function test_counts_people_and_ignores_robots(): void {
		$store       = new ArrayClickStore();
		$destination = array(
			'code' => 'boosty',
			'url'  => 'https://boosty.to/manacost',
			'utm'  => array(),
		);
		$click       = array(
			'code'    => 'boosty',
			'post_id' => 15,
			'rule_id' => 0,
			'variant' => 0,
		);

		$target = ClickRouter::visit( $store, $click, $destination, self::BROWSER, '2026-11-16', 'hs-manacost.ru' );
		ClickRouter::visit( $store, $click, $destination, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', '2026-11-16', 'hs-manacost.ru' );
		ClickRouter::visit( $store, $click, $destination, '', '2026-11-16', 'hs-manacost.ru' );

		self::assertSame( 'https://boosty.to/manacost?utm_source=hs-manacost.ru&utm_medium=follow&utm_campaign=boosty&utm_content=post-15', $target );
		self::assertSame( 1, $store->rows( '2026-11-16', '2026-11-16' )[0]['hits'] );
	}
}
