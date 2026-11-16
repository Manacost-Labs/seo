<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Schema;

use IndexPilot\Schema\Graph;
use IndexPilot\Schema\Paywall;
use IndexPilot\Text\Cleaner;
use PHPUnit\Framework\TestCase;

final class PaywallTest extends TestCase {

	private const CONTENT = '<p>Вступление: колода сильна в патче 37.0.</p>[sociallocker]<p>Закрытый разбор муллигана.</p>[/sociallocker]<p>Итог.</p>';

	public function test_locked_part_is_detected_and_ordinary_shortcodes_are_not(): void {
		self::assertTrue( Paywall::has_locked( self::CONTENT ) );
		self::assertTrue( Paywall::has_locked( 'Текст [onp-sl id="3"]закрыто[/onp-sl]' ) );
		self::assertTrue( Paywall::has_locked( '[my_boosty_gate]x[/my_boosty_gate]' ) );
		self::assertFalse( Paywall::has_locked( '[gallery ids="1,2"] [caption]подпись[/caption]' ) );
		self::assertFalse( Paywall::has_locked( '[reusable_block id="5"]' ) );
		self::assertFalse( Paywall::has_locked( 'Просто текст про lock в скобках (lock)' ) );
	}

	public function test_article_node_is_marked_as_paid_with_selector(): void {
		$graph = new Graph();
		$graph->add(
			Paywall::mark(
				array(
					'@type' => 'Article',
					'@id'   => 'https://s.test/a/#article',
				),
				true
			)
		);

		$json = json_decode( $graph->to_json(), true );
		$node = $json['@graph'][0];

		self::assertFalse( $node['isAccessibleForFree'] );
		self::assertSame( 'WebPageElement', $node['hasPart']['@type'] );
		self::assertFalse( $node['hasPart']['isAccessibleForFree'] );
		self::assertSame( '.indexpilot-locked', $node['hasPart']['cssSelector'] );

		// Без обёртки селектору нечего найти: остаётся только пометка статьи.
		self::assertArrayNotHasKey( 'hasPart', Paywall::mark( array( '@type' => 'Article' ), false ) );
	}

	public function test_locked_text_does_not_reach_description(): void {
		$text = Cleaner::to_plain_text( self::CONTENT );

		self::assertStringContainsString( 'Вступление', $text );
		self::assertStringNotContainsString( 'муллигана', $text );
	}

	public function test_wrap_adds_class(): void {
		self::assertSame( '<div class="indexpilot-locked"><p>x</p></div>', Paywall::wrap( '<p>x</p>' ) );
	}
}
