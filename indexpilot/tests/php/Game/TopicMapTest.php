<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Game\Lexicon;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\TopicMap;
use PHPUnit\Framework\TestCase;

final class TopicMapTest extends TestCase {

	private function material( string $title, string $type, array $archetypes = array() ): array {
		return array(
			'id'         => crc32( $title ),
			'title'      => $title,
			'type'       => $type,
			'archetypes' => $archetypes,
		);
	}

	public function test_class_with_guide_and_deck_but_no_mulligan_has_mulligan_gap(): void {
		$map    = new TopicMap( new Lexicon() );
		$topics = array(
			array( 'key' => 'class:priest', 'name' => 'Жрец', 'kind' => 'class', 'slug' => 'priest' ),
			array( 'key' => 'class:mage', 'name' => 'Маг', 'kind' => 'class', 'slug' => 'mage' ),
		);
		$result = $map->build(
			$topics,
			array(
				$this->material( 'Гайд на жреца: как играть', MaterialType::GUIDE ),
				$this->material( 'Колода приста на легенду', MaterialType::DECK ),
				$this->material( 'Бюджетная колода мага', MaterialType::DECK ),
			),
			array()
		);

		$priest = $result[0];
		self::assertSame( 'class:priest', $priest['key'] );
		self::assertContains( 'mulligan', $priest['gaps'] );
		self::assertNotContains( 'guide', $priest['gaps'] );
		self::assertNotContains( 'legend', $priest['gaps'] );
		self::assertNotContains( 'best', $priest['gaps'] );
		self::assertCount( 2, $priest['materials'] );
		self::assertSame( 'Муллиган', TopicMap::question( 'mulligan' ) );

		self::assertNotContains( 'budget', $result[1]['gaps'] );
		self::assertContains( 'guide', $result[1]['gaps'] );
	}

	public function test_queries_add_demand_and_reorder_gaps(): void {
		$map    = new TopicMap( new Lexicon() );
		$result = $map->build(
			array( array( 'key' => 'archetype:7', 'name' => 'Мех-разбойник', 'kind' => 'archetype', 'slug' => '' ) ),
			array( $this->material( 'Колода', MaterialType::DECK, array( 'мех-разбойник' ) ) ),
			array(
				array( 'query' => 'мех разбойник против кого', 'impressions' => 40 ),
				array( 'query' => 'мех разбойник муллиган', 'impressions' => 300 ),
				array( 'query' => 'колода мага', 'impressions' => 900 ),
			)
		);

		self::assertSame( array( 'mulligan', 'matchups' ), array_slice( $result[0]['gaps'], 0, 2 ) );
		self::assertSame( 300, $result[0]['demand']['mulligan'] );
		self::assertSame( 340, $result[0]['impressions'] );
	}
}
