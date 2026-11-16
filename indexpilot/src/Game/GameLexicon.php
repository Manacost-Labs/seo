<?php
/** Контракт словаря без WordPress.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Game;

/** Общий интерфейс игровых словарей. */
interface GameLexicon {
	/** Обрабатывает игровые названия.
	 * @param string $text Значение.
	 */
	public function normalize( string $text ): string;
	/** Обрабатывает игровые названия.
	 * @param string $a Значение.
	 * @param string $b Значение.
	 */
	public function same( string $a, string $b ): bool;
	/** Обрабатывает игровые названия.
	 * @param string $text Значение.
	 */
	public function classes( string $text ): array;
	/** Обрабатывает игровые названия.
	 * @param string $slug Значение.
	 */
	public function class_name( string $slug ): string;
	/** Обрабатывает игровые названия.
	 * @param string $text Значение.
	 */
	public function terms( string $text ): array;
	/** Обрабатывает игровые названия.
	 */
	public function class_slugs(): array;
}
