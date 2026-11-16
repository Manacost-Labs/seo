<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Authors;

use IndexPilot\Authors\Review;
use PHPUnit\Framework\TestCase;

final class ReviewTest extends TestCase {

	private const TEXT = '<p>Колода жреца на контроль.</p><p>Код: AAECAa0GBsUE9BbSlwT8oAXWnwbIqQYM</p><p>Муллиган: берём дешёвые карты.</p><h2>Выводы</h2><p>Колода сильна против агрессии.</p>';

	public function test_review_is_lost_after_deck_or_conclusion_change(): void {
		$stored = Review::fingerprint( self::TEXT, array(), 'Лучший контроль патча.' );

		self::assertSame( Review::VALID, Review::status( 5, $stored, Review::fingerprint( self::TEXT, array(), 'Лучший контроль патча.' ) ) );

		// Опечатка в середине текста — проверка остаётся в силе.
		$typo = str_replace( 'дешёвые', 'дешевые', self::TEXT );
		self::assertSame( Review::VALID, Review::status( 5, $stored, Review::fingerprint( $typo, array(), 'Лучший контроль патча.' ) ) );

		// Другой состав колоды.
		$deck = str_replace( 'AAECAa0GBsUE9BbSlwT8oAXWnwbIqQYM', 'AAECAa0GBsUE9BbSlwT8oAXWnwbIqQYN', self::TEXT );
		self::assertSame( Review::STALE, Review::status( 5, $stored, Review::fingerprint( $deck, array(), 'Лучший контроль патча.' ) ) );

		// Другие выводы.
		$verdict = str_replace( 'сильна против агрессии', 'слаба против агрессии', self::TEXT );
		self::assertSame( Review::STALE, Review::status( 5, $stored, Review::fingerprint( $verdict, array(), 'Лучший контроль патча.' ) ) );

		// Другой краткий ответ.
		self::assertSame( Review::STALE, Review::status( 5, $stored, Review::fingerprint( self::TEXT, array(), 'Худший контроль патча.' ) ) );

		// Код колоды из поля записи тоже учитывается.
		self::assertNotSame( Review::fingerprint( self::TEXT, array( 'AAEBAQ' . str_repeat( 'x', 20 ) ) ), Review::fingerprint( self::TEXT ) );
	}

	public function test_without_reviewer_there_is_no_mark(): void {
		self::assertSame( Review::NONE, Review::status( 0, 'abc', 'abc' ) );
		self::assertSame( Review::STALE, Review::status( 3, '', 'abc' ) );
	}

	public function test_last_paragraph_is_conclusion_without_heading(): void {
		self::assertSame( 'итог: играбельно.', Review::conclusion( '<p>Начало.</p><p>Итог: играбельно.</p>' ) );
	}
}
