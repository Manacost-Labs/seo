<?php
/**
 * Колоды hs-manacost.ru.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * На hs-manacost.ru класс, режим и архетип — таксономии; стоимость, игрок, код, ключ архетипа и признак архива — поля записи.
 */
final class ManacostDeckAdapter implements DeckData {

	/**
	 * Конструктор.
	 *
	 * @param PostReader $reader Чтение записей.
	 */
	public function __construct( private PostReader $reader ) {
	}

	/**
	 * Колода по ID записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public function deck( int $post_id ): Deck {
		$class_name = $this->term( $post_id, 'deck_class' );
		$mode       = $this->term( $post_id, 'deck_mode' );
		$archetype  = $this->term( $post_id, 'deck_archetype' );
		$key        = Deck::clean( $this->reader->meta( $post_id, '_hs_archetype_key' ) );
		$player     = Deck::clean( $this->reader->meta( $post_id, '_deck_player' ) );

		// У части колод игрок записан только в поле стримера.
		if ( '' === $player ) {
			$player = Deck::clean( $this->reader->meta( $post_id, '_deck_streamer' ) );
		}

		return new Deck(
			$post_id,
			$this->reader->title( $post_id ),
			$class_name,
			$mode,
			$archetype,
			max( 0, (int) $this->reader->meta( $post_id, '_dust_cost' ) ),
			$player,
			trim( $this->reader->meta( $post_id, '_deck_code' ) ),
			'1' === $this->reader->meta( $post_id, '_hs_deck_archived' ),
			// Ключ архетипа надёжнее названия: его ведёт плагин колод, и он одинаков у всех версий.
			Deck::group_key( '' !== $key ? $key : $archetype, $class_name, $mode ),
			$this->reader->date( $post_id )
		);
	}

	/**
	 * Первый термин записи в таксономии.
	 *
	 * @param int    $post_id  ID записи.
	 * @param string $taxonomy Таксономия.
	 */
	private function term( int $post_id, string $taxonomy ): string {
		return Deck::clean( (string) ( $this->reader->terms( $post_id, $taxonomy )[0] ?? '' ) );
	}
}
