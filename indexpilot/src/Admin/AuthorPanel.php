<?php
/**
 * Поля IndexPilot в профиле пользователя.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Authors\AuthorData;
use IndexPilot\Authors\AuthorProfile;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Meta\MetaRegistrar;
use IndexPilot\Plugin;

/**
 * Автор (стример, игрок, редактор) указывает роль, ссылки на свои площадки, фото и описание своей страницы для поиска.
 * Рассказ о себе берётся из штатного поля «Биография».
 */
final class AuthorPanel {

	private const NONCE_ACTION = 'indexpilot_save_author';
	private const NONCE_FIELD  = 'indexpilot_author_nonce';

	/**
	 * Подключает поля и сохранение.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Подключает выбор фото из медиатеки на экране профиля.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'indexpilot-settings', plugins_url( 'assets/js/settings.js', INDEXPILOT_FILE ), array(), Plugin::VERSION, true );
	}

	/**
	 * Печатает поля.
	 *
	 * @param \WP_User $user Пользователь.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$image_id = (int) get_user_meta( $user->ID, AuthorProfile::IMAGE_ID, true );
		$image    = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		$links    = AuthorData::links( get_user_meta( $user->ID, AuthorProfile::LINKS, true ) );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<h2><?php esc_html_e( 'IndexPilot — автор в поиске', 'indexpilot' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Эти сведения показываются в блоке «Об авторе», на странице автора и передаются поисковикам вместе с каждой статьёй. Рассказ о себе берётся из поля «Биография» выше.', 'indexpilot' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="indexpilot_author_role"><?php esc_html_e( 'Роль', 'indexpilot' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="indexpilot_author_role" name="indexpilot_author_role" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, AuthorProfile::ROLE, true ) ); ?>">
					<p class="description"><?php esc_html_e( 'Коротко, со строчной буквы: стример, киберспортсмен, редактор.', 'indexpilot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="indexpilot_author_links"><?php esc_html_e( 'Площадки автора', 'indexpilot' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="4" id="indexpilot_author_links" name="indexpilot_author_links"><?php echo esc_textarea( implode( "\n", $links ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Полные адреса, по одному в строке: Twitch, YouTube, Telegram, ВКонтакте и другие.', 'indexpilot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Фото', 'indexpilot' ); ?></th>
				<td data-indexpilot-image>
					<input type="hidden" name="indexpilot_author_image_id" value="<?php echo esc_attr( (string) $image_id ); ?>" data-indexpilot-image-id>
					<img src="<?php echo esc_url( $image ); ?>" alt="" class="indexpilot-image-preview indexpilot-image-preview-small" data-indexpilot-image-preview <?php echo '' === $image ? 'hidden' : ''; ?>>
					<?php if ( current_user_can( 'upload_files' ) ) : ?>
						<button type="button" class="button" data-indexpilot-image-select><?php esc_html_e( 'Выбрать фото', 'indexpilot' ); ?></button>
						<button type="button" class="button-link" data-indexpilot-image-remove><?php esc_html_e( 'Убрать', 'indexpilot' ); ?></button>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Квадратное, не меньше 400 × 400. Без фото в блоке автора показывается аватар.', 'indexpilot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="indexpilot_author_title"><?php esc_html_e( 'Заголовок страницы автора', 'indexpilot' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="indexpilot_author_title" name="indexpilot_author_title" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, MetaKeys::TITLE, true ) ); ?>">
					<p class="description"><?php esc_html_e( 'Пусто — заголовок собирается по шаблону из настроек IndexPilot.', 'indexpilot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="indexpilot_author_description"><?php esc_html_e( 'Описание страницы автора', 'indexpilot' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="3" id="indexpilot_author_description" name="indexpilot_author_description"><?php echo esc_textarea( (string) get_user_meta( $user->ID, MetaKeys::DESCRIPTION, true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Одно-два предложения для поисковой выдачи. Пусто — берётся начало биографии.', 'indexpilot' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Сохраняет поля.
	 *
	 * @param int $user_id ID пользователя.
	 */
	public function save( $user_id ): void {
		$user_id = (int) $user_id;
		$nonce   = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значения очищаются ниже: template_text, AuthorData::links, absint.
		$role        = isset( $_POST['indexpilot_author_role'] ) ? (string) wp_unslash( $_POST['indexpilot_author_role'] ) : '';
		$links       = isset( $_POST['indexpilot_author_links'] ) ? (string) wp_unslash( $_POST['indexpilot_author_links'] ) : '';
		$title       = isset( $_POST['indexpilot_author_title'] ) ? (string) wp_unslash( $_POST['indexpilot_author_title'] ) : '';
		$description = isset( $_POST['indexpilot_author_description'] ) ? (string) wp_unslash( $_POST['indexpilot_author_description'] ) : '';
		$image_id    = isset( $_POST['indexpilot_author_image_id'] ) ? absint( wp_unslash( $_POST['indexpilot_author_image_id'] ) ) : 0;
		// phpcs:enable

		$values = array(
			AuthorProfile::ROLE     => sanitize_text_field( $role ),
			AuthorProfile::LINKS    => array_map( 'esc_url_raw', AuthorData::links( $links ) ),
			AuthorProfile::IMAGE_ID => wp_attachment_is_image( $image_id ) ? $image_id : 0,
			MetaKeys::TITLE         => MetaRegistrar::template_text( $title ),
			MetaKeys::DESCRIPTION   => MetaRegistrar::template_text( $description, true ),
		);

		foreach ( $values as $key => $value ) {
			if ( '' === $value || 0 === $value || array() === $value ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, wp_slash( $value ) );
			}
		}
	}
}
