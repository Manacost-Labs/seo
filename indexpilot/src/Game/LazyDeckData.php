<?php
/**
 * Отложенный выбор адаптера колод.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Переменные шаблонов регистрируются раньше, чем становится известен профиль сайта.
 * Обёртка выбирает адаптер в момент первого обращения к данным колоды.
 */
final class LazyDeckData implements DeckData {

	/**
	 * Функция, возвращающая адаптер сайта.
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Конструктор.
	 *
	 * @param callable $factory Функция, возвращающая DeckData.
	 */
	public function __construct( callable $factory ) {
		$this->factory = $factory;
	}

	/**
	 * Колода по ID записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public function deck( int $post_id ): Deck {
		return ( $this->factory )()->deck( $post_id );
	}
}
