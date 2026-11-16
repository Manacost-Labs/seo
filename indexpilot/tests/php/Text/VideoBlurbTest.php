<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Text;

use IndexPilot\Text\VideoBlurb;
use PHPUnit\Framework\TestCase;

final class VideoBlurbTest extends TestCase {

	private const CONTENT = '<h2>Список <em>карт</em></h2><p>Текст.</p><h3>Подраздел</h3><h2 class="wp-block-heading">Муллиган &amp; тактика</h2><p>Ещё текст.</p>[indexpilot_stat value="54 %"]<h2> </h2>';

	public function test_blurb_has_the_short_link_and_no_html(): void {
		$text = VideoBlurb::build( 'Маг на <b>заклинаниях</b> выигрывает темпом.', self::CONTENT, 'https://site.test/go/p7' );

		self::assertStringContainsString( 'https://site.test/go/p7', $text );
		self::assertSame( $text, strip_tags( $text ) );
		self::assertStringNotContainsString( '&amp;', $text );
		self::assertStringNotContainsString( '[indexpilot', $text );
	}

	public function test_blurb_lists_the_answer_then_h2_headings_then_the_link(): void {
		$text = VideoBlurb::build( 'Маг на заклинаниях выигрывает темпом.', self::CONTENT, 'https://site.test/go/p7' );

		self::assertSame(
			"Маг на заклинаниях выигрывает темпом.\n\nВ статье:\n— Список карт\n— Муллиган & тактика\n\nЧитать полностью: https://site.test/go/p7",
			$text
		);
	}

	public function test_empty_parts_are_skipped(): void {
		self::assertSame( 'Читать полностью: https://site.test/go/p7', VideoBlurb::build( '', '<p>Без подзаголовков.</p>', 'https://site.test/go/p7' ) );
		self::assertSame( 'Коротко о главном.', VideoBlurb::build( 'Коротко о главном.', '', '' ) );
		self::assertSame( array( 'Список карт', 'Муллиган & тактика' ), VideoBlurb::headings( self::CONTENT ) );
	}
}
