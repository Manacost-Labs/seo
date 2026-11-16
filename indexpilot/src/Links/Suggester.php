<?php
/**
 * Подбор внутренних ссылок для текста.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

use IndexPilot\Analysis\KeywordMatcher;

/**
 * Находит страницы сайта, о которых говорится в тексте: сравнивает основы слов текста с основами слов заголовков.
 * Редкие слова весят больше частых: совпадение по слову «колода» значит мало, по названию архетипа — много.
 */
final class Suggester {

	/**
	 * Какая доля заголовка должна встретиться в тексте. Доля считается по весу слов:
	 * редкое слово («заклинаниях») весит больше частого («гайд», «разбор»).
	 */
	private const MIN_COVERAGE = 0.5;

	/**
	 * Заголовок из одного слова предлагается, только если это слово встречается не более чем в такой доле заголовков.
	 */
	private const RARE_SHARE = 0.2;

	private const STOP_WORDS = array( 'для', 'или', 'как', 'что', 'это', 'при', 'про', 'над', 'под', 'без', 'the', 'and', 'for', 'with', 'from' );

	/**
	 * Конструктор.
	 *
	 * @param KeywordMatcher $matcher Разбор слов и основ.
	 */
	public function __construct( private KeywordMatcher $matcher ) {
	}

	/**
	 * Строит индекс заголовков.
	 *
	 * @param array<string, string> $titles Идентификатор страницы => заголовок.
	 * @return array{total: int, docs: array<string, array{title: string, stems: list<string>}>, stems: array<string, int>}
	 */
	public function build( array $titles ): array {
		$docs      = array();
		$frequency = array();

		foreach ( $titles as $id => $title ) {
			$stems = array_keys( $this->stems( (string) $title ) );

			if ( array() === $stems ) {
				continue;
			}

			$docs[ (string) $id ] = array(
				'title' => (string) $title,
				'stems' => array_map( 'strval', $stems ),
			);

			foreach ( $stems as $stem ) {
				$frequency[ $stem ] = ( $frequency[ $stem ] ?? 0 ) + 1;
			}
		}

		return array(
			'total' => count( $docs ),
			'docs'  => $docs,
			'stems' => $frequency,
		);
	}

	/**
	 * Подбирает страницы для текста.
	 *
	 * @param array<string, mixed> $index   Индекс из build().
	 * @param string               $html    Текст записи.
	 * @param list<string>         $exclude Идентификаторы, которые предлагать не нужно.
	 * @param int                  $limit   Сколько страниц вернуть.
	 * @return list<array{id: string, title: string, score: float}>
	 */
	public function suggest( array $index, string $html, array $exclude = array(), int $limit = 5 ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress и проверяется модульными тестами.
		$text  = $this->stems( html_entity_decode( strip_tags( str_replace( '<', ' <', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$total = max( 1, (int) ( $index['total'] ?? 0 ) );
		$skip  = array_flip( $exclude );
		$found = array();

		foreach ( (array) ( $index['docs'] ?? array() ) as $id => $doc ) {
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}

			$stems   = (array) $doc['stems'];
			$matched = array();

			foreach ( $stems as $stem ) {
				if ( isset( $text[ $stem ] ) ) {
					$matched[] = $stem;
				}
			}

			// Одного совпавшего слова мало, если в заголовке их несколько.
			if ( count( $matched ) < min( 2, count( $stems ) ) ) {
				continue;
			}

			if ( 1 === count( $stems ) && ( (int) ( $index['stems'][ $stems[0] ] ?? 0 ) ) / $total > self::RARE_SHARE ) {
				continue;
			}

			$weight = 0.0;
			foreach ( $stems as $stem ) {
				$weight += $this->weight( $index, $stem, $total );
			}

			$score = 0.0;
			foreach ( $matched as $stem ) {
				$score += $this->weight( $index, $stem, $total );
			}

			if ( $weight <= 0.0 || $score / $weight < self::MIN_COVERAGE ) {
				continue;
			}

			$found[] = array(
				'id'    => (string) $id,
				'title' => (string) $doc['title'],
				'score' => round( $score * $score / $weight, 4 ),
			);
		}

		usort( $found, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return array_slice( $found, 0, max( 0, $limit ) );
	}

	/**
	 * Вес слова: чем в меньшем числе заголовков оно встречается, тем он больше.
	 *
	 * @param array<string, mixed> $index Индекс из build().
	 * @param string               $stem  Основа слова.
	 * @param int                  $total Число заголовков в индексе.
	 */
	private function weight( array $index, string $stem, int $total ): float {
		return log( 1 + $total / max( 1, (int) ( $index['stems'][ $stem ] ?? 1 ) ) );
	}

	/**
	 * Основы значимых слов текста: без предлогов, коротких слов кириллицей и чисел.
	 * Синонимы игрового словаря уже заменены каноническими словами: «дека приста» совпадает с заголовком «Колода жреца».
	 *
	 * @param string $text Простой текст.
	 * @return array<string, true> Основа => true.
	 */
	private function stems( string $text ): array {
		$set = array();

		foreach ( $this->matcher->canonical_words( $text ) as $word ) {
			$latin = 1 === preg_match( '/^[a-z0-9]+$/', $word );

			if ( ctype_digit( $word ) || in_array( $word, self::STOP_WORDS, true ) || mb_strlen( $word ) < ( $latin ? 2 : 3 ) ) {
				continue;
			}

			$set[ $this->matcher->stem( $word ) ] = true;
		}

		return $set;
	}
}
