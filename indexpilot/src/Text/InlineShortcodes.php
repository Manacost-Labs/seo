<?php
/**
 * Шорткоды внутри коротких текстов: ответы, вопросы, краткий ответ.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Text;

/**
 * Редакторы пишут в ответах карты шорткодом: «[hs_card id="CORE_DRG_107"]Аметистового чарокрыла[/hs_card]».
 * На странице шорткод должен сработать (подсказка карты), а в разметке и тексте для поисковиков остаётся
 * только название карты. Остальной текст экранируется как обычный.
 */
final class InlineShortcodes {

	/**
	 * Шорткод с закрывающим тегом или одиночный.
	 */
	private const PATTERN = '/\[([a-z][a-z0-9_-]*)\b[^\]]*\](?:(.*?)\[\/\1\])?/isu';

	/**
	 * Текст без тегов шорткодов: содержимое парных шорткодов остаётся, одиночные убираются.
	 *
	 * @param string $text Текст с шорткодами.
	 */
	public static function plain( string $text ): string {
		$text = (string) preg_replace_callback(
			self::PATTERN,
			static fn( array $found ): string => isset( $found[2] ) ? self::plain( $found[2] ) : '',
			$text
		);

		// Одиночный шорткод мог оставить двойной пробел или пробел перед знаком препинания.
		$text = (string) preg_replace( '/[ \t]+([,.;:!?)])/u', '$1', $text );

		return trim( (string) preg_replace( '/[ \t]{2,}/u', ' ', $text ) );
	}

	/**
	 * HTML текста: шорткоды выполняются, остальное экранируется, переводы строк становятся <br>.
	 *
	 * @param string                    $text      Текст с шорткодами.
	 * @param callable( string ): string $shortcode Выполнение шорткода (do_shortcode); null — шорткоды остаются текстом.
	 */
	public static function html( string $text, ?callable $shortcode = null ): string {
		$escape = static fn( string $part ): string => nl2br( htmlspecialchars( $part, ENT_QUOTES, 'UTF-8' ), false );

		if ( null === $shortcode ) {
			return $escape( $text );
		}

		$html   = '';
		$offset = 0;

		if ( preg_match_all( self::PATTERN, $text, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $match ) {
				list( $raw, $start ) = $match;

				$html    .= $escape( substr( $text, $offset, $start - $offset ) );
				$rendered = (string) $shortcode( $raw );
				// Незарегистрированный шорткод возвращается как есть — тогда это просто текст.
				$html  .= $rendered === $raw ? $escape( $raw ) : $rendered;
				$offset = $start + strlen( $raw );
			}
		}

		return $html . $escape( substr( $text, $offset ) );
	}
}
