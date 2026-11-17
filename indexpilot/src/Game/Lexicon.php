<?php
/**
 * Игровой словарь без зависимости от WordPress.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Game;

use IndexPilot\Analysis\Language\RussianStemmer;
/** Сопоставляет игровые названия и словоформы. */
final class Lexicon implements GameLexicon {
	/** @var array Названия классов. */
	public const NAMES = array(
		'death-knight' => 'Рыцарь смерти',
		'demon-hunter' => 'Охотник на демонов',
		'druid'        => 'Друид',
		'hunter'       => 'Охотник',
		'mage'         => 'Маг',
		'paladin'      => 'Паладин',
		'priest'       => 'Жрец',
		'rogue'        => 'Разбойник',
		'shaman'       => 'Шаман',
		'warlock'      => 'Чернокнижник',
		'warrior'      => 'Воин',
	);
	/**
	 * Короткие разговорные имена: слово => его допустимые формы. Такие слова сравниваются как написаны, без стеммера:
	 * «локов» и «шама» — классы, а «локация», «шампунь», «палка» и «пала» — нет.
	 *
	 * @var array<string, list<string>>
	 */
	private const FORMS = array(
		'дк'    => array( 'дк' ),
		'дх'    => array( 'дх' ),
		'дру'   => array( 'дру' ),
		'бг'    => array( 'бг' ),
		'хант'  => array( 'хант', 'ханта', 'ханту', 'хантом', 'ханте', 'ханты', 'хантов', 'хантам', 'хантами', 'хантах' ),
		// Без формы «пала»: это ещё и глагол.
		'пал'   => array( 'пал', 'палу', 'палом', 'пале', 'палы', 'палов', 'палам', 'палами', 'палах' ),
		'прист' => array( 'прист', 'приста', 'присту', 'пристом', 'присте', 'присты', 'пристов', 'пристам', 'пристами', 'пристах' ),
		// Без форм «рог», «рогов», «рогам»: это рога, а не разбойник.
		'рога'  => array( 'рога', 'роги', 'роге', 'рогу', 'рогой' ),
		'шам'   => array( 'шам', 'шама', 'шаму', 'шамом', 'шаме', 'шамы', 'шамов', 'шамам', 'шамами', 'шамах' ),
		'лок'   => array( 'лок', 'лока', 'локу', 'локом', 'локе', 'локи', 'локов', 'локам', 'локами', 'локах' ),
		'вар'   => array( 'вар', 'вара', 'вару', 'варом', 'варе', 'вары', 'варов', 'варам', 'варами', 'варах' ),
	);
	/** @var array<string, list<string>> Дополнительные синонимы владельца. */
	private array $extra_synonyms;
	/** @var array<string, list<string>> Дополнительные имена классов. */
	private array $extra_classes;
	/** @var array<string, array{stems: array<string, list<array{0: list<string>, 1: string}>>, forms: array<string, string>}>|null Наборы правил all, class и term; строятся при первом обращении. */
	private ?array $sets = null;
	/** @var RussianStemmer Русский стеммер. */
	private RussianStemmer $stemmer;
	/**
	 * Собирает словарь. Правила строятся при первом обращении: на страницах сайта словарь не нужен и ничего не стоит.
	 * @param array<string, list<string>> $extra_synonyms Дополнительные синонимы.
	 * @param array<string, list<string>> $extra_classes Дополнительные имена классов.
	 */
	public function __construct( array $extra_synonyms = array(), array $extra_classes = array() ) {
		$this->stemmer        = new RussianStemmer();
		$this->extra_synonyms = $extra_synonyms;
		$this->extra_classes  = $extra_classes;
	}
	/**
	 * Разбирает поле настроек «Свои синонимы»: по строке на группу, «колода = дека, сборка».
	 * @param string $text Текст поля.
	 * @return array<string, list<string>> Каноническое слово => синонимы.
	 */
	public static function parse_synonyms( string $text ): array {
		$groups = array();
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			$parts = explode( '=', $line, 2 );
			$word  = self::clean( $parts[0] );
			$list  = array_values( array_filter( array_map( array( self::class, 'clean' ), explode( ',', $parts[1] ?? '' ) ), 'strlen' ) );
			if ( '' !== $word && array() !== $list ) {
				$groups[ $word ] = array_values( array_unique( array_merge( $groups[ $word ] ?? array(), $list ) ) );
			}
		}
		return $groups;
	}
	/**
	 * Нормализует фразу.
	 * @param string $text Исходный текст.
	 */
	public function normalize( string $text ): string {
		$result = array();
		foreach ( $this->scan( $text, 'all' ) as $token ) {
			$result[] = null === $token[0] ? $token[2] : implode( ' ', array_map( array( $this->stemmer, 'stem' ), $this->split( $token[0] ) ) );
		}
		return implode( ' ', $result );
	}
	/**
	 * Сравнивает фразы.
	 * @param string $a Первая фраза.
	 * @param string $b Вторая фраза.
	 */
	public function same( string $a, string $b ): bool {
		return $this->normalize( $a ) === $this->normalize( $b );
	}
	/**
	 * Слова текста в нижнем регистре, в которых синонимы и разговорные имена заменены каноническими словами:
	 * «дека приста» → «колода», «жрец». Остальные слова остаются как написаны — основы считает тот, кто сравнивает.
	 * @param string $text Исходный текст.
	 * @return list<string>
	 */
	public function canonical_words( string $text ): array {
		$result = array();
		foreach ( $this->scan( $text, 'all' ) as $token ) {
			if ( null === $token[0] ) {
				$result[] = $token[1];
			} else {
				array_push( $result, ...$this->split( $token[0] ) );
			}
		}
		return $result;
	}
	/**
	 * Возвращает классы по порядку упоминания.
	 * @param string $text Исходный текст.
	 * @return list<string>
	 */
	public function classes( string $text ): array {
		return $this->found( $text, 'class' );
	}
	/**
	 * Возвращает название класса.
	 * @param string $slug Слаг класса.
	 */
	public function class_name( string $slug ): string {
		return self::NAMES[ $slug ] ?? '';
	}
	/**
	 * Возвращает термины по порядку упоминания.
	 * @param string $text Исходный текст.
	 * @return list<string>
	 */
	public function terms( string $text ): array {
		return $this->found( $text, 'term' );
	}
	/**
	 * Возвращает все слаги классов.
	 * @return list<string>
	 */
	public function class_slugs(): array {
		return array_keys( self::NAMES );
	}
	/**
	 * Найденные значения набора без повторов, по порядку упоминания.
	 * @param string $text Исходный текст.
	 * @param string $set Набор правил.
	 * @return list<string>
	 */
	private function found( string $text, string $set ): array {
		$values = array();
		foreach ( $this->scan( $text, $set ) as $token ) {
			if ( null !== $token[0] ) {
				$values[ $token[0] ] = true;
			}
		}
		return array_map( 'strval', array_keys( $values ) );
	}
	/**
	 * Слово или фраза из настроек: нижний регистр, без лишних пробелов.
	 * @param string $text Текст.
	 */
	private static function clean( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $text, 'UTF-8' ) ) );
	}
	/**
	 * Разбивает текст на слова в нижнем регистре; «ё» приравнивается к «е».
	 * @param string $text Исходный текст.
	 * @return list<string>
	 */
	private function split( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}]+/u', str_replace( 'ё', 'е', mb_strtolower( $text, 'UTF-8' ) ), $matches );
		return $matches[0];
	}
	/**
	 * Наборы правил: встроенные слова и дополнения владельца.
	 * @return array<string, array{stems: array<string, list<array{0: list<string>, 1: string}>>, forms: array<string, string>}>
	 */
	private function sets(): array {
		if ( null !== $this->sets ) {
			return $this->sets;
		}
		$empty      = array(
			'stems' => array(),
			'forms' => array(),
		);
		$this->sets = array(
			'all'   => $empty,
			'class' => $empty,
			'term'  => $empty,
		);
		$groups     = array(
			'колода'   => array( 'дека', 'сборка', 'билд', 'деклист', 'дек' ),
			'гайд'     => array( 'руководство', 'разбор', 'гид' ),
			'муллиган' => array( 'мулиган', 'стартовая рука' ),
			'матчап'   => array( 'матчапы', 'противостояние' ),
			'замена'   => array( 'замены', 'альтернатива' ),
		);
		foreach ( $this->extra_synonyms as $canonical => $aliases ) {
			$groups[ $canonical ] = array_merge( $groups[ $canonical ] ?? array(), (array) $aliases );
		}
		foreach ( $groups as $canonical => $aliases ) {
			$this->add( 'all', (string) $canonical, array_merge( array( (string) $canonical ), $aliases ) );
		}
		$aliases = array(
			'death-knight' => array( 'дк' ),
			'demon-hunter' => array( 'дх' ),
			'druid'        => array( 'дру' ),
			'hunter'       => array( 'хант' ),
			'mage'         => array(),
			'paladin'      => array( 'пал' ),
			'priest'       => array( 'прист' ),
			'rogue'        => array( 'рога' ),
			'shaman'       => array( 'шам' ),
			'warlock'      => array( 'лок', 'варлок' ),
			'warrior'      => array( 'вар' ),
		);
		foreach ( self::NAMES as $slug => $name ) {
			$words = array_merge( array( $name ), $aliases[ $slug ], (array) ( $this->extra_classes[ $slug ] ?? array() ) );
			$this->add( 'class', $slug, $words );
			$this->add( 'all', $name, $words );
		}
		$terms = array(
			'archetype'     => array( 'архетип' ),
			'mulligan'      => array( 'муллиган', 'мулиган', 'стартовая рука' ),
			'matchup'       => array( 'матчап', 'противостояние' ),
			'meta'          => array( 'мета', 'метагейм' ),
			'tier-list'     => array( 'тир-лист', 'тир лист', 'тирлист' ),
			'battlegrounds' => array( 'поля сражений', 'поля боя', 'столы', 'бг' ),
			'arena'         => array( 'арена' ),
			'replacement'   => array( 'замена', 'альтернатива' ),
			'winrate'       => array( 'винрейт', 'процент побед' ),
			'legend'        => array( 'легенда', 'легенды ранг' ),
		);
		foreach ( $terms as $slug => $words ) {
			$this->add( 'term', $slug, $words );
		}
		// Длинное название проверяется раньше короткого: «охотник на демонов» — не «охотник».
		foreach ( $this->sets as $set => $rules ) {
			foreach ( $rules['stems'] as $first => $list ) {
				usort( $list, static fn( array $a, array $b ): int => count( $b[0] ) <=> count( $a[0] ) );
				$this->sets[ $set ]['stems'][ $first ] = $list;
			}
		}
		return $this->sets;
	}
	/**
	 * Добавляет варианты названия в набор правил.
	 * @param string       $set     Набор правил.
	 * @param string       $value   Каноническое значение.
	 * @param list<string> $aliases Варианты.
	 */
	private function add( string $set, string $value, array $aliases ): void {
		foreach ( $aliases as $alias ) {
			$words = $this->split( (string) $alias );
			if ( array() === $words ) {
				continue;
			}
			if ( 1 === count( $words ) && isset( self::FORMS[ $words[0] ] ) ) {
				foreach ( self::FORMS[ $words[0] ] as $form ) {
					$this->sets[ $set ]['forms'][ $form ] ??= $value;
				}
				continue;
			}
			$this->sets[ $set ]['stems'][ $this->stemmer->stem( $words[0] ) ][] = array( array_map( array( $this->stemmer, 'stem' ), $words ), $value );
		}
	}
	/**
	 * Проходит по словам текста и отмечает самые длинные непересекающиеся названия.
	 * @param string $text Исходный текст.
	 * @param string $set Набор правил.
	 * @return list<array{0: string|null, 1: string, 2: string}> Найденное значение или null, слово текста, его основа. У названия из нескольких слов — первое слово.
	 */
	private function scan( string $text, string $set ): array {
		$rules  = $this->sets()[ $set ];
		$words  = $this->split( $text );
		$stems  = array_map( array( $this->stemmer, 'stem' ), $words );
		$total  = count( $words );
		$result = array();
		for ( $i = 0; $i < $total; ) {
			$value = null;
			$size  = 1;
			foreach ( $rules['stems'][ $stems[ $i ] ] ?? array() as $rule ) {
				if ( array_slice( $stems, $i, count( $rule[0] ) ) === $rule[0] ) {
					$value = $rule[1];
					$size  = count( $rule[0] );
					break;
				}
			}
			if ( null === $value && isset( $rules['forms'][ $words[ $i ] ] ) ) {
				$value = $rules['forms'][ $words[ $i ] ];
			}
			$result[] = array( $value, $words[ $i ], $stems[ $i ] );
			$i       += $size;
		}
		return $result;
	}
}
