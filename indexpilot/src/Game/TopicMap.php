<?php
/**
 * Карта тем по классам и архетипам.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Для каждого класса и архетипа — вопросы, которые люди задают вокруг темы: как играть, лучшая и бюджетная колода,
 * муллиган, против кого сильна и слаба, для новичка, для легенды. Показывает, на какой вопрос есть материал,
 * а на какой нет. Материал узнаётся по типу и названию; если есть данные поиска, у пробела появляется спрос —
 * показы запросов о теме с этим вопросом, и пробелы с большим спросом встают первыми.
 */
final class TopicMap {

	/**
	 * Вопрос => название, слова в названии материала или запросе, типы материалов, которые на него отвечают.
	 */
	private const QUESTIONS = array(
		'guide'    => array( 'Как играть (гайд)', 'гайд|как играть|руководств|разбор', array( MaterialType::GUIDE ) ),
		'best'     => array( 'Лучшая колода', 'лучш|топ|сильнейш', array( MaterialType::DECK, MaterialType::TIER_LIST, MaterialType::META ) ),
		'budget'   => array( 'Бюджетная колода', 'бюджет|дешев|дешёв|недорог|без легендар', array() ),
		'mulligan' => array( 'Муллиган', 'муллиган|мулиган|стартов', array( MaterialType::MULLIGAN ) ),
		'matchups' => array( 'Против кого сильна и слаба', 'матчап|против', array( MaterialType::MATCHUPS ) ),
		'beginner' => array( 'Для новичка', 'новичк|начинающ', array() ),
		'legend'   => array( 'Для легенды', 'легенд(?!арн)', array() ),
	);

	/**
	 * Конструктор.
	 *
	 * @param GameLexicon $lexicon Словарь игры: классы и разговорные имена.
	 */
	public function __construct( private GameLexicon $lexicon ) {
	}

	/**
	 * Название вопроса.
	 *
	 * @param string $key Ключ вопроса.
	 */
	public static function question( string $key ): string {
		return self::QUESTIONS[ $key ][0] ?? $key;
	}

	/**
	 * Ключи вопросов по порядку.
	 *
	 * @return list<string>
	 */
	public static function questions(): array {
		return array_keys( self::QUESTIONS );
	}

	/**
	 * Строит карту.
	 *
	 * @param list<array{key: string, name: string, kind: string, slug: string}>                                $topics    Темы: класс (slug — слаг класса словаря) или архетип.
	 * @param list<array{id: int, title: string, type: string, archetypes?: list<string>, classes?: list<string>}> $materials Материалы сайта: название, тип, архетипы и классы из рубрик.
	 * @param list<array{query: string, impressions: int}>                                                    $queries   Запросы из поиска; пусто — карта строится только по материалам.
	 * @return list<array{key: string, name: string, kind: string, materials: list<array{id: int, title: string}>, covered: array<string, list<int>>, gaps: list<string>, demand: array<string, int>, impressions: int}>
	 */
	public function build( array $topics, array $materials, array $queries ): array {
		$prepared = array();

		foreach ( $materials as $material ) {
			$title      = (string) $material['title'];
			$prepared[] = array(
				'id'         => (int) $material['id'],
				'title'      => $title,
				'answers'    => $this->answers( $title, (string) $material['type'] ),
				'classes'    => array_merge( $this->lexicon->classes( $title ), (array) ( $material['classes'] ?? array() ) ),
				'archetypes' => array_map( static fn( $name ): string => mb_strtolower( (string) $name, 'UTF-8' ), (array) ( $material['archetypes'] ?? array() ) ),
				'words'      => $this->words( $title ),
			);
		}

		$prepared_queries = array();

		foreach ( $queries as $query ) {
			$text               = (string) $query['query'];
			$prepared_queries[] = array(
				'impressions' => (int) $query['impressions'],
				'answers'     => $this->answers( $text, '' ),
				'classes'     => $this->lexicon->classes( $text ),
				'words'       => $this->words( $text ),
			);
		}

		$map = array();

		foreach ( $topics as $topic ) {
			$item = array(
				'key'         => (string) $topic['key'],
				'name'        => (string) $topic['name'],
				'kind'        => (string) $topic['kind'],
				'materials'   => array(),
				'covered'     => array(),
				'gaps'        => array(),
				'demand'      => array(),
				'impressions' => 0,
			);

			foreach ( $prepared as $material ) {
				if ( ! $this->belongs( $topic, $material ) ) {
					continue;
				}

				$item['materials'][] = array(
					'id'    => $material['id'],
					'title' => $material['title'],
				);

				foreach ( $material['answers'] as $question ) {
					$item['covered'][ $question ][] = $material['id'];
				}
			}

			foreach ( $prepared_queries as $query ) {
				if ( ! $this->belongs( $topic, $query + array( 'archetypes' => array() ) ) ) {
					continue;
				}

				$item['impressions'] += $query['impressions'];

				foreach ( $query['answers'] as $question ) {
					$item['demand'][ $question ] = ( $item['demand'][ $question ] ?? 0 ) + $query['impressions'];
				}
			}

			$order = array_flip( self::questions() );
			$gaps  = array_values( array_diff( self::questions(), array_keys( $item['covered'] ) ) );
			usort(
				$gaps,
				static fn( string $a, string $b ): int => array( $item['demand'][ $b ] ?? 0, $order[ $a ] ) <=> array( $item['demand'][ $a ] ?? 0, $order[ $b ] )
			);
			$item['gaps'] = $gaps;
			$map[]        = $item;
		}

		return $map;
	}

	/**
	 * На какие вопросы отвечает материал или о чём спрашивает запрос.
	 *
	 * @param string $text Название или запрос.
	 * @param string $type Тип материала; пусто — запрос.
	 * @return list<string>
	 */
	private function answers( string $text, string $type ): array {
		$text    = mb_strtolower( $text, 'UTF-8' );
		$answers = array();

		foreach ( self::QUESTIONS as $key => $question ) {
			if ( in_array( $type, $question[2], true ) || 1 === preg_match( '/(?<!\p{L})(?:' . $question[1] . ')/u', $text ) ) {
				$answers[] = $key;
			}
		}

		return $answers;
	}

	/**
	 * Относится ли материал или запрос к теме.
	 *
	 * @param array{key: string, name: string, kind: string, slug: string} $topic Тема.
	 * @param array<string, mixed>                                          $item  Материал или запрос.
	 */
	private function belongs( array $topic, array $item ): bool {
		if ( 'class' === $topic['kind'] ) {
			return in_array( (string) $topic['slug'], (array) $item['classes'], true );
		}

		$name = mb_strtolower( (string) $topic['name'], 'UTF-8' );

		if ( in_array( $name, (array) $item['archetypes'], true ) ) {
			return true;
		}

		$words = $this->words( $name );

		return array() !== $words && array() === array_diff( $words, (array) $item['words'] );
	}

	/**
	 * Нормализованные слова фразы: основы и канонические имена словаря.
	 *
	 * @param string $text Фраза.
	 * @return list<string>
	 */
	private function words( string $text ): array {
		return array_values( array_filter( explode( ' ', $this->lexicon->normalize( $text ) ), 'strlen' ) );
	}
}
