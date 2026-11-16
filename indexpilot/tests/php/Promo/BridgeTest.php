<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Promo;

use IndexPilot\Promo\Bridge;
use PHPUnit\Framework\TestCase;

final class BridgeTest extends TestCase {

	public function test_unavailable_partner_gives_no_block_and_no_waiting(): void {
		self::assertSame( '', Bridge::html( null ) );
		self::assertSame( '', Bridge::html( array( 'failed' => true, 'items' => array() ) ) );
		self::assertSame( '', Bridge::html( 'мусор' ) );

		// Блок собирается только из сохранённых данных: у метода нет доступа к сети, поэтому он не может ждать ответа.
		$method = new \ReflectionMethod( Bridge::class, 'html' );
		self::assertSame( 1, $method->getNumberOfParameters() );
	}

	public function test_partner_answer_turns_into_links(): void {
		$items = Bridge::items(
			array(
				'keyword'   => array(
					array( 'title' => 'Гайд <b>жрец</b>', 'url' => 'https://kolodahearthstone.com/gajd-zhreca/' ),
					array( 'title' => 'Без адреса', 'url' => 'javascript:alert(1)' ),
				),
				'archetype' => array( 'title' => 'Дракон-жрец', 'url' => 'https://kolodahearthstone.com/archetype/drakon/' ),
			),
			'kolodahearthstone.com'
		);

		self::assertCount( 2, $items );
		self::assertSame( 'Подробный разбор на kolodahearthstone.com', $items[0]['label'] );
		self::assertSame( 'Все колоды архетипа «Дракон-жрец» на kolodahearthstone.com', $items[1]['label'] );

		$html = Bridge::html( array( 'failed' => false, 'items' => $items ) );
		self::assertStringContainsString( 'class="indexpilot-bridge"', $html );
		self::assertStringContainsString( 'href="https://kolodahearthstone.com/gajd-zhreca/"', $html );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringNotContainsString( 'javascript', $html );
	}

	public function test_empty_answer_gives_no_items(): void {
		self::assertSame( array(), Bridge::items( array( 'keyword' => array(), 'archetype' => null ), 'example.org' ) );
		self::assertSame( array(), Bridge::items( array(), 'example.org' ) );
	}
}
