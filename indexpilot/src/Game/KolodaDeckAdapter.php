<?php
/**
 * Колоды kolodahearthstone.com.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * На kolodahearthstone.com у колоды есть только название и код. Класс и режим читаются из самого кода колоды,
 * архетипом считается название.
 */
final class KolodaDeckAdapter implements DeckData {

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
		$title      = $this->reader->title( $post_id );
		$code       = trim( $this->reader->meta( $post_id, '_hs_deck_code' ) );
		$header     = Deckstring::decode( $code );
		$class_name = null !== $header ? Deckstring::class_name( $header['hero'] ) : '';
		$mode       = null !== $header ? Deckstring::mode_name( $header['format'] ) : '';

		return new Deck( $post_id, $title, $class_name, $mode, $title, 0, '', $code, false, Deck::group_key( $title, $class_name, $mode ), $this->reader->date( $post_id ) );
	}
}
