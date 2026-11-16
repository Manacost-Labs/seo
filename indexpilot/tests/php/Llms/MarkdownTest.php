<?php
/**
 * Проверки перевода записи в Markdown.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tests\Llms;

use IndexPilot\Llms\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Из HTML записи получается чистый Markdown: заголовки, списки, таблицы и ссылки, без меню и скриптов.
 */
final class MarkdownTest extends TestCase {

	/**
	 * Заголовки, абзацы, списки и ссылки переводятся, меню и скрипты пропадают.
	 */
	public function test_headings_lists_links(): void {
		$html = '<nav><a href="/">Главная</a></nav><script>alert(1)</script><style>p{color:red}</style>'
			. '<h2>Муллиган</h2><p>Оставляйте <strong>дешёвые</strong> карты, см. <a href="https://hs.test/gajd/">гайд</a>.</p>'
			. '<ul><li>Первая карта</li><li>Вторая <em>карта</em><ol><li>вложенная</li></ol></li></ul>'
			. '<ol><li>Шаг один</li><li>Шаг два</li></ol>'
			. '<form><input name="q"></form><div class="ad"><p>Текст в блоке</p></div>';

		$markdown = Markdown::convert( $html );

		self::assertStringContainsString( "## Муллиган\n\nОставляйте **дешёвые** карты, см. [гайд](https://hs.test/gajd/).", $markdown );
		self::assertStringContainsString( "- Первая карта\n- Вторая *карта*\n  1. вложенная", $markdown );
		self::assertStringContainsString( "1. Шаг один\n2. Шаг два", $markdown );
		self::assertStringContainsString( 'Текст в блоке', $markdown );
		self::assertStringNotContainsString( 'Главная', $markdown );
		self::assertStringNotContainsString( 'alert', $markdown );
		self::assertStringNotContainsString( 'color', $markdown );
		self::assertStringNotContainsString( '<', $markdown );
	}

	/**
	 * Таблица переводится в таблицу Markdown, вертикальная черта в ячейке экранируется.
	 */
	public function test_table(): void {
		$html = '<table><thead><tr><th>Колода</th><th>Победы</th></tr></thead><tbody><tr><td>Blood DK</td><td>54 %</td></tr><tr><td>A|B</td><td>50 %</td></tr></tbody></table>';

		self::assertSame( "| Колода | Победы |\n| --- | --- |\n| Blood DK | 54 % |\n| A\\|B | 50 % |", Markdown::convert( $html ) );
	}

	/**
	 * Документ: заголовок, краткий ответ, текст и строка об источнике.
	 */
	public function test_document(): void {
		$text = Markdown::document( 'Гайд на жреца', 'Жрец силён против агро.', '<p>Текст статьи.</p>', 'Источник: https://hs.test/gajd/' );

		self::assertSame( "# Гайд на жреца\n\n> Жрец силён против агро.\n\nТекст статьи.\n\n---\n\nИсточник: https://hs.test/gajd/\n", $text );
	}

	/**
	 * Блоки кода, цитаты, картинки и переносы строк.
	 */
	public function test_other_elements(): void {
		$markdown = Markdown::convert( '<blockquote><p>Цитата</p></blockquote><pre><code>AAECAa0G</code></pre><p>Строка<br>вторая</p><p><img src="https://hs.test/a.png" alt="Карта"></p><p><a href="javascript:void(0)">кнопка</a></p>' );

		self::assertStringContainsString( '> Цитата', $markdown );
		self::assertStringContainsString( "```\nAAECAa0G\n```", $markdown );
		self::assertStringContainsString( "Строка  \nвторая", $markdown );
		self::assertStringContainsString( '![Карта](https://hs.test/a.png)', $markdown );
		self::assertStringContainsString( 'кнопка', $markdown );
		self::assertStringNotContainsString( 'javascript', $markdown );
	}
}
