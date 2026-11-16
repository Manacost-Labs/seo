<?php
/**
 * Кнопка «короткая ссылка» в списке записей и в верхней панели админки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Plugin;
use IndexPilot\ShortLinks\Router;
use IndexPilot\ShortLinks\Slug;

/**
 * Короткий адрес записи есть у каждой опубликованной записи; здесь он копируется в один щелчок.
 * На страницах сайта верхнюю панель с короткой ссылкой выводит сам WordPress (через фильтр pre_get_shortlink).
 */
final class ShortLinkActions {

	public function __construct( private Router $router ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 95 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Короткий адрес записи; пусто — у записи его нет (не опубликована или без страницы на сайте).
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function url( \WP_Post $post ): string {
		return 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ? $this->router->url( Slug::for_post( (int) $post->ID ) ) : '';
	}

	/**
	 * Кнопка в строке записи.
	 *
	 * @param array<string, string> $actions Действия строки.
	 * @param \WP_Post              $post    Запись.
	 * @return array<string, string>
	 */
	public function row_action( $actions, $post ): array {
		$actions = (array) $actions;

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = $this->url( $post );

		if ( '' !== $url ) {
			$actions['indexpilot_shortlink'] = sprintf(
				'<button type="button" class="button-link" data-indexpilot-copy="%1$s" title="%1$s">%2$s</button>',
				esc_attr( $url ),
				esc_html__( 'Короткая ссылка', 'indexpilot' )
			);
		}

		return $actions;
	}

	/**
	 * Пункт верхней панели на экране правки записи.
	 *
	 * @param \WP_Admin_Bar $bar Верхняя панель.
	 */
	public function admin_bar( $bar ): void {
		if ( ! is_admin() || ! $bar instanceof \WP_Admin_Bar || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		$post   = get_post();

		if ( null === $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$url = $this->url( $post );

		if ( '' === $url ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'indexpilot-shortlink',
				/* translators: %s: короткий адрес записи. */
				'title' => esc_html( sprintf( __( 'Короткая ссылка: %s', 'indexpilot' ), $url ) ),
				'href'  => $url,
				'meta'  => array(
					'title'  => __( 'Короткий адрес записи — для соцсетей и рассылок. Щелчок открывает его в новой вкладке.', 'indexpilot' ),
					'target' => '_blank',
				),
			)
		);
	}

	/**
	 * Копирование по кнопке в списке записей.
	 *
	 * @param string $hook Текущий экран.
	 */
	public function enqueue( $hook ): void {
		if ( 'edit.php' === $hook ) {
			wp_enqueue_script( 'indexpilot-search', plugins_url( 'assets/js/search.js', INDEXPILOT_FILE ), array(), Plugin::VERSION, true );
			wp_localize_script( 'indexpilot-search', 'IndexPilotSearch', array( 'copied' => __( 'Скопировано', 'indexpilot' ) ) );
		}
	}
}
