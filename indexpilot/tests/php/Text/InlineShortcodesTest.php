<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Text;

use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Schema\GraphBuilder;
use IndexPilot\Text\InlineShortcodes;
use PHPUnit\Framework\TestCase;

final class InlineShortcodesTest extends TestCase {

	/**
	 * Ответ из панели статьи на hs-manacost.ru.
	 */
	private const ANSWER = 'Берн Маг всегда желает видеть в стартовой руке пять карт: [hs_card id="CORE_DRG_107"]Аметистового чарокрыла[/hs_card], [hs_card id="JAIL_312"]Контрабандные жезлы[/hs_card] и [hs_card id="CATA_458"]Верховного мага Кейлека[/hs_card].';

	/**
	 * Как выполняет шорткод hs-tooltip: только зарегистрированные, остальные возвращаются как есть.
	 *
	 * @param string $raw Шорткод.
	 */
	public static function fake_shortcode( string $raw ): string {
		if ( 1 === preg_match( '/^\[hs_card id="([^"]+)"\](.*)\[\/hs_card\]$/su', $raw, $found ) ) {
			return '<span class="hs-card" data-id="' . $found[1] . '">' . $found[2] . '</span>';
		}

		return $raw;
	}

	public function test_plain_text_keeps_card_names_only(): void {
		self::assertSame(
			'Берн Маг всегда желает видеть в стартовой руке пять карт: Аметистового чарокрыла, Контрабандные жезлы и Верховного мага Кейлека.',
			InlineShortcodes::plain( self::ANSWER )
		);
		self::assertSame( 'Колода: и всё.', InlineShortcodes::plain( 'Колода: [deck code="AAE"] и всё.' ), 'Одиночный шорткод убирается без лишних пробелов.' );
		self::assertSame( 'Сноска [1] остаётся.', InlineShortcodes::plain( 'Сноска [1] остаётся.' ) );
	}

	public function test_page_runs_card_shortcodes_and_escapes_the_rest(): void {
		$html = InlineShortcodes::html( "Карта: [hs_card id=\"BE_251\"]Космические источники[/hs_card] <b>не тег</b>\n[unknown]x[/unknown]", array( self::class, 'fake_shortcode' ) );

		self::assertStringContainsString( '<span class="hs-card" data-id="BE_251">Космические источники</span>', $html );
		self::assertStringContainsString( '&lt;b&gt;не тег&lt;/b&gt;<br>', $html, 'Текст редактора экранируется, перевод строки сохраняется.' );
		self::assertStringContainsString( '[unknown]x[/unknown]', $html, 'Незарегистрированный шорткод остаётся текстом.' );
		self::assertStringContainsString( '[hs_card id=&quot;BE_251&quot;]', InlineShortcodes::html( '[hs_card id="BE_251"]x[/hs_card]' ), 'Без выполнения шорткодов всё экранируется.' );
	}

	public function test_tooltip_assets_are_needed_only_for_cards(): void {
		self::assertTrue( \IndexPilot\Compat\CardTooltips::needs( self::ANSWER ) );
		self::assertFalse( \IndexPilot\Compat\CardTooltips::needs( 'Муллиган без карт.' ) );
	}

	public function test_faq_block_and_schema_handle_cards(): void {
		$items = array(
			array(
				'question' => 'Какой муллиган у Берн мага?',
				'answer'   => self::ANSWER,
			),
		);

		$html = FaqBlock::html( $items, 'Вопросы и ответы', array( self::class, 'fake_shortcode' ) );

		self::assertStringContainsString( '<span class="hs-card" data-id="CATA_458">Верховного мага Кейлека</span>', $html );
		self::assertStringNotContainsString( '[hs_card', $html );

		$graph = ( new GraphBuilder() )->build(
			array(
				'site' => array(
					'name' => 'Манакост',
					'url'  => 'https://site.test/',
				),
				'page' => array(
					'type'  => 'WebPage',
					'url'   => 'https://site.test/bern-mag/',
					'title' => 'Берн маг',
				),
				'faq'  => $items,
			)
		);
		$faq   = $graph->first( 'FAQPage' );

		self::assertStringContainsString( 'пять карт: Аметистового чарокрыла, Контрабандные жезлы', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
		self::assertStringNotContainsString( 'hs_card', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
	}
}
