<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Admin;

use IndexPilot\Admin\Menu;
use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase {

	/**
	 * Пункты в том порядке, в каком их регистрируют страницы плагина.
	 *
	 * @return list<array{0: string, 1: string, 2: string}>
	 */
	private function items(): array {
		return array(
			array( 'Настройки', 'manage_options', 'indexpilot' ),
			array( 'Поисковики', 'manage_options', 'indexpilot-search' ),
			array( 'Импорт', 'manage_options', 'indexpilot-import' ),
			array( 'Обзор', 'edit_others_posts', 'indexpilot-overview' ),
			array( 'Редиректы', 'manage_options', 'indexpilot-redirects' ),
			array( 'Ссылки', 'manage_options', 'indexpilot-links' ),
			array( 'Массовый редактор', 'edit_others_posts', 'indexpilot-bulk' ),
			array( 'Аудит', 'manage_options', 'indexpilot-audit' ),
			array( 'Короткие ссылки', 'edit_others_posts', 'indexpilot-shortlinks' ),
			array( 'Блоки перехода', 'manage_options', 'indexpilot-promo' ),
			array( 'Переходы', 'edit_others_posts', 'indexpilot-promo-report' ),
		);
	}

	public function test_eleven_screens_become_six_menu_items_in_working_order(): void {
		$menu = Menu::arrange( $this->items(), static fn( string $cap ): bool => true );

		self::assertSame(
			array( 'Обзор', 'Контент', 'Переходы', 'Техника', 'Настройки', 'Импорт' ),
			array_column( $menu['items'], 0 )
		);
		self::assertSame(
			array( 'indexpilot-overview', 'indexpilot-bulk', 'indexpilot-promo-report', 'indexpilot-redirects', 'indexpilot', 'indexpilot-import' ),
			array_column( $menu['items'], 2 )
		);
	}

	public function test_screens_of_one_group_share_tabs_and_highlight_the_group_item(): void {
		$menu = Menu::arrange( $this->items(), static fn( string $cap ): bool => true );

		self::assertSame( 'indexpilot-bulk', $menu['active']['indexpilot-audit'] );
		self::assertSame( 'indexpilot-redirects', $menu['active']['indexpilot-shortlinks'] );
		self::assertSame(
			array( 'Массовый редактор', 'Аудит', 'Ссылки' ),
			array_column( $menu['tabs']['indexpilot-links'], 'title' )
		);
		self::assertSame( $menu['tabs']['indexpilot-bulk'], $menu['tabs']['indexpilot-audit'] );
		self::assertArrayNotHasKey( 'indexpilot-overview', $menu['tabs'] );
	}

	public function test_images_report_is_a_tab_of_the_content_group(): void {
		$items   = $this->items();
		$items[] = array( 'Картинки', 'upload_files', 'indexpilot-images' );
		$menu    = Menu::arrange( $items, static fn( string $cap ): bool => true );

		self::assertSame( 'indexpilot-bulk', $menu['active']['indexpilot-images'] );
		self::assertSame( array( 'Массовый редактор', 'Аудит', 'Ссылки', 'Картинки' ), array_column( $menu['tabs']['indexpilot-images'], 'title' ) );
	}

	public function test_editor_sees_only_the_screens_available_to_them(): void {
		$menu = Menu::arrange( $this->items(), static fn( string $cap ): bool => 'edit_others_posts' === $cap );

		self::assertSame( array( 'Обзор', 'Контент', 'Переходы', 'Техника' ), array_column( $menu['items'], 0 ) );
		self::assertSame(
			array( 'indexpilot-overview', 'indexpilot-bulk', 'indexpilot-promo-report', 'indexpilot-shortlinks' ),
			array_column( $menu['items'], 2 ),
			'Пункт группы ведёт на первый доступный экран.'
		);
		self::assertArrayNotHasKey( 'indexpilot-bulk', $menu['tabs'], 'Одна доступная страница — вкладки не нужны.' );
	}

	public function test_unknown_screen_is_kept_at_the_end(): void {
		$items   = $this->items();
		$items[] = array( 'Новый экран', 'manage_options', 'indexpilot-new' );
		$menu    = Menu::arrange( $items, static fn( string $cap ): bool => true );

		self::assertSame( 'indexpilot-new', $menu['items'][6][2] );
	}
}
