<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Frontend;

use IndexPilot\Frontend\FaqBlock;
use PHPUnit\Framework\TestCase;

final class FaqBlockTest extends TestCase {

	public function test_pairs_are_cleaned_and_limited_to_ten(): void {
		$raw = array(
			array(
				'question' => '  Сколько пыли стоит колода?  ',
				'answer'   => " Около 6000 пыли. \n",
			),
			array(
				'question' => '',
				'answer'   => '',
			),
			array(
				'question' => 'Вопрос без ответа?',
				'answer'   => '',
			),
			'мусор',
		);

		for ( $i = 1; $i <= 12; $i++ ) {
			$raw[] = array(
				'question' => 'Вопрос ' . $i,
				'answer'   => 'Ответ ' . $i,
			);
		}

		$items = FaqBlock::sanitize( $raw );

		self::assertCount( FaqBlock::MAX_ITEMS, $items );
		self::assertSame(
			array(
				'question' => 'Сколько пыли стоит колода?',
				'answer'   => 'Около 6000 пыли.',
			),
			$items[0]
		);
		// Недописанная пара не теряется при сохранении, но в блок и в разметку не попадает.
		self::assertSame( 'Вопрос без ответа?', $items[1]['question'] );
		self::assertCount( FaqBlock::MAX_ITEMS - 1, FaqBlock::complete( $items ) );
		self::assertSame( array(), FaqBlock::sanitize( 'строка' ) );
	}

	public function test_form_rows_become_pairs(): void {
		$items = FaqBlock::from_form(
			array(
				'question' => array( 'Первый?', '', 'Третий?' ),
				'answer'   => array( 'Да.', '', 'Нет.' ),
			)
		);

		self::assertSame( array( 'Первый?', 'Третий?' ), array_column( $items, 'question' ) );
		self::assertSame( array( 'Да.', 'Нет.' ), array_column( $items, 'answer' ) );
		self::assertSame( array(), FaqBlock::from_form( 'мусор' ) );
	}

	public function test_block_is_visible_markup_without_scripts(): void {
		$html = FaqBlock::html(
			array(
				array(
					'question' => 'Подходит ли <колода> новичку?',
					'answer'   => "Да.\nПлан игры простой.",
				),
			),
			'Вопросы и ответы'
		);

		self::assertStringContainsString( '<section class="indexpilot-faq"', $html );
		self::assertStringContainsString( '>Вопросы и ответы</p>', $html, 'Заголовок блока — скромная подпись, а не H2 размером с раздел статьи.' );
		self::assertStringContainsString( '<details class="indexpilot-spoiler indexpilot-faq-item"><summary><h3>Подходит ли &lt;колода&gt; новичку?</h3></summary>', $html );
		self::assertStringContainsString( "<p>Да.<br>\nПлан игры простой.</p>", $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertSame( '', FaqBlock::html( array(), 'Вопросы и ответы' ) );
	}

	public function test_placement_follows_shortcode_then_auto_setting(): void {
		$plain     = '<p>Первая страница.</p><!--nextpage--><p>Вторая страница.</p>';
		$shortcode = '<p>Первая.</p>[indexpilot_faq]<!--nextpage--><p>Вторая.</p>';

		// Автоматически блок встаёт в конец последней страницы записи.
		self::assertSame( '', FaqBlock::placement( $plain, 1, true ) );
		self::assertSame( 'auto', FaqBlock::placement( $plain, 2, true ) );
		self::assertSame( '', FaqBlock::placement( $plain, 2, false ) );
		self::assertSame( 'auto', FaqBlock::placement( '<p>Одна страница.</p>', 1, true ) );

		// Шорткод сильнее настройки: блок выводится там, где он стоит, и больше нигде.
		self::assertSame( 'shortcode', FaqBlock::placement( $shortcode, 1, true ) );
		self::assertSame( '', FaqBlock::placement( $shortcode, 2, true ) );
		self::assertSame( 'shortcode', FaqBlock::placement( '<p>Текст.</p>[indexpilot_faq title="FAQ"]', 1, false ) );
		self::assertSame( '', FaqBlock::placement( '<p>Текст [indexpilot_faqs].</p>', 1, false ) );
	}
}
