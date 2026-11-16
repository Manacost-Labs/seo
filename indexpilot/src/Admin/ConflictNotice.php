<?php
/**
 * Уведомление о другом активном SEO-плагине.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Compat\ConflictDetector;

/**
 * Объясняет администратору, почему IndexPilot пока не выводит теги, и ведёт на страницу импорта.
 */
final class ConflictNotice {

	/**
	 * Конструктор.
	 *
	 * @param ConflictDetector $detector Поиск других SEO-плагинов.
	 */
	public function __construct( private ConflictDetector $detector ) {
	}

	/**
	 * Подключает уведомление.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Печатает уведомление на консоли, странице плагинов и страницах IndexPilot.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || ! ( in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || str_contains( $screen->id, 'indexpilot' ) ) ) {
			return;
		}

		$found = $this->detector->detect();
		if ( array() === $found ) {
			return;
		}

		$names = implode( ', ', $found );

		if ( $this->detector->output_allowed() ) {
			/* translators: %s: названия плагинов через запятую. */
			$message = sprintf( __( 'IndexPilot выводит SEO-теги одновременно с: %s. На страницах будут повторяющиеся теги — отключите вывод одного из источников.', 'indexpilot' ), $names );
		} else {
			/* translators: %s: названия плагинов через запятую. */
			$message = sprintf( __( 'IndexPilot пока не выводит SEO-теги на сайте, потому что их выводит: %s. Перенесите данные и отключите этот плагин — после этого теги начнёт выводить IndexPilot.', 'indexpilot' ), $names );
		}

		printf(
			'<div class="notice notice-warning" data-indexpilot-conflict><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=' . ImportPage::SLUG ) ),
			esc_html__( 'Перейти к импорту', 'indexpilot' )
		);
	}
}
