<?php
/**
 * Стеммер русского языка.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis\Language;

/**
 * Алгоритм Snowball (Портера) для русского языка: «колода», «колоды», «колодой» → «колод».
 */
final class RussianStemmer implements Stemmer {

	private const RV               = '/^(.*?[аеиоуыэюя])(.*)$/u';
	private const PERFECTIVE       = '/((ив|ивши|ившись|ыв|ывши|ывшись)|((?<=[ая])(в|вши|вшись)))$/u';
	private const REFLEXIVE        = '/(с[яь])$/u';
	private const ADJECTIVE        = '/(ее|ие|ые|ое|ими|ыми|ей|ий|ый|ой|ем|им|ым|ом|его|ого|ему|ому|их|ых|ую|юю|ая|яя|ою|ею)$/u';
	private const PARTICIPLE       = '/((ивш|ывш|ующ)|((?<=[ая])(ем|нн|вш|ющ|щ)))$/u';
	private const VERB             = '/((ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ен|ило|ыло|ено|ят|ует|уют|ит|ыт|ены|ить|ыть|ишь|ую|ю)|((?<=[ая])(ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)))$/u';
	private const NOUN             = '/(а|ев|ов|ие|ье|е|иями|ями|ами|еи|ии|и|ией|ей|ой|ий|й|иям|ям|ием|ем|ам|ом|о|у|ах|иях|ях|ы|ь|ию|ью|ю|ия|ья|я)$/u';
	private const DERIVATIONAL     = '/[^аеиоуыэюя][аеиоуыэюя]+[^аеиоуыэюя]+[аеиоуыэюя].*(?<=о)сть?$/u';
	private const DERIVATIONAL_END = '/ость?$/u';

	/**
	 * Уже посчитанные основы.
	 *
	 * @var array<string, string>
	 */
	private array $cache = array();

	/**
	 * Основа слова.
	 *
	 * @param string $word Слово.
	 */
	public function stem( string $word ): string {
		$word = str_replace( 'ё', 'е', mb_strtolower( $word ) );

		if ( isset( $this->cache[ $word ] ) ) {
			return $this->cache[ $word ];
		}

		$stem = $word;

		if ( preg_match( self::RV, $word, $parts ) && '' !== $parts[2] ) {
			$start = $parts[1];
			$rv    = $parts[2];

			// Шаг 1: окончания деепричастий, прилагательных, глаголов, существительных.
			if ( ! self::cut( $rv, self::PERFECTIVE ) ) {
				self::cut( $rv, self::REFLEXIVE );

				if ( self::cut( $rv, self::ADJECTIVE ) ) {
					self::cut( $rv, self::PARTICIPLE );
				} elseif ( ! self::cut( $rv, self::VERB ) ) {
					self::cut( $rv, self::NOUN );
				}
			}

			// Шаг 2: конечная «и».
			self::cut( $rv, '/и$/u' );

			// Шаг 3: словообразовательный суффикс «ость».
			if ( preg_match( self::DERIVATIONAL, $rv ) ) {
				self::cut( $rv, self::DERIVATIONAL_END );
			}

			// Шаг 4: мягкий знак, превосходная степень, удвоенная «н».
			if ( ! self::cut( $rv, '/ь$/u' ) ) {
				self::cut( $rv, '/ейше?$/u' );
				$rv = (string) preg_replace( '/нн$/u', 'н', $rv );
			}

			$stem = $start . $rv;
		}

		$this->cache[ $word ] = $stem;

		return $stem;
	}

	/**
	 * Отрезает окончание по шаблону.
	 *
	 * @param string $text    Часть слова; изменяется на месте.
	 * @param string $pattern Регулярное выражение окончания.
	 * @return bool Было ли что-то отрезано.
	 */
	private static function cut( string &$text, string $pattern ): bool {
		$before = $text;
		$text   = (string) preg_replace( $pattern, '', $text );

		return $before !== $text;
	}
}
