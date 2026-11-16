<?php
/**
 * Превращение HTML записи в простой текст.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Text;

/**
 * Чистит HTML для автоописания и анализа. Не зависит от WordPress.
 */
final class Cleaner {

	/**
	 * Возвращает видимый текст без разметки, скриптов, шорткодов и комментариев блоков.
	 *
	 * @param string $html          Исходный HTML.
	 * @param bool   $drop_headings Выбросить заголовки h1–h6 вместе с текстом (для автоописания).
	 */
	public static function to_plain_text( string $html, bool $drop_headings = true ): string {
		$patterns = array(
			// Блоки, содержимое которых не является текстом страницы.
			'#<(script|style|pre|code|noscript|iframe|svg|form|nav|figcaption)\b[^>]*>.*?</\1>#isu' => ' ',
			// Комментарии, включая разметку блоков Gutenberg.
			'/<!--.*?-->/su' => ' ',
			// Теги шорткодов; текст между открывающим и закрывающим тегом остаётся.
			'/\[\/?[a-zA-Z][a-zA-Z0-9_\-]*(?:\s[^\]]*)?\/?\]/u' => ' ',
		);

		if ( $drop_headings ) {
			$patterns = array( '#<h[1-6]\b[^>]*>.*?</h[1-6]>#isu' => ' ' ) + $patterns;
		}

		// Концы блочных элементов становятся пробелами, иначе соседние абзацы слипнутся.
		$patterns['#<(?:br|/p|/div|/li|/tr|/td|/th|/h[1-6]|/blockquote)\b[^>]*>#iu'] = ' ';

		foreach ( $patterns as $pattern => $replacement ) {
			$html = (string) preg_replace( $pattern, $replacement, $html );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress; опасные блоки уже вырезаны выше.
		$text = strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
