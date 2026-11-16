<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Frontend;

use IndexPilot\Frontend\AnswerBlock;
use PHPUnit\Framework\TestCase;

final class AnswerBlockTest extends TestCase {

	private const ANSWER = 'Маг на заклинаниях выигрывает темпом: дешёвые заклинания чистят стол, а урон в лицо закрывает игру к восьмому ходу.';

	private function block(): string {
		return AnswerBlock::html( self::ANSWER, 'Коротко', 'Обновлено', '15.11.2026', '2026-11-15T10:00:00+03:00' );
	}

	public function test_block_is_inserted_once_and_before_the_first_paragraph(): void {
		$content = '<figure><img src="a.png" alt=""><figcaption><p>Подпись</p></figcaption></figure>' . "\n" . '<p>Первый абзац.</p>' . "\n" . '<h2>Раздел</h2><p>Второй абзац.</p>';
		$result  = AnswerBlock::insert( $content, $this->block() );

		self::assertSame( 1, substr_count( $result, 'class="indexpilot-answer"' ) );
		self::assertLessThan( strpos( $result, '<p>Первый абзац.</p>' ), strpos( $result, 'indexpilot-answer' ) );
		// Абзац внутри подписи к картинке первым абзацем статьи не считается.
		self::assertGreaterThan( strpos( $result, '</figure>' ), strpos( $result, 'indexpilot-answer' ) );

		// Повторный проход фильтра второй блок не добавляет.
		self::assertSame( $result, AnswerBlock::insert( $result, $this->block() ) );
	}

	public function test_block_placed_by_shortcode_is_kept_and_duplicates_are_removed(): void {
		$content = '<p>Вступление.</p>' . $this->block() . '<p>Текст.</p>' . $this->block();
		$result  = AnswerBlock::insert( $content, $this->block() );

		self::assertSame( 1, substr_count( $result, 'class="indexpilot-answer"' ) );
		self::assertStringStartsWith( '<p>Вступление.</p><div class="indexpilot-answer"', $result );
	}

	public function test_text_without_paragraphs_gets_the_block_at_the_start(): void {
		self::assertStringStartsWith( '<div class="indexpilot-answer"', AnswerBlock::insert( 'Просто строка без разметки', $this->block() ) );
		self::assertSame( '<p>Текст.</p>', AnswerBlock::insert( '<p>Текст.</p>', '' ) );
	}

	public function test_block_shows_heading_answer_and_visible_update_date(): void {
		$html = $this->block();

		self::assertStringContainsString( '<strong>Коротко</strong>', $html );
		self::assertStringContainsString( self::ANSWER, $html );
		self::assertStringContainsString( 'Обновлено <time datetime="2026-11-15T10:00:00+03:00">15.11.2026</time>', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertSame( '', AnswerBlock::html( "  \n ", 'Коротко' ) );
	}

	public function test_markup_in_the_answer_is_escaped(): void {
		$html = AnswerBlock::html( 'Темп <b>важнее</b> карт & "ценности"', 'Коротко' );

		self::assertStringContainsString( 'Темп &lt;b&gt;важнее&lt;/b&gt; карт &amp; &quot;ценности&quot;', $html );
		self::assertStringNotContainsString( '<time', $html );
	}

	public function test_word_count_and_length_range(): void {
		self::assertSame( 0, AnswerBlock::word_count( '  ' ) );
		self::assertSame( 4, AnswerBlock::word_count( 'Блад-ДК — 54 % побед, топ-1.' ) );
		self::assertFalse( AnswerBlock::fits( self::ANSWER ) );
		self::assertTrue( AnswerBlock::fits( trim( str_repeat( 'слово ', 40 ) ) ) );
		self::assertTrue( AnswerBlock::fits( trim( str_repeat( 'слово ', 60 ) ) ) );
		self::assertFalse( AnswerBlock::fits( trim( str_repeat( 'слово ', 61 ) ) ) );
	}
}
