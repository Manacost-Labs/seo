<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Game\Deck;
use IndexPilot\Game\DeckDescription;
use IndexPilot\Game\DeckSchema;
use IndexPilot\Game\Deckstring;
use IndexPilot\Game\DeckVariables;
use IndexPilot\Game\DeckVersionRule;
use IndexPilot\Game\KolodaDeckAdapter;
use IndexPilot\Game\ManacostDeckAdapter;
use IndexPilot\Game\RelatedLinks;
use IndexPilot\Resolver\Context;
use IndexPilot\Schema\Graph;
use IndexPilot\Tests\Support\ArrayPostReader;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase {

	private const HUNTER_CODE = 'AAECAR8GxwPJBLsFmQfZB/gIDI0B2AGoArUDhwSSBe0G6wfbCe0JgQr+DAA=';
	private const DRUID_CODE  = 'AAECAZICDs2eBqn1BqGBB5KDB8ODB6+HB6yIB4KYB+ixB+DAB+LAB93EB+XE';

	private function manacost(): ManacostDeckAdapter {
		return new ManacostDeckAdapter(
			new ArrayPostReader(
				array(
					1 => array(
						'title' => 'Маг на заклинаниях',
						'date'  => '2026-11-10 12:00:00',
						'meta'  => array(
							'_dust_cost'         => '9400',
							'_deck_player'       => 'Unknown',
							'_deck_streamer'     => 'PizzaHS',
							'_deck_code'         => self::HUNTER_CODE,
							'_hs_archetype_key'  => 'маг на заклинаниях',
							'_hs_deck_archived'  => '0',
						),
						'terms' => array(
							'deck_class'     => array( 'Маг' ),
							'deck_mode'      => array( 'Стандартный' ),
							'deck_archetype' => array( 'Маг на заклинаниях' ),
						),
					),
					2 => array(
						'title' => 'Колода без данных',
						'date'  => '2026-11-11 12:00:00',
					),
				)
			)
		);
	}

	public function test_deck_code_header_is_decoded(): void {
		self::assertSame( array( 'format' => 2, 'hero' => 31 ), Deckstring::decode( self::HUNTER_CODE ) );
		self::assertSame( array( 'format' => 2, 'hero' => 274 ), Deckstring::decode( self::DRUID_CODE ) );
		self::assertNull( Deckstring::decode( 'не код колоды' ) );
		self::assertNull( Deckstring::decode( '' ) );
		self::assertSame( 'Охотник', Deckstring::class_name( 31 ) );
		self::assertSame( '', Deckstring::class_name( 999999 ) );
		self::assertSame( 'Стандартный', Deckstring::mode_name( 2 ) );
		self::assertSame( 'Вольный', Deckstring::mode_name( 1 ) );
	}

	public function test_skins_and_brawl_heroes_keep_their_playable_classes(): void {
		self::assertSame( Deckstring::class_name( 893 ), Deckstring::class_name( 47817 ) );
		self::assertSame( 'Жрец / Чернокнижник', Deckstring::class_name( 134896 ) );
		self::assertSame( array( 'death-knight', 'warrior', 'demon-hunter' ), Deckstring::class_slugs( 134893 ) );
		self::assertSame( array(), Deckstring::class_slugs( 999999 ) );
	}

	public function test_manacost_deck_is_built_from_meta_and_taxonomies(): void {
		$deck = $this->manacost()->deck( 1 );

		self::assertSame( 'Маг', $deck->hero_class );
		self::assertSame( 'Стандартный', $deck->mode );
		self::assertSame( 'Маг на заклинаниях', $deck->archetype );
		self::assertSame( 9400, $deck->dust );
		self::assertSame( 'PizzaHS', $deck->player, 'Заглушка «Unknown» пропускается, берётся стример.' );
		self::assertFalse( $deck->archived );
		self::assertSame( 'маг на заклинаниях|маг|стандартный', $deck->group );
	}

	public function test_missing_fields_give_empty_values_not_errors(): void {
		$deck = $this->manacost()->deck( 2 );

		self::assertSame( 'Колода без данных', $deck->title );
		self::assertSame( '', $deck->hero_class );
		self::assertSame( '', $deck->mode );
		self::assertSame( '', $deck->player );
		self::assertSame( 0, $deck->dust );
		self::assertSame( '', $deck->group, 'Без архетипа колода ни с кем не объединяется.' );

		self::assertSame( '', $this->manacost()->deck( 404 )->title );
	}

	public function test_koloda_deck_takes_class_and_mode_from_the_deck_code(): void {
		$adapter = new KolodaDeckAdapter(
			new ArrayPostReader(
				array(
					5 => array(
						'title' => 'Дракон Друид',
						'date'  => '2026-11-13 20:45:56',
						'meta'  => array( '_hs_deck_code' => self::DRUID_CODE ),
					),
				)
			)
		);

		$deck = $adapter->deck( 5 );

		self::assertSame( 'Друид', $deck->hero_class );
		self::assertSame( 'Стандартный', $deck->mode );
		self::assertSame( 'Дракон Друид', $deck->archetype );
		self::assertSame( 'дракон друид|друид|стандартный', $deck->group );
	}

	public function test_template_with_empty_player_leaves_no_stray_characters(): void {
		$registry = new VariableRegistry();
		$registry->register( 'title', static fn(): string => 'Маг на заклинаниях' );
		( new DeckVariables( $this->manacost() ) )->register( $registry );

		$renderer = new Renderer( $registry, '—' );
		$template = '%title%{{ (%deck_class%, %deck_mode%)}}{{, игрок %deck_player%}}{{, %deck_dust% пыли}}';

		self::assertSame( 'Маг на заклинаниях (Маг, Стандартный), игрок PizzaHS, 9400 пыли', $renderer->render( $template, new Context( Context::POST, 1, 'hs_deck' ) ) );
		self::assertSame( 'Маг на заклинаниях', $renderer->render( $template, new Context( Context::POST, 2, 'hs_deck' ) ) );
		self::assertSame( 'Маг на заклинаниях', $renderer->render( $template, new Context( Context::POST, 1, 'post' ) ), 'У обычной записи переменные колоды пустые.' );
	}

	public function test_decks_of_different_classes_get_different_non_empty_text(): void {
		$mage  = new Deck( 1, 'Маг на заклинаниях', 'Маг', 'Стандартный', 'Маг на заклинаниях', 9400, 'PizzaHS', self::HUNTER_CODE, false, 'a', '2026-11-10 12:00:00' );
		$druid = new Deck( 2, 'Дракон Друид', 'Друид', 'Вольный', 'Дракон Друид', 0, '', '', false, 'b', '2026-11-11 12:00:00' );

		$mage_text  = DeckDescription::text( $mage );
		$druid_text = DeckDescription::text( $druid );

		self::assertNotSame( $mage_text, $druid_text );
		self::assertStringContainsString( 'Маг', $mage_text );
		self::assertStringContainsString( '9400', $mage_text );
		self::assertStringContainsString( 'PizzaHS', $mage_text );
		self::assertStringContainsString( 'Друид', $druid_text );
		self::assertStringNotContainsString( 'пыли', $druid_text, 'Неизвестная стоимость не упоминается.' );
		self::assertStringNotContainsString( '  ', $druid_text );
	}

	public function test_only_the_newest_live_version_is_current(): void {
		$map = DeckVersionRule::current(
			array(
				array( 'id' => 1, 'group' => 'g', 'date' => '2026-10-01 10:00:00', 'archived' => false ),
				array( 'id' => 2, 'group' => 'g', 'date' => '2026-11-01 10:00:00', 'archived' => false ),
				array( 'id' => 3, 'group' => 'g', 'date' => '2026-11-10 10:00:00', 'archived' => true ),
				array( 'id' => 4, 'group' => 'other', 'date' => '2026-09-01 10:00:00', 'archived' => false ),
				array( 'id' => 5, 'group' => '', 'date' => '2026-09-01 10:00:00', 'archived' => false ),
				array( 'id' => 6, 'group' => '', 'date' => '2026-09-02 10:00:00', 'archived' => false ),
				array( 'id' => 7, 'group' => 'old', 'date' => '2025-01-01 10:00:00', 'archived' => true ),
				array( 'id' => 8, 'group' => 'old', 'date' => '2025-02-01 10:00:00', 'archived' => true ),
			)
		);

		self::assertSame( 2, $map[1] );
		self::assertSame( 2, $map[2] );
		self::assertSame( 2, $map[3], 'Архивная версия уступает более старой неархивной.' );
		self::assertSame( 4, $map[4] );
		self::assertSame( 5, $map[5], 'Колоды без группы не объединяются.' );
		self::assertSame( 6, $map[6] );
		self::assertSame( 8, $map[7], 'Если все версии в архиве, актуальной считается самая новая.' );
	}

	public function test_deck_node_points_to_the_page_and_keeps_the_graph_consistent(): void {
		$graph = new Graph();
		$graph->add( array( '@type' => 'WebPage', '@id' => 'https://site.test/deck/mage/#webpage', 'url' => 'https://site.test/deck/mage/' ) );

		DeckSchema::add_deck(
			$graph,
			'https://site.test/',
			'https://site.test/deck/mage/',
			new Deck( 1, 'Маг на заклинаниях', 'Маг', 'Стандартный', 'Маг на заклинаниях', 9400, 'PizzaHS', self::HUNTER_CODE, false, 'a', '2026-11-10 12:00:00' )
		);

		$node = $graph->first( 'CreativeWork' );

		self::assertSame( array(), $graph->dangling() );
		self::assertSame( 'https://site.test/deck/mage/#webpage', $node['mainEntityOfPage']['@id'] );
		self::assertSame( 'Hearthstone', $node['about']['name'] );
		self::assertSame( 'PizzaHS', $graph->first( 'Person' )['name'] );
		self::assertSame( $graph->first( 'Person' )['@id'], $node['creator']['@id'] );
		self::assertSame( array( 'Маг', 'Стандартный', 'Маг на заклинаниях' ), $node['keywords'] );
	}

	public function test_archetype_page_lists_its_decks(): void {
		$graph = new Graph();
		$graph->add( array( '@type' => 'CollectionPage', '@id' => 'https://site.test/archetype/mage/#webpage', 'url' => 'https://site.test/archetype/mage/' ) );

		DeckSchema::add_deck_list(
			$graph,
			'https://site.test/archetype/mage/',
			array(
				array( 'name' => 'Маг на заклинаниях', 'url' => 'https://site.test/deck/mage-1/' ),
				array( 'name' => 'Маг на заклинаниях', 'url' => 'https://site.test/deck/mage-2/' ),
			)
		);

		$list = $graph->first( 'ItemList' );

		self::assertSame( array(), $graph->dangling() );
		self::assertCount( 2, $list['itemListElement'] );
		self::assertSame( 2, $list['itemListElement'][1]['position'] );
		self::assertSame( $list['@id'], $graph->first( 'CollectionPage' )['mainEntity']['@id'] );
	}

	public function test_deck_links_to_its_archetype_guide_and_never_to_itself(): void {
		$deck = new Deck( 1, 'Маг на заклинаниях', 'Маг', 'Стандартный', 'Маг на заклинаниях', 9400, '', '', false, 'a', '2026-11-10 12:00:00' );

		$links = RelatedLinks::pick(
			$deck,
			array(
				50 => 'Гайд: маг на заклинаниях в новом сезоне',
				51 => 'Гайд по жрецу на контроле',
				52 => 'Новости патча',
			),
			array( 1, 2, 3 )
		);

		self::assertSame( array( 50 ), $links['guides'] );
		self::assertSame( array( 2, 3 ), $links['decks'] );
	}
}
