<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Game\Deck;
use IndexPilot\Game\DeckSchema;
use IndexPilot\Game\StreamerPage;
use IndexPilot\Schema\Graph;
use PHPUnit\Framework\TestCase;

final class StreamerPageTest extends TestCase {

	public function test_player_node_has_same_id_on_streamer_page_and_in_deck_markup(): void {
		$home  = 'https://hs-manacost.ru/';
		$deck  = new Deck( 1, 'Маг на заклинаниях', 'Маг', 'Стандартный', 'Маг на заклинаниях', 9400, 'PizzaHS', '', false, 'a', '2026-11-10 12:00:00' );
		$graph = new Graph();

		DeckSchema::add_deck( $graph, $home, 'https://hs-manacost.ru/deck/mag/', $deck );

		$person = StreamerPage::person( $home, 'pizzahs ', 'https://www.twitch.tv/pizzahs', 'Играет на легенде с 2016 года.', 'https://hs-manacost.ru/streamer/pizzahs/' );

		self::assertNotNull( $graph->node( $person['@id'] ) );
		self::assertSame( 'Person', $person['@type'] );
		self::assertSame( array( 'https://www.twitch.tv/pizzahs' ), $person['sameAs'] );
		self::assertSame( 'Играет на легенде с 2016 года.', $person['description'] );
	}

	public function test_twitch_address_is_normalised(): void {
		self::assertSame( 'https://www.twitch.tv/pizzahs', StreamerPage::twitch_url( 'pizzahs' ) );
		self::assertSame( 'https://www.twitch.tv/pizzahs', StreamerPage::twitch_url( 'https://twitch.tv/PizzaHS/' ) );
		self::assertSame( 'https://www.twitch.tv/pizza_hs', StreamerPage::twitch_url( 'twitch.tv/pizza_hs' ) );
		self::assertSame( '', StreamerPage::twitch_url( 'https://evil.test/pizzahs' ) );
		self::assertSame( '', StreamerPage::twitch_url( 'javascript:alert(1)' ) );
		self::assertSame( '', StreamerPage::twitch_url( '' ) );
	}
}
