<?php
/**
 * Проверка готовой страницы: видно ли основное содержимое без JavaScript.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Audit;

use IndexPilot\Text\Cleaner;

/**
 * Смотрит на HTML страницы так, как его получает поисковик или ассистент, не выполняющий скрипты.
 * Если состав колоды (или основной текст) появляется только после работы скрипта, в HTML его нет —
 * тогда страница для робота пустая. Не зависит от WordPress.
 */
final class RenderedPage {

	public const NOTE = 'основное содержимое не видно без JavaScript';

	/**
	 * Меньше стольких слов в основной части при наличии скриптов — содержимое, скорее всего, рисует скрипт.
	 */
	private const MIN_WORDS = 25;

	/**
	 * Разбор HTML.
	 *
	 * @param string       $html  HTML готовой страницы.
	 * @param list<string> $cards Названия карт колоды, если они известны: каждое должно быть в тексте.
	 * @return array{hidden: bool, words: int, scripts: int, missing: list<string>, note: string}
	 */
	public static function analyze( string $html, array $cards = array() ): array {
		$scripts = preg_match_all( '#<script\b(?![^>]*type=["\']application/(?:ld\+)?json)#i', $html );
		$main    = self::main( $html );
		$text    = Cleaner::to_plain_text( $main, false );
		$words   = count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
		$lower   = mb_strtolower( $text );
		$missing = array();

		foreach ( $cards as $card ) {
			$card = trim( (string) $card );

			if ( '' !== $card && ! str_contains( $lower, mb_strtolower( $card ) ) ) {
				$missing[] = $card;
			}
		}

		$hidden = array() !== $missing || ( $scripts > 0 && $words < self::MIN_WORDS );

		return array(
			'hidden'  => $hidden,
			'words'   => $words,
			'scripts' => (int) $scripts,
			'missing' => $missing,
			'note'    => $hidden ? self::NOTE : '',
		);
	}

	/**
	 * Основная часть страницы: main, иначе article, иначе body без шапки, меню и подвала.
	 *
	 * @param string $html HTML страницы.
	 */
	private static function main( string $html ): string {
		foreach ( array( 'main', 'article' ) as $tag ) {
			if ( 1 === preg_match( '#<' . $tag . '\b[^>]*>(.*)</' . $tag . '>#isu', $html, $match ) ) {
				return $match[1];
			}
		}

		$body = 1 === preg_match( '#<body\b[^>]*>(.*)</body>#isu', $html, $match ) ? $match[1] : $html;

		return (string) preg_replace( '#<(header|footer|aside)\b[^>]*>.*?</\1>#isu', ' ', $body );
	}
}
