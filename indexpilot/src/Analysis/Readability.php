<?php
/**
 * Метрики удобства чтения.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Analysis;

use IndexPilot\Text\Cleaner;
/** Измеряет текст без WordPress. */
final class Readability {
	public const LONG_SENTENCE_WORDS  = 25;
	public const LONG_PARAGRAPH_WORDS = 150;
	public const LONG_WORD_LETTERS    = 15;
	private const FILLER              = array( 'в настоящее время', 'на сегодняшний день', 'является', 'в целях', 'в рамках', 'данный', 'осуществлять', 'осуществляется', 'имеет место', 'в связи с тем что', 'таким образом', 'следует отметить', 'необходимо отметить', 'стоит отметить', 'как известно', 'не секрет что', 'в общем и целом', 'по сути дела', 'так или иначе', 'в той или иной степени' );
	/** Измеряет содержимое записи. Слова учитываются во всём видимом тексте; предложения и абзацы — только в основном тексте.
	 * @param string $html HTML записи.
	 * @return array{words:int,sentences:int,paragraphs:int,long_sentences:int,long_sentence_share:float,long_paragraphs:int,long_word_share:float,filler:list<string>,has_lists:bool,has_tables:bool,subheadings:int,first_paragraph_words:int,score:int}
	 */
	public static function measure( string $html ): array {
		$html       = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>|<!--.*?-->|\[[^\]]*\]#isu', ' ', $html );
		$text       = Cleaner::to_plain_text( $html, false );
		$words      = self::words( $text );
		$total      = count( $words );
		$long_words = count( array_filter( $words, static fn( string $word ): bool => self::LONG_WORD_LETTERS < mb_strlen( (string) preg_replace( '/[^\p{L}]/u', '', $word ), 'UTF-8' ) ) );
		$body       = (string) preg_replace( '#<(h[1-6]|li|td|th)\b[^>]*>.*?</\1>#isu', "\n\n", $html );
		$body       = (string) preg_replace( '#</?(?:p|div|blockquote|ul|ol|table|tr)\b[^>]*>|<br\s*/?>#iu', "\n\n", $body );
		$paragraphs = array();
		$sentences  = array();
		foreach ( preg_split( '/\n\s*\n/u', $body ) as $part ) {
			$part = Cleaner::to_plain_text( $part );
			if ( array() === self::words( $part ) ) {
				continue; }
			$paragraphs[] = count( self::words( $part ) );
			$part         = (string) preg_replace_callback( '/(?<!\p{L})(?:и\s+)?т\.\s*[едп]\./iu', static fn( array $abbreviation ): string => str_replace( '.', '', $abbreviation[0] ), $part );
			foreach ( preg_split( '/[.!?…]+(?:\s+|$)/u', $part ) as $sentence ) {
				$count = count( self::words( $sentence ) );
				if ( 0 < $count ) {
					$sentences[] = $count; }
			}
		}
		preg_match_all( '/(?<!\p{L})(?:' . implode( '|', self::FILLER ) . ')(?!\p{L})/iu', $text, $found );
		$filler         = array_values( array_unique( array_map( static fn( string $phrase ): string => mb_strtolower( $phrase, 'UTF-8' ), $found[0] ) ) );
		$long_sentences = count( array_filter( $sentences, static fn( int $count ): bool => self::LONG_SENTENCE_WORDS < $count ) );
		$result         = array(
			'words'                 => $total,
			'sentences'             => count( $sentences ),
			'paragraphs'            => count( $paragraphs ),
			'long_sentences'        => $long_sentences,
			'long_sentence_share'   => count( $sentences ) ? (float) ( $long_sentences / count( $sentences ) ) : 0.0,
			'long_paragraphs'       => count( array_filter( $paragraphs, static fn( int $count ): bool => self::LONG_PARAGRAPH_WORDS < $count ) ),
			'long_word_share'       => $total ? (float) ( $long_words / $total ) : 0.0,
			'filler'                => $filler,
			'has_lists'             => 1 === preg_match( '/<(?:ul|ol)\b/iu', $html ),
			'has_tables'            => 1 === preg_match( '/<table\b/iu', $html ),
			'subheadings'           => preg_match_all( '/<h[2-6]\b/iu', $html ),
			'first_paragraph_words' => $paragraphs[0] ?? 0,
			'score'                 => 0,
		);
		$issues         = self::issues( $result );
		// Штрафы линейны: предложения от доли 0,25 до 1; длинные слова от 0 до 1.
		$penalty = min( 30, max( 0, ( $result['long_sentence_share'] - 0.25 ) / 0.75 * 30 ) ) + min( 20, $result['long_paragraphs'] * 10 ) + min( 15, $result['long_word_share'] * 15 ) + min( 15, max( 0, count( $filler ) - 2 ) * 5 );
		foreach ( array( 'no_lists', 'no_subheadings' ) as $issue ) {
			if ( in_array( $issue, $issues, true ) ) {
					$penalty += 10; }
		}
		$result['score'] = 0 === $total ? 0 : max( 0, (int) round( 100 - $penalty ) );
		return $result;
	}
	/** Возвращает проблемы по важности.
	 * @param array<string,mixed> $measure Метрики.
	 * @return list<string>
	 */
	public static function issues( array $measure ): array {
		if ( 0 === ( $measure['words'] ?? 0 ) ) {
			return array(); }
		$conditions = array(
			'long_sentences'  => 0.25 < $measure['long_sentence_share'],
			'long_paragraphs' => 0 < $measure['long_paragraphs'],
			'long_words'      => 0.1 < $measure['long_word_share'],
			'filler'          => 3 <= count( $measure['filler'] ),
			'no_lists'        => 300 <= $measure['words'] && ! $measure['has_lists'] && ! $measure['has_tables'],
			'no_subheadings'  => 300 <= $measure['words'] && 0 === $measure['subheadings'],
			'long_intro'      => 80 < $measure['first_paragraph_words'],
		);
		return array_keys( array_filter( $conditions ) );
	}
	/** Выделяет слова и числа.
	 * @param string $text Текст.
	 * @return list<string>
	 */
	private static function words( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}]+(?:[’\x27-][\p{L}\p{N}]+|[.,]\p{N}+)*/u', $text, $matches );
		return $matches[0]; }
}
