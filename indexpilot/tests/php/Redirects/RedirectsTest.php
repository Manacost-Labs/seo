<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Redirects;

use IndexPilot\Redirects\ChainResolver;
use IndexPilot\Redirects\LinkAudit;
use IndexPilot\Redirects\Redirect;
use PHPUnit\Framework\TestCase;

final class RedirectsTest extends TestCase {

	private const HOST = 'hs-manacost.ru';

	public function test_chain_is_followed_to_the_final_address(): void {
		$resolver = new ChainResolver(
			array(
				new Redirect( 1, '/a/', '/b/' ),
				new Redirect( 2, '/b/', '/c/' ),
			),
			self::HOST
		);

		$result = $resolver->resolve( '/a/' );

		self::assertSame( 'https://hs-manacost.ru/c/', $result['final'] );
		self::assertSame( array( 'https://hs-manacost.ru/b/', 'https://hs-manacost.ru/c/' ), $result['hops'] );
		self::assertFalse( $result['loop'] );
	}

	public function test_loop_is_reported_as_an_error(): void {
		$resolver = new ChainResolver(
			array(
				new Redirect( 1, '/a/', '/b/' ),
				new Redirect( 2, '/b/', '/a/' ),
			),
			self::HOST
		);

		self::assertTrue( $resolver->resolve( '/a/' )['loop'] );
		self::assertCount( 2, $resolver->problems() );
		self::assertTrue( $resolver->problems()[0]['loop'] );
	}

	public function test_address_without_redirect_has_no_hops(): void {
		$resolver = new ChainResolver( array( new Redirect( 1, '/a/', '/b/' ) ), self::HOST );

		$result = $resolver->resolve( 'https://hs-manacost.ru/other/' );

		self::assertSame( array(), $result['hops'] );
		self::assertSame( 'https://hs-manacost.ru/other/', $result['final'] );
	}

	public function test_sources_match_regardless_of_slash_case_scheme_and_encoding(): void {
		$resolver = new ChainResolver( array( new Redirect( 1, '/Гайд-Маг', 'https://kolodahearthstone.com/mage/' ) ), self::HOST );

		foreach ( array( '/гайд-маг/', 'http://www.hs-manacost.ru/%D0%B3%D0%B0%D0%B9%D0%B4-%D0%BC%D0%B0%D0%B3/?utm=1', 'https://HS-MANACOST.RU/гайд-маг#top' ) as $url ) {
			self::assertSame( 'https://kolodahearthstone.com/mage/', $resolver->resolve( $url )['final'], $url );
		}

		// Тот же путь на другом домене — другой адрес.
		self::assertSame( array(), $resolver->resolve( 'https://example.com/гайд-маг/' )['hops'] );
	}

	public function test_hop_between_own_domains_counts_as_part_of_the_chain(): void {
		$external = static fn( string $url ): string => str_starts_with( $url, 'https://kolodahearthstone.ru/' )
			? str_replace( 'kolodahearthstone.ru', 'kolodahearthstone.com', $url )
			: '';

		$resolver = new ChainResolver(
			array(
				new Redirect( 1, '/old-slug/', '/new-slug/' ),
				new Redirect( 2, '/new-slug/', 'https://kolodahearthstone.ru/deck/' ),
				new Redirect( 3, '/direct/', 'https://kolodahearthstone.com/direct/' ),
			),
			self::HOST,
			$external
		);

		$problems = $resolver->problems();

		self::assertCount( 2, $problems, 'Прямой редирект на конечный адрес проблемой не считается.' );
		self::assertSame( 1, $problems[0]['redirect']->id );
		self::assertSame( 'https://kolodahearthstone.com/deck/', $problems[0]['final'] );
		self::assertCount( 3, $problems[0]['hops'] );
		self::assertSame( 2, $problems[1]['redirect']->id );
		self::assertCount( 2, $problems[1]['hops'] );
	}

	public function test_endless_chain_is_cut_off(): void {
		$external = static fn( string $url ): string => $url . 'x';
		$result   = ( new ChainResolver( array(), self::HOST, $external ) )->resolve( 'https://example.com/' );

		self::assertTrue( $result['loop'] );
		self::assertLessThanOrEqual( 10, count( $result['hops'] ) );
	}

	public function test_links_to_redirected_addresses_are_found_in_text(): void {
		$resolver = new ChainResolver(
			array(
				new Redirect( 1, '/old-guide/', 'https://kolodahearthstone.com/guide/' ),
				new Redirect( 2, '/moved/', '/new-place/' ),
			),
			self::HOST
		);

		$html = '<p><a href="/old-guide/">гайд</a>, <a href=\'https://hs-manacost.ru/moved\'>статья</a>, '
			. '<a href="https://hs-manacost.ru/fine/">обычная</a>, <a href="https://example.com/old-guide/">чужая</a>, '
			. '<a href="#top">якорь</a>, <a href="/old-guide/">повтор</a></p>';

		self::assertSame(
			array(
				array(
					'href'  => '/old-guide/',
					'final' => 'https://kolodahearthstone.com/guide/',
				),
				array(
					'href'  => 'https://hs-manacost.ru/moved',
					'final' => 'https://hs-manacost.ru/new-place/',
				),
			),
			LinkAudit::find( $html, $resolver )
		);
	}
}
