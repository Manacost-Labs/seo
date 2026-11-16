<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Audit;

use IndexPilot\Audit\RenderedPage;
use PHPUnit\Framework\TestCase;

final class RenderedPageTest extends TestCase {

	private const SHELL = '<html><head><title>Колода</title><script src="/app.js"></script></head><body><header><nav>Главная Колоды Гайды Стримеры Новости Контакты</nav></header><main><h1>Маг на заклинаниях</h1>%s</main><footer>© Сайт, все права защищены, политика конфиденциальности</footer></body></html>';

	public function test_content_inserted_by_script_is_reported(): void {
		$html   = sprintf( self::SHELL, '<div id="deck" data-code="AAECAf0EBA"></div><script>document.getElementById("deck").innerHTML = "<ul><li>2 Чародейская стрела</li></ul>";</script>' );
		$result = RenderedPage::analyze( $html );

		self::assertTrue( $result['hidden'] );
		self::assertSame( RenderedPage::NOTE, $result['note'] );
	}

	public function test_deck_list_as_text_is_fine(): void {
		$cards = '';

		foreach ( array( 'Чародейская стрела', 'Ледяная стрела', 'Огненный шар', 'Чародейский интеллект', 'Ледяная глыба', 'Зеркальное отражение', 'Пиромант', 'Вызов пламени', 'Огненный элементаль', 'Архимаг Антонидас' ) as $card ) {
			$cards .= '<li>2 × ' . $card . ' — мана 2</li>';
		}

		$result = RenderedPage::analyze( sprintf( self::SHELL, '<p>Колода мага на заклинаниях для режима Стандарт.</p><ul>' . $cards . '</ul>' ) );

		self::assertFalse( $result['hidden'] );
		self::assertSame( '', $result['note'] );
	}

	public function test_expected_card_names_must_be_in_text(): void {
		$html = sprintf( self::SHELL, str_repeat( '<p>Длинный разбор колоды без самого списка карт, только слова о матчапах и муллигане.</p>', 5 ) );

		self::assertFalse( RenderedPage::analyze( $html )['hidden'] );
		self::assertTrue( RenderedPage::analyze( $html, array( 'Огненный шар', 'Пиромант' ) )['hidden'] );
		self::assertFalse( RenderedPage::analyze( str_replace( 'муллигане.', 'муллигане: Огненный шар и Пиромант.', $html ), array( 'Огненный шар', 'Пиромант' ) )['hidden'] );
	}

	public function test_page_without_scripts_is_not_blamed_on_javascript(): void {
		self::assertFalse( RenderedPage::analyze( '<html><body><main><h1>Пусто</h1></main></body></html>' )['hidden'] );
	}
}
