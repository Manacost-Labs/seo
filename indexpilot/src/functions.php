<?php
/**
 * Функции для шаблонов темы.
 *
 * @package IndexPilot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Печатает текст рубрики, который задан в поле «Текст под списком записей».
 *
 * Нужна темам, которые выводят записи не стандартным циклом WordPress:
 * в таких темах текст не появится сам, функцию вызывают в шаблоне архива.
 */
function indexpilot_archive_outro(): void {
	\IndexPilot\Plugin::instance()->archive_text()->print_outro();
}

/**
 * Хлебные крошки текущей страницы. Вызывается в шаблоне темы; то же выводит шорткод [indexpilot_breadcrumbs].
 *
 * @param bool $display Напечатать цепочку (true) или только вернуть её HTML (false).
 */
function indexpilot_breadcrumbs( bool $display = true ): string {
	$html = \IndexPilot\Plugin::instance()->breadcrumbs()->html();

	if ( $display ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран в Breadcrumbs\Output, все значения там экранированы.
		echo $html;
	}

	return $html;
}

/**
 * Блок «Об авторе» для записи. Вызывается в шаблоне темы; то же выводит шорткод [indexpilot_author].
 *
 * @param int  $post_id ID записи; 0 — текущая запись.
 * @param bool $display Напечатать блок (true) или только вернуть его HTML (false).
 */
function indexpilot_author_box( int $post_id = 0, bool $display = true ): string {
	$html = \IndexPilot\Plugin::instance()->author_box()->html( $post_id );

	if ( $display ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран в Authors\AuthorBox, все значения там экранированы.
		echo $html;
	}

	return $html;
}

/**
 * Блок связанных ссылок для страницы колоды: архетип, гайды по нему и другие колоды архетипа.
 * Вызывается в шаблоне темы; то же выводит шорткод [indexpilot_deck_links].
 *
 * @param int  $post_id ID колоды; 0 — текущая запись.
 * @param bool $display Напечатать блок (true) или только вернуть его HTML (false).
 */
function indexpilot_deck_links( int $post_id = 0, bool $display = true ): string {
	$html = \IndexPilot\Plugin::instance()->game()->links_html( $post_id );

	if ( $display ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран в Game\\Module, все значения там экранированы.
		echo $html;
	}

	return $html;
}
