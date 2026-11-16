<?php
/**
 * Разметка колоды и страницы архетипа.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Schema\Graph;
use IndexPilot\Schema\IdFactory;

/**
 * Добавляет в граф страницы узлы игрового модуля: колоду и список колод архетипа.
 * В schema.org нет типа «колода», поэтому колода описывается как творческая работа об игре Hearthstone.
 */
final class DeckSchema {

	/**
	 * Узел колоды. Игрок, с которым связана колода, добавляется отдельным узлом Person:
	 * он не обязан быть пользователем сайта.
	 *
	 * @param Graph  $graph Граф страницы.
	 * @param string $home  Адрес главной страницы.
	 * @param string $url   Адрес страницы колоды.
	 * @param Deck   $deck  Колода.
	 * @param string $utc_offset Смещение часового пояса сайта на дату публикации колоды, например «+03:00».
	 */
	public static function add_deck( Graph $graph, string $home, string $url, Deck $deck, string $utc_offset = '+00:00' ): void {
		$player_id = '';

		if ( '' !== $deck->player ) {
			$player_id = IdFactory::player( $home, $deck->player );

			$graph->add(
				array(
					'@type' => 'Person',
					'@id'   => $player_id,
					'name'  => $deck->player,
				)
			);
		}

		$graph->add(
			array(
				'@type'            => 'CreativeWork',
				'@id'              => IdFactory::part( $url, 'deck' ),
				'name'             => $deck->title,
				'about'            => array(
					'@type' => 'VideoGame',
					'name'  => 'Hearthstone',
				),
				'genre'            => $deck->mode,
				'keywords'         => array_values( array_filter( array( $deck->hero_class, $deck->mode, $deck->archetype ), 'strlen' ) ),
				'creator'          => '' !== $player_id ? array( '@id' => $player_id ) : null,
				// Полная дата ISO 8601 с часовым поясом — в том же виде, что у узла статьи.
				'datePublished'    => '' !== $deck->date ? str_replace( ' ', 'T', $deck->date ) . ( 1 === preg_match( '/^[+-]\d{2}:\d{2}$/', $utc_offset ) ? $utc_offset : '+00:00' ) : '',
				'mainEntityOfPage' => array( '@id' => IdFactory::part( $url, 'webpage' ) ),
			)
		);
	}

	/**
	 * Список колод на странице архетипа: главный объект страницы.
	 *
	 * @param Graph                                  $graph Граф страницы.
	 * @param string                                 $url   Адрес страницы архетипа.
	 * @param list<array{name: string, url: string}> $decks Колоды в порядке показа.
	 */
	public static function add_deck_list( Graph $graph, string $url, array $decks ): void {
		$items    = array();
		$position = 0;

		foreach ( $decks as $deck ) {
			if ( '' === (string) ( $deck['url'] ?? '' ) ) {
				continue;
			}

			$items[] = array(
				'@type'    => 'ListItem',
				'position' => ++$position,
				'name'     => (string) ( $deck['name'] ?? '' ),
				'url'      => (string) $deck['url'],
			);
		}

		$page = $graph->node( IdFactory::part( $url, 'webpage' ) );

		if ( array() === $items || null === $page ) {
			return;
		}

		$list_id = IdFactory::part( $url, 'decks' );

		$graph->add(
			array(
				'@type'           => 'ItemList',
				'@id'             => $list_id,
				'numberOfItems'   => count( $items ),
				'itemListElement' => $items,
			)
		);

		$graph->add( $page + array( 'mainEntity' => array( '@id' => $list_id ) ) );
	}
}
