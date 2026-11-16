<?php
/**
 * Отчёт «Обновить после патча».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Game\DeckVariables;
use IndexPilot\Game\Module;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Resolver\PostDates;

/**
 * Экран группы «Контент»: гайды и колоды, которые не обновлялись с выхода текущего патча.
 * Номер и дата патча задаются в настройках. Скриптов и стилей у экрана нет.
 */
final class PatchReport {

	public const SLUG = 'indexpilot-patch';

	/**
	 * Сколько записей каждого вида просматривается.
	 */
	private const LIMIT = 300;

	/**
	 * Конструктор.
	 *
	 * @param Module $module Игровой модуль: патч и правило устаревания.
	 */
	public function __construct( private Module $module ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 15 );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — обновить после патча', 'indexpilot' ),
			__( 'После патча', 'indexpilot' ),
			'edit_others_posts',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$patch    = $this->module->patch();
		$settings = admin_url( 'admin.php?page=' . SettingsPage::SLUG );

		echo '<div class="wrap"><h1>' . esc_html__( 'Обновить после патча', 'indexpilot' ) . '</h1>';

		if ( ! $patch->active() ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Номер и дата текущего патча не заданы.', 'indexpilot' ),
				esc_url( $settings ),
				esc_html__( 'Указать в настройках', 'indexpilot' )
			);

			return;
		}

		$posts = $this->outdated( $patch->date );
		$total = count( $posts );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: номер патча, 2: дата выхода, 3: число записей. */
					__( 'Патч %1$s вышел %2$s. Гайды и колоды, которые с тех пор не обновлялись: %3$d. Проверьте состав и советы, обновите текст — запись уйдёт из списка после сохранения. Архивные колоды и старые версии колод сюда не попадают.', 'indexpilot' ),
					$patch->version,
					mysql2date( (string) get_option( 'date_format' ), $patch->date ),
					$total
				)
			)
		);

		if ( 0 === $total ) {
			echo '<p><strong>' . esc_html__( 'Всё актуально.', 'indexpilot' ) . '</strong></p></div>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Запись', 'indexpilot' ) . '</th><th>' . esc_html__( 'Тип материала', 'indexpilot' ) . '</th><th>' . esc_html__( 'Обновлена', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( $posts as $post ) {
			$edit = get_edit_post_link( $post->ID );

			printf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a> · <a href="%3$s">%4$s</a></td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( is_string( $edit ) ? $edit : '' ),
				esc_html( '' !== $post->post_title ? wp_strip_all_tags( $post->post_title ) : __( '(без названия)', 'indexpilot' ) ),
				esc_url( (string) get_permalink( $post ) ),
				esc_html__( 'открыть', 'indexpilot' ),
				esc_html( PostMaterial::label( $post ) ),
				esc_html( PostDates::modified( (string) get_option( 'date_format' ), false, $post, true ) )
			);
		}

		echo '</tbody></table>';

		if ( $total >= self::LIMIT ) {
			echo '<p class="description">' . esc_html__( 'Показаны записи, изменённые незадолго до патча. Обновите их — в списке появятся следующие.', 'indexpilot' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Устаревшие записи: сначала изменённые незадолго до патча.
	 *
	 * @param string $date Дата выхода патча, ГГГГ-ММ-ДД.
	 * @return list<\WP_Post>
	 */
	private function outdated( string $date ): array {
		$types  = post_type_exists( DeckVariables::POST_TYPE ) ? array( 'post', DeckVariables::POST_TYPE ) : array( 'post' );
		$patch  = $this->module->patch();
		$result = array();

		foreach ( $types as $type ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => self::LIMIT,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'date_query'     => array(
						array(
							'column'    => 'post_modified',
							'before'    => $date,
							'inclusive' => false,
						),
					),
				)
			);

			foreach ( $posts as $post ) {
				if ( $this->module->outdated( $post, $patch ) ) {
					$result[] = $post;
				}
			}
		}

		return $result;
	}
}
