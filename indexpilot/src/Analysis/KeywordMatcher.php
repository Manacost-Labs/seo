<?php
/**
 * Поиск ключевого слова в тексте с учётом словоформ.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Analysis\Language\Stemmer;
use IndexPilot\Game\GameLexicon;
use IndexPilot\Text\Transliterator;

/**
 * Ключ считается найденным, если основы всех его слов стоят рядом в любом порядке,
 * допускается до двух посторонних слов между ними: «колода для мага» совпадает с ключом «колода мага».
 * С игровым словарём синонимы и разговорные имена классов приводятся к одному слову: «дека приста» — это «колода жреца».
 */
final class KeywordMatcher {

	/**
	 * Сколько посторонних слов допускается внутри ключевой фразы.
	 */
	private const EXTRA_WORDS = 2;

	/**
	 * Строки не длиннее этого (в байтах) запоминаются: это ключи, а не тексты.
	 */
	private const MEMO_LENGTH = 200;

	/**
	 * Сколько строк каждого вида помнить; при переполнении память очищается.
	 */
	private const MEMO_SIZE = 20000;

	/**
	 * Запомненные основы коротких строк: вид → строка → основы.
	 *
	 * @var array<string, array<string, list<string>>>
	 */
	private array $memo = array();

	/**
	 * Служебные слова, которые не участвуют в сравнении.
	 */
	private const STOP_WORDS = array( 'и', 'в', 'во', 'на', 'для', 'по', 'от', 'с', 'со', 'к', 'о', 'об', 'из', 'за', 'у', 'the', 'a', 'an', 'of', 'for', 'in', 'on', 'to', 'and' );

	/**
	 * Конструктор.
	 *
	 * @param Stemmer      $russian Стеммер для слов кириллицей.
	 * @param Stemmer      $english Стеммер для слов латиницей.
	 * @param GameLexicon|null $lexicon Игровой словарь; null — сравниваются только формы слов.
	 */
	public function __construct(
		private Stemmer $russian,
		private Stemmer $english,
		private ?GameLexicon $lexicon = null
	) {
	}

	/**
	 * Слова текста в нижнем регистре.
	 *
	 * @param string $text Простой текст.
	 * @return list<string>
	 */
	public function words( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $text ), $matches );

		return $matches[0];
	}

	/**
	 * Слова текста для сравнения: с игровым словарём синонимы заменены каноническими словами, без него — слова как есть.
	 * Число слов может отличаться от words(): «дк» становится словами «рыцарь», «смерти».
	 *
	 * @param string $text Простой текст.
	 * @return list<string>
	 */
	public function canonical_words( string $text ): array {
		return null === $this->lexicon ? $this->words( $text ) : $this->lexicon->canonical_words( $text );
	}

	/**
	 * Основы слов текста — по одной на каждое слово из canonical_words().
	 *
	 * @param string $text Простой текст.
	 * @return list<string>
	 */
	public function stems( string $text ): array {
		// Короткие строки (ключи) сравниваются тысячами — при проверке занятого ключа каждый ключ сайта
		// сопоставляется с ключом записи. Их основы запоминаются; длинные тексты не запоминаются.
		if ( strlen( $text ) > self::MEMO_LENGTH ) {
			return array_map( array( $this, 'stem' ), $this->canonical_words( $text ) );
		}

		return $this->memo( 'stems', $text, fn(): array => array_map( array( $this, 'stem' ), $this->canonical_words( $text ) ) );
	}

	/**
	 * Запомненный результат для короткой строки.
	 *
	 * @param string            $kind  Вид результата.
	 * @param string            $text  Строка.
	 * @param callable(): array $build Вычисление.
	 * @return list<string>
	 */
	private function memo( string $kind, string $text, callable $build ): array {
		if ( ! isset( $this->memo[ $kind ][ $text ] ) ) {
			if ( count( $this->memo[ $kind ] ?? array() ) >= self::MEMO_SIZE ) {
				$this->memo[ $kind ] = array();
			}

			$this->memo[ $kind ][ $text ] = $build();
		}

		return $this->memo[ $kind ][ $text ];
	}

	/**
	 * Встречается ли ключ в тексте.
	 *
	 * @param string $text    Простой текст.
	 * @param string $keyword Ключевое слово или фраза.
	 */
	public function contains( string $text, string $keyword ): bool {
		return $this->position( $text, $keyword ) >= 0;
	}

	/**
	 * Номер слова (с нуля), с которого начинается первое вхождение ключа; -1, если ключа нет.
	 *
	 * @param string $text    Простой текст.
	 * @param string $keyword Ключевое слово или фраза.
	 */
	public function position( string $text, string $keyword ): int {
		$matches = $this->find( $this->stems( $text ), $this->keyword_stems( $keyword ), 1 );

		return $matches[0] ?? -1;
	}

	/**
	 * Число непересекающихся вхождений ключа.
	 *
	 * @param string $text    Простой текст.
	 * @param string $keyword Ключевое слово или фраза.
	 */
	public function count( string $text, string $keyword ): int {
		return count( $this->find( $this->stems( $text ), $this->keyword_stems( $keyword ), PHP_INT_MAX ) );
	}

	/**
	 * Есть ли ключ в слаге. Русские слова сравниваются в транслитерации, по началу слова.
	 *
	 * @param string $slug    Слаг записи.
	 * @param string $keyword Ключевое слово или фраза.
	 */
	public function in_slug( string $slug, string $keyword ): bool {
		$slug_words = array_filter( explode( '-', strtolower( $slug ) ), 'strlen' );

		// Слаг может быть написан и словами самого ключа («deka-prista»), и каноническими («koloda-zhreca»).
		return $this->stems_in_slug( $slug_words, $this->significant_stems( $this->words( $keyword ) ) )
			|| ( null !== $this->lexicon && $this->stems_in_slug( $slug_words, $this->keyword_stems( $keyword ) ) );
	}

	/**
	 * Одинаковы ли два ключа: каждый содержит другой («колода мага» и «колоды для мага»; со словарём — и «дека мага»).
	 *
	 * @param string $first  Первый ключ.
	 * @param string $second Второй ключ.
	 */
	public function same( string $first, string $second ): bool {
		return $this->contains( $first, $second ) && $this->contains( $second, $first );
	}

	/**
	 * Все ли основы ключа есть в слаге.
	 *
	 * @param array<int, string> $slug_words Слова слага.
	 * @param list<string>       $stems      Основы слов ключа.
	 */
	private function stems_in_slug( array $slug_words, array $stems ): bool {
		if ( array() === $stems ) {
			return false;
		}

		foreach ( $stems as $stem ) {
			$latin = Transliterator::slugify( $stem );
			$found = false;

			foreach ( $slug_words as $slug_word ) {
				if ( '' !== $latin && str_starts_with( $slug_word, $latin ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Число значимых слов в ключе.
	 *
	 * @param string $keyword Ключевое слово или фраза.
	 */
	public function length( string $keyword ): int {
		return count( $this->keyword_stems( $keyword ) );
	}

	/**
	 * Основы слов ключа без служебных слов.
	 *
	 * @param string $keyword Ключевое слово или фраза.
	 * @return list<string>
	 */
	private function keyword_stems( string $keyword ): array {
		if ( strlen( $keyword ) > self::MEMO_LENGTH ) {
			return $this->significant_stems( $this->canonical_words( $keyword ) );
		}

		return $this->memo( 'keyword', $keyword, fn(): array => $this->significant_stems( $this->canonical_words( $keyword ) ) );
	}

	/**
	 * Основы слов без служебных слов.
	 *
	 * @param list<string> $words Слова в нижнем регистре.
	 * @return list<string>
	 */
	private function significant_stems( array $words ): array {
		$significant = array_values( array_diff( $words, self::STOP_WORDS ) );

		// Ключ из одних служебных слов сравнивается как есть.
		return array_map( array( $this, 'stem' ), array() === $significant ? $words : $significant );
	}

	/**
	 * Ищет вхождения ключа.
	 *
	 * @param list<string> $text    Основы слов текста.
	 * @param list<string> $keyword Основы слов ключа.
	 * @param int          $limit   Сколько вхождений нужно найти.
	 * @return list<int> Номера слов, с которых начинаются вхождения.
	 */
	private function find( array $text, array $keyword, int $limit ): array {
		$found = array();
		$need  = count( $keyword );
		$total = count( $text );

		if ( 0 === $need || 0 === $total ) {
			return $found;
		}

		$window = $need + self::EXTRA_WORDS;
		$index  = 0;
		$hits   = 0;

		while ( $index < $total && $hits < $limit ) {
			// Вхождение начинается со слова ключа, иначе одно и то же место считалось бы несколько раз.
			if ( ! in_array( $text[ $index ], $keyword, true ) ) {
				++$index;
				continue;
			}

			$remaining = array_count_values( $keyword );
			$last      = -1;

			for ( $offset = 0; $offset < $window && $index + $offset < $total; $offset++ ) {
				$stem = $text[ $index + $offset ];

				if ( ! empty( $remaining[ $stem ] ) ) {
					--$remaining[ $stem ];
					$last = $index + $offset;
				}
			}

			if ( 0 === array_sum( $remaining ) ) {
				$found[] = $index;
				++$hits;
				$index = $last + 1;
			} else {
				++$index;
			}
		}

		return $found;
	}

	/**
	 * Основа слова: кириллица — русский стеммер, латиница — английский.
	 *
	 * @param string $word Слово в нижнем регистре.
	 */
	public function stem( string $word ): string {
		return preg_match( '/\p{Cyrillic}/u', $word ) ? $this->russian->stem( $word ) : $this->english->stem( $word );
	}
}
