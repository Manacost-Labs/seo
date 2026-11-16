<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Text;

use IndexPilot\Text\Cleaner;
use IndexPilot\Text\Transliterator;
use IndexPilot\Text\Truncator;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	private const LONG_TEXT = 'Маг на заклинаниях держит темп с первых ходов. Колода наказывает медленные архетипы и закрывает игру к восьмому ходу. Третье предложение нужно только для того, чтобы длина текста заметно превысила предел в сто пятьдесят пять символов.';

	public function test_short_text_is_returned_unchanged(): void {
		self::assertSame( 'Короткое описание.', Truncator::by_sentence( 'Короткое описание.', 155 ) );
	}

	public function test_long_text_is_cut_at_the_end_of_a_sentence(): void {
		$result = Truncator::by_sentence( self::LONG_TEXT, 155 );

		self::assertSame(
			'Маг на заклинаниях держит темп с первых ходов. Колода наказывает медленные архетипы и закрывает игру к восьмому ходу.',
			$result
		);
	}

	public function test_text_without_sentence_end_is_cut_at_a_word_boundary(): void {
		$text   = 'колода ' . str_repeat( 'заклинание ', 30 );
		$result = Truncator::by_sentence( $text, 155 );

		self::assertLessThanOrEqual( 155, mb_strlen( $result ) );
		self::assertStringEndsWith( '…', $result );

		$kept = mb_substr( $result, 0, -1 );
		self::assertStringStartsWith( $kept, $text );
		self::assertSame( ' ', mb_substr( $text, mb_strlen( $kept ), 1 ), 'Обрезка должна проходить по пробелу, а не посреди слова.' );
	}

	public function test_cleaner_keeps_text_and_drops_markup(): void {
		$html = '<h2>Заголовок раздела</h2><p>Первый абзац.</p><!-- wp:paragraph --><p>Второй&nbsp;абзац.</p>'
			. '[su_button url="https://example.com"]Кнопка[/su_button]<script>alert(1)</script><style>p{color:red}</style>';

		self::assertSame( 'Первый абзац. Второй абзац. Кнопка', Cleaner::to_plain_text( $html ) );
		self::assertSame(
			'Заголовок раздела Первый абзац. Второй абзац. Кнопка',
			Cleaner::to_plain_text( $html, false )
		);
	}

	public function test_transliteration_matches_site_slugs(): void {
		self::assertSame( 'zhrecz-tmy', Transliterator::slugify( 'Жрец тьмы' ) );
		self::assertSame( 'gajd-na-bern-maga', Transliterator::slugify( 'Гайд на берн мага' ) );
		self::assertSame( 'razbojnik-na-vozveshhenii', Transliterator::slugify( 'Разбойник на возвещении' ) );
		self::assertSame( 'chernoknizhnik', Transliterator::slugify( 'Чернокнижник' ) );
		self::assertSame( 'blood-dk', Transliterator::slugify( 'Blood DK' ) );
	}

	public function test_text_behind_a_lock_never_reaches_the_plain_text(): void {
		$closed = Cleaner::to_plain_text( '<p>Открытое начало.</p>[locker id="5"]<p>Секретный муллиган.</p>[/locker]<p>Открытый конец.</p>' );
		self::assertStringContainsString( 'Открытое начало.', $closed );
		self::assertStringContainsString( 'Открытый конец.', $closed );
		self::assertStringNotContainsString( 'Секретный', $closed );

		$unclosed = Cleaner::to_plain_text( '<p>Вступление.</p>[boosty_paywall]<p>Платный разбор матчапов.</p>' );
		self::assertStringContainsString( 'Вступление.', $unclosed );
		self::assertStringNotContainsString( 'Платный', $unclosed );

		// Обычные шорткоды по-прежнему оставляют свой текст.
		self::assertStringContainsString( 'Подсказка', Cleaner::to_plain_text( '[su_note]Подсказка[/su_note]' ) );
	}

	public function test_vip_locker_paywall_hides_text_and_its_access_code(): void {
		// Так пейвол записан в 78 статьях kolodahearthstone.com: с кодом доступа в атрибуте.
		$text = Cleaner::to_plain_text( "<p>Открытая часть.</p>[vip_locker code=\"код-1234\"]\n\n<p>Платный разбор.</p>[/vip_locker]<p>Итог.</p>" );

		self::assertStringContainsString( 'Открытая часть.', $text );
		self::assertStringContainsString( 'Итог.', $text );
		self::assertStringNotContainsString( 'Платный', $text );
		self::assertStringNotContainsString( 'код-1234', $text );
		self::assertTrue( \IndexPilot\Schema\Paywall::is_lock( 'vip_locker' ) );
		self::assertTrue( \IndexPilot\Schema\Paywall::has_locked( '[vip_locker code="код-1234"]x[/vip_locker]' ) );
	}
}
