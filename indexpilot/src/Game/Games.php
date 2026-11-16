<?php
/** Игровые данные без WordPress.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Game;

/** Реестр игр; при пересечении корней побеждает первая игра. */
final class Games {
	/** @var array Записи игр. */
	private array $games = array();
	/** @var array Экземпляры словарей. */
	private array $lexicons = array();
	/** @var string Игра по умолчанию. */
	private string $default;
	/** Создаёт реестр.
	 * @param array $games Игры.
	 * @param string $default Игра по умолчанию.
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Имя параметра закреплено контрактом.
	public function __construct( array $games, string $default ) {
		foreach ( $games as $game ) {
			$this->games[ $game['slug'] ] = $game; }
		if ( ! isset( $this->games[ $default ] ) ) {
			throw new \InvalidArgumentException( 'Неизвестная игра по умолчанию.' ); }
		$this->default = $default;
	}
	/** Определяет игру по рубрикам.
	 * @param array $term_ancestry Рубрики и их предки.
	 * @return string
	 */
	public function for_terms( array $term_ancestry ): string {
		foreach ( $this->games as $slug => $game ) {
			foreach ( $term_ancestry as $id => $ancestors ) {
				if ( array_intersect( $game['root_term_ids'], array_merge( array( $id ), $ancestors ) ) ) {
					return $slug; }
			}
		}
		return $this->default;
	}
	/** Возвращает игру.
	 * @param string $slug Слаг.
	 * @return array
	 */
	public function get( string $slug ): array {
		if ( ! isset( $this->games[ $slug ] ) ) {
			throw new \InvalidArgumentException( 'Неизвестная игра.' ); }
		return $this->games[ $slug ];
	}
	/** Возвращает словарь.
	 * @param string $slug Слаг.
	 * @return GameLexicon
	 */
	public function lexicon( string $slug ): GameLexicon {
		if ( ! isset( $this->lexicons[ $slug ] ) ) {
			$class   = $this->get( $slug )['lexicon'];
			$lexicon = new $class();
			if ( ! $lexicon instanceof GameLexicon ) {
				throw new \InvalidArgumentException( 'Неверный класс словаря.' ); }
			$this->lexicons[ $slug ] = $lexicon;
		}
		return $this->lexicons[ $slug ];
	}
	/** Возвращает игры.
	 * @return array
	 */
	public function all(): array {
		return array_values( $this->games ); }
	/** Возвращает начальный набор.
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			array(
				'slug'          => 'hearthstone',
				'name'          => 'Hearthstone',
				'root_term_ids' => array(),
				'schema_name'   => 'Hearthstone',
				'lexicon'       => Lexicon::class,
			),
			array(
				'slug'          => 'wow',
				'name'          => 'World of Warcraft',
				'root_term_ids' => array(),
				'schema_name'   => 'World of Warcraft',
				'lexicon'       => Lexicons\Wow::class,
			),
		);
	}
}
