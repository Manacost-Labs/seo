<?php
/**
 * Упоминания классов и архетипов в тексте.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Analysis\Language\RussianStemmer;

/**
 * Находит в тексте классы (по игровому словарю) и архетипы сайта (по названиям терминов) и сопоставляет им адреса
 * их страниц. Формы слов учитываются: «мага на заклинаниях» — это архетип «Маг на заклинаниях». Название, записанное
 * латиницей, находится и в русской записи на слух: «Блад ДК» — это «Blood DK». Класс не WordPress-зависимый.
 */
final class Mentions {

	public const CLASS_TYPE = 'class';
	public const ARCHETYPE  = 'archetype';

	/**
	 * Нечёткое совпадение (латиница против кириллицы) засчитывается, только если совпало столько согласных:
	 * иначе «Big» нашёлся бы в слове «бег».
	 */
	private const MIN_FUZZY = 4;

	/**
	 * Согласные кириллицы латиницей; гласные, «й», «ь» и «ъ» в остов слова не входят.
	 */
	private const CYRILLIC = array(
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'д' => 'd',
		'ж' => 'zh',
		'з' => 'z',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'ф' => 'f',
		'х' => 'h',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'sh',
	);

	/**
	 * Сочетания латинских букв, которые звучат одной согласной.
	 */
	private const LATIN = array(
		'ck' => 'k',
		'ph' => 'f',
		'th' => 't',
		'ce' => 'se',
		'ci' => 'si',
		'cy' => 'sy',
		'c'  => 'k',
		'q'  => 'k',
		'x'  => 'ks',
		'w'  => 'v',
		'j'  => 'dzh',
	);

	/**
	 * Правила архетипов: остов первого слова => правила.
	 *
	 * @var array<string, list<array{words: list<array{exact: string, skeleton: string, latin: bool}>, name: string, url: string}>>|null
	 */
	private ?array $rules = null;

	/**
	 * Русский стеммер.
	 *
	 * @var RussianStemmer
	 */
	private RussianStemmer $stemmer;

	/**
	 * Конструктор.
	 *
	 * @param Lexicon                                $lexicon    Игровой словарь: классы и их разговорные имена.
	 * @param array<string, string>                  $class_urls Адреса страниц классов: слаг класса из словаря => адрес.
	 * @param list<array{name: string, url: string}> $archetypes Архетипы сайта: название и адрес страницы.
	 */
	public function __construct( private Lexicon $lexicon, private array $class_urls = array(), private array $archetypes = array() ) {
		$this->stemmer = new RussianStemmer();
	}

	/**
	 * Упоминания по порядку: сначала архетипы, затем классы. Класс, названный только внутри архетипа
	 * («ДК» в «Блад ДК»), отдельно не считается.
	 *
	 * @param string $text Текст без разметки.
	 * @return list<array{type: string, name: string, url: string}>
	 */
	public function find( string $text ): array {
		$words  = $this->words( $text );
		$total  = count( $words );
		$rules  = $this->rules();
		$found  = array();
		$result = array();
		$rest   = array();

		for ( $i = 0; $i < $total; ) {
			$match = null;

			foreach ( $this->candidates( $words[ $i ], $rules ) as $rule ) {
				if ( ( null === $match || count( $rule['words'] ) > count( $match['words'] ) ) && $this->matches( $rule['words'], $words, $i ) ) {
					$match = $rule;
				}
			}

			if ( null === $match ) {
				$rest[] = $words[ $i ]['word'];
				++$i;
				continue;
			}

			if ( ! isset( $found[ $match['name'] ] ) ) {
				$found[ $match['name'] ] = true;
				$result[]                = array(
					'type' => self::ARCHETYPE,
					'name' => $match['name'],
					'url'  => $match['url'],
				);
			}

			// Слова архетипа в поиск классов не идут; точка не даёт склеить слова по обе стороны архетипа.
			$rest[] = '.';
			$i     += count( $match['words'] );
		}

		foreach ( $this->lexicon->classes( implode( ' ', $rest ) ) as $slug ) {
			$result[] = array(
				'type' => self::CLASS_TYPE,
				'name' => $this->lexicon->class_name( $slug ),
				'url'  => (string) ( $this->class_urls[ $slug ] ?? '' ),
			);
		}

		return $result;
	}

	/**
	 * Упоминания со страницей, на которую текст ещё не ссылается.
	 *
	 * @param list<array{type: string, name: string, url: string}> $found Упоминания.
	 * @param string                                               $html  HTML текста.
	 * @param string                                               $own   Адрес самой страницы: на себя ссылаться не нужно.
	 * @return list<array{type: string, name: string, url: string}>
	 */
	public static function unlinked( array $found, string $html, string $own = '' ): array {
		preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1/is', $html, $links );

		$linked = array();
		foreach ( $links[2] as $href ) {
			$linked[ self::path( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) ) ] = true;
		}

		if ( '' !== $own ) {
			$linked[ self::path( $own ) ] = true;
		}

		$result = array();
		foreach ( $found as $mention ) {
			if ( '' !== $mention['url'] && ! isset( $linked[ self::path( $mention['url'] ) ] ) ) {
				$result[] = $mention;
			}
		}

		return $result;
	}

	/**
	 * Путь адреса для сравнения: без схемы, сайта, параметров и косой черты в конце.
	 *
	 * @param string $url Адрес.
	 */
	private static function path( string $url ): string {
		$path = (string) parse_url( trim( $url ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress.

		return rtrim( mb_strtolower( rawurldecode( $path ), 'UTF-8' ), '/' );
	}

	/**
	 * Правила архетипов, сгруппированные по остову первого слова.
	 *
	 * @return array<string, list<array{words: list<array{exact: string, skeleton: string, latin: bool}>, name: string, url: string}>>
	 */
	private function rules(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$this->rules = array();

		foreach ( $this->archetypes as $archetype ) {
			$name  = trim( (string) ( $archetype['name'] ?? '' ) );
			$words = $this->words( $name );

			if ( array() === $words ) {
				continue;
			}

			$this->rules[ self::key( $words[0] ) ][] = array(
				'words' => $words,
				'name'  => $name,
				'url'   => (string) ( $archetype['url'] ?? '' ),
			);
		}

		return $this->rules;
	}

	/**
	 * Правила, которые могут начинаться с этого слова.
	 *
	 * @param array{word: string, exact: string, skeleton: string, latin: bool}                                                $word  Слово текста.
	 * @param array<string, list<array{words: list<array{exact: string, skeleton: string, latin: bool}>, name: string, url: string}>> $rules Правила.
	 * @return list<array{words: list<array{exact: string, skeleton: string, latin: bool}>, name: string, url: string}>
	 */
	private function candidates( array $word, array $rules ): array {
		return $rules[ self::key( $word ) ] ?? array();
	}

	/**
	 * Ключ группы правил: остов слова, а у слова из одних гласных — само слово.
	 *
	 * @param array{exact: string, skeleton: string} $word Слово.
	 */
	private static function key( array $word ): string {
		return '' !== $word['skeleton'] ? $word['skeleton'] : '=' . $word['exact'];
	}

	/**
	 * Совпадает ли название архетипа со словами текста начиная с позиции.
	 *
	 * @param list<array{exact: string, skeleton: string, latin: bool}>                     $rule  Слова названия.
	 * @param list<array{word: string, exact: string, skeleton: string, latin: bool}> $words Слова текста.
	 * @param int                                                                         $start Позиция.
	 */
	private function matches( array $rule, array $words, int $start ): bool {
		$fuzzy = 0;
		$exact = 0;

		foreach ( $rule as $offset => $expected ) {
			$word = $words[ $start + $offset ] ?? null;

			if ( null === $word ) {
				return false;
			}

			if ( $word['exact'] === $expected['exact'] ) {
				++$exact;
				continue;
			}

			// Латиница сравнивается с кириллицей по согласным: «блад» и «blood» — это bld.
			if ( $word['latin'] === $expected['latin'] || '' === $word['skeleton'] || $word['skeleton'] !== $expected['skeleton'] ) {
				return false;
			}

			$fuzzy += strlen( $word['skeleton'] );
		}

		return 0 === $fuzzy || $exact > 0 || $fuzzy >= self::MIN_FUZZY;
	}

	/**
	 * Слова текста: само слово, его основа (кириллица — через стеммер, латиница — как написана), остов из согласных.
	 *
	 * @param string $text Текст.
	 * @return list<array{word: string, exact: string, skeleton: string, latin: bool}>
	 */
	private function words( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}]+/u', str_replace( 'ё', 'е', mb_strtolower( $text, 'UTF-8' ) ), $matches );

		$words = array();
		foreach ( $matches[0] as $word ) {
			$latin   = 1 !== preg_match( '/\p{Cyrillic}/u', $word );
			$exact   = $latin ? $word : $this->stemmer->stem( $word );
			$words[] = array(
				'word'     => $word,
				'exact'    => $exact,
				'skeleton' => self::skeleton( $exact, $latin ),
				'latin'    => $latin,
			);
		}

		return $words;
	}

	/**
	 * Остов слова: согласные латиницей без повторов подряд.
	 *
	 * @param string $word  Слово в нижнем регистре.
	 * @param bool   $latin Записано латиницей.
	 */
	private static function skeleton( string $word, bool $latin ): string {
		if ( $latin ) {
			$word = (string) preg_replace( '/[aeiouy]+/', '', strtr( $word, self::LATIN ) );
		} else {
			$word = strtr( (string) preg_replace( '/[^бвгджзклмнпрстфхцчшщ0-9]+/u', '', $word ), self::CYRILLIC );
		}

		return (string) preg_replace( '/(.)\1+/', '$1', $word );
	}
}
