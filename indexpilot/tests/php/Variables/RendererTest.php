<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Variables;

use IndexPilot\Resolver\Context;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	/**
	 * @param array<string, string> $values
	 */
	private function renderer( array $values ): Renderer {
		$registry = new VariableRegistry();
		foreach ( $values as $name => $value ) {
			$registry->register( $name, static fn(): string => $value );
		}
		$registry->register_prefix( 'cf_', static fn( string $key ): string => 'dust' === $key ? '5400' : '' );

		return new Renderer( $registry, '—' );
	}

	private function context(): Context {
		return new Context( Context::POST, 10, 'hs_deck' );
	}

	public function test_renders_known_variables(): void {
		$renderer = $this->renderer(
			array(
				'title'    => 'Колода мага',
				'sitename' => 'Manacost',
			)
		);

		self::assertSame( 'Колода мага — Manacost', $renderer->render( '%title% %sep% %sitename%', $this->context() ) );
	}

	public function test_empty_variable_collapses_neighbouring_separators(): void {
		$renderer = $this->renderer(
			array(
				'title'    => 'Колода мага',
				'category' => '',
				'sitename' => 'Manacost',
			)
		);

		self::assertSame(
			'Колода мага — Manacost',
			$renderer->render( '%title% %sep% %category% %sep% %sitename%', $this->context() )
		);
	}

	public function test_separator_is_trimmed_from_the_edges(): void {
		$renderer = $this->renderer(
			array(
				'title'    => 'Колода мага',
				'category' => '',
			)
		);

		self::assertSame( 'Колода мага', $renderer->render( '%category% %sep% %title% %sep%', $this->context() ) );
	}

	public function test_unknown_token_is_removed_on_the_site_with_its_orphaned_separator(): void {
		$renderer = $this->renderer(
			array(
				'title'    => 'Колода мага',
				'sitename' => 'Manacost',
			)
		);

		self::assertSame( 'Колода мага', $renderer->render( '%title% %nope%', $this->context() ) );
		self::assertSame( 'Колода мага — Manacost', $renderer->render( '%title% %sep% %sitedesc% %sep% %sitename%', $this->context() ) );
		self::assertSame( 'Колода мага', $renderer->render( '%title% %sep% %sitedesc%', $this->context() ) );
		self::assertSame( 'Колода мага для новичков', $renderer->render( '%title%   %nope%   для новичков', $this->context() ) );
		self::assertSame( 'Колода мага', $renderer->render( '%title% (%nope%)', $this->context() ) );
		self::assertSame( '', $renderer->render( '%nope%', $this->context() ) );
	}

	public function test_unknown_token_stays_in_the_preview_and_is_reported(): void {
		$renderer = $this->renderer( array( 'title' => 'Колода мага' ) );
		$preview  = new Context( Context::POST, 10, 'hs_deck', 1, array(), true );

		self::assertSame( 'Колода мага %nope%', $renderer->render( '%title% %nope%', $preview ) );
		self::assertSame( 'Колода мага — %sitedesc%', $renderer->render( '%title% %sep% %sitedesc%', $preview ) );
		self::assertSame( array( 'nope' ), $renderer->unknown_tokens( '%title% %sep% %nope% %cf_dust%' ) );
		self::assertSame( array(), $renderer->unknown_tokens( 'Винрейт 60% и 50% шанс %title%' ) );
	}

	public function test_prefix_variable_receives_its_argument(): void {
		$renderer = $this->renderer( array() );

		self::assertSame( 'Пыль: 5400', $renderer->render( 'Пыль: %cf_dust%', $this->context() ) );
	}

	public function test_empty_brackets_and_dangling_commas_are_removed(): void {
		$renderer = $this->renderer(
			array(
				'title' => 'Колода мага',
				'class' => '',
				'mode'  => 'Стандарт',
			)
		);

		self::assertSame( 'Колода мага', $renderer->render( '%title% (%class%)', $this->context() ) );
		self::assertSame( 'Колода мага: Стандарт', $renderer->render( '%title%: %class%, %mode%', $this->context() ) );
	}

	public function test_percent_signs_in_plain_text_are_preserved(): void {
		$renderer = $this->renderer( array() );

		self::assertSame(
			'Винрейт 60% и 50% шанс',
			$renderer->render( 'Винрейт 60% и 50% шанс', $this->context() )
		);
	}

	public function test_value_is_resolved_once_per_context(): void {
		$calls    = 0;
		$registry = new VariableRegistry();
		$registry->register(
			'title',
			static function () use ( &$calls ): string {
				++$calls;
				return 'Колода';
			}
		);
		$renderer = new Renderer( $registry, '|' );

		$renderer->render( '%title% %sep% %title%', $this->context() );
		$renderer->render( '%title%', $this->context() );

		self::assertSame( 1, $calls );
	}
}
