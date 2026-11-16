<?php
/**
 * Отчёт «Авторы с незаполненным профилем».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\HelpTip;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Authors\AuthorProfile;
use IndexPilot\Authors\AuthorSummary;

/**
 * Авторы опубликованных материалов, у которых в профиле нет рассказа о себе, ссылок на площадки, фото или роли.
 * Без этого страница автора пустая, а поисковик и ассистент не видят, кто пишет гайды.
 */
final class AuthorsReport {

	public const SLUG = 'indexpilot-authors';

	private const CAPABILITY = 'edit_others_posts';

	/**
	 * Подключает экран.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 14 );
	}

	/**
	 * Пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — авторы', 'indexpilot' ),
			__( 'Авторы', 'indexpilot' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Печатает экран.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$labels   = array(
			'description' => __( 'рассказ о себе', 'indexpilot' ),
			'same_as'     => __( 'ссылки на площадки', 'indexpilot' ),
			'image'       => __( 'фото', 'indexpilot' ),
			'role'        => __( 'роль', 'indexpilot' ),
		);
		$profiles = new AuthorProfile();
		$users    = get_users(
			array(
				'has_published_posts' => true,
				'number'              => 300,
				'orderby'             => 'display_name',
			)
		);
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Авторы с незаполненным профилем', 'indexpilot' ); ?>
				<?php HelpTip::render( __( 'Ничего — это список. Откройте профиль автора и заполните недостающее в разделе IndexPilot.', 'indexpilot' ), __( 'Заполненный профиль показывает поисковику и ассистентам, что гайд написал человек с опытом. Ссылки на Twitch и YouTube попадают в разметку автора.', 'indexpilot' ) ); ?>
			</h1>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Автор', 'indexpilot' ); ?></th><th><?php esc_html_e( 'Материалов', 'indexpilot' ); ?></th><th><?php esc_html_e( 'Не заполнено', 'indexpilot' ); ?></th></tr></thead>
				<tbody>
				<?php
				$shown = 0;

				foreach ( $users as $user ) {
					$missing = AuthorSummary::missing( $profiles->get( (int) $user->ID ) );

					if ( array() === $missing ) {
						continue;
					}

					++$shown;
					$name = current_user_can( 'edit_user', (int) $user->ID )
						? '<a href="' . esc_url( get_edit_user_link( (int) $user->ID ) ) . '">' . esc_html( (string) $user->display_name ) . '</a>'
						: esc_html( (string) $user->display_name );

					printf(
						'<tr data-indexpilot-author="%1$d"><td>%2$s</td><td>%3$d</td><td>%4$s</td></tr>',
						(int) $user->ID,
						$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- собрано из экранированных частей выше.
						(int) AuthorProfile::post_count( (int) $user->ID ),
						esc_html( implode( ', ', array_map( static fn( string $key ): string => $labels[ $key ], $missing ) ) )
					);
				}

				if ( 0 === $shown ) {
					echo '<tr><td colspan="3">' . esc_html__( 'У всех авторов профиль заполнен.', 'indexpilot' ) . '</td></tr>';
				}
				?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
