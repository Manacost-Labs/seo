<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Compat;

use IndexPilot\Compat\DeckArchetypes;
use IndexPilot\Compat\HeadDedupe;
use PHPUnit\Framework\TestCase;

final class HeadDedupeTest extends TestCase {

	public function test_foreign_repeats_outside_our_block_are_removed(): void {
		// Так выглядел head страницы /reklama/ на hs-manacost.ru: тема печатала свой canonical с параметрами запроса.
		$html = "<link rel=\"preload\" as=\"image\" href=\"/hero.jpg\"/>\n"
			. "<link rel=\"canonical\" href=\"https://site.test/reklama/?nc=1\"/>\n"
			. "<meta name=\"description\" content=\"Чужое\">\n"
			. "<meta property=\"og:locale\" content=\"ru_RU\">\n"
			. "<!-- IndexPilot 1.0.0 -->\n"
			. "<meta name=\"description\" content=\"Наше\">\n"
			. "<link rel=\"canonical\" href=\"https://site.test/reklama/\">\n"
			. "<meta property=\"og:image\" content=\"/a.jpg\">\n"
			. "<meta property=\"og:image\" content=\"/b.jpg\">\n"
			. "<!-- /IndexPilot -->\n"
			. "<link rel='canonical' href='https://site.test/x/' />\n"
			. "<meta property=\"og:image\" content=\"/c.jpg\">\n"
			. "<style>.a{}</style>\n";

		$clean = HeadDedupe::clean( $html );

		self::assertSame( 1, substr_count( $clean, 'rel="canonical"' ) + substr_count( $clean, "rel='canonical'" ) );
		self::assertStringContainsString( 'href="https://site.test/reklama/"', $clean );
		self::assertSame( 1, substr_count( $clean, 'name="description"' ) );
		self::assertSame( 2, substr_count( $clean, 'og:image' ), 'Две картинки нашего блока остаются, чужая третья убрана.' );
		self::assertStringContainsString( 'og:locale', $clean, 'Тега, которого нет в нашем блоке, это не касается.' );
		self::assertStringContainsString( 'rel="preload"', $clean );
		self::assertStringContainsString( '<style>', $clean );
	}

	public function test_head_without_our_block_is_left_alone(): void {
		$html = "<link rel=\"canonical\" href=\"/a/\">\n<link rel=\"canonical\" href=\"/b/\">\n";

		self::assertSame( $html, HeadDedupe::clean( $html ) );
	}

	public function test_deck_plugin_values_replace_ours_only_when_given(): void {
		self::assertSame( 'Айя Разбойник — Стандарт', DeckArchetypes::pass( 'Айя Разбойник | Архетип', static fn( string $v ): string => 'Айя Разбойник — Стандарт' ) );
		self::assertSame( 'Наш', DeckArchetypes::pass( 'Наш', static fn( string $v ): string => $v ), 'Не архетип: плагин колод возвращает значение как есть.' );
		self::assertSame( 'Наш', DeckArchetypes::pass( 'Наш', static fn( string $v ): string => '' ) );
		self::assertSame( 'Наш', DeckArchetypes::pass( 'Наш', null ), 'Плагина колод нет.' );
	}
}
