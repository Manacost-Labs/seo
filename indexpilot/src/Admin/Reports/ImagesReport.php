<?php
/**
 * Отчёт «Картинки».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\SettingsPage;

/**
 * Картинки медиатеки без описания (alt) с правкой на месте: описания заполняются пачкой и сохраняются одной кнопкой.
 * Пустой alt у картинки-украшения — норма; такие картинки здесь можно просто пропустить.
 */
final class ImagesReport {

	public const SLUG = 'indexpilot-images';

	/**
	 * Право на экран и правку: то же, что у загрузки файлов в медиатеку.
	 */
	private const CAPABILITY = 'upload_files';

	private const ACTION = 'indexpilot_images_alt';

	/**
	 * Сколько картинок на странице отчёта.
	 */
	private const PER_PAGE = 50;

	/**
	 * Наибольшая длина описания.
	 */
	private const MAX_LENGTH = 250;

	/**
	 * Подключает страницу и сохранение.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 14 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — картинки', 'indexpilot' ),
			__( 'Картинки', 'indexpilot' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Описания из формы: ID вложения => очищенный текст. Пустые значения и неверные ID пропускаются.
	 *
	 * @param mixed $raw Значение поля alt[] из формы.
	 * @return array<int, string>
	 */
	public static function alts_from_form( $raw ): array {
		$alts = array();

		foreach ( is_array( $raw ) ? $raw : array() as $id => $value ) {
			if ( ! is_string( $value ) || ! ctype_digit( (string) $id ) || (int) $id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- чистая функция проверяется без WordPress.
			$text = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) );

			if ( '' !== $text ) {
				$alts[ (int) $id ] = mb_substr( $text, 0, self::MAX_LENGTH );
			}
		}

		return $alts;
	}

	/**
	 * Сохраняет описания и возвращает на ту же страницу отчёта.
	 */
	public function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'indexpilot' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		// Каждое значение очищает alts_from_form(): теги убираются, пробелы схлопываются, длина ограничена.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$alts  = self::alts_from_form( isset( $_POST['alt'] ) ? wp_unslash( $_POST['alt'] ) : array() );
		$saved = 0;

		foreach ( $alts as $id => $alt ) {
			if ( ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			++$saved;
		}

		$paged = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::SLUG,
					'paged' => $paged,
					'saved' => $saved,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- номер страницы и число сохранённых только выводятся.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : -1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total = $this->count();
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$ids   = $this->without_alt( $paged );
		?>
		<div class="wrap" data-indexpilot-images>
			<h1><?php esc_html_e( 'IndexPilot — картинки', 'indexpilot' ); ?></h1>
			<p><?php esc_html_e( 'Картинки медиатеки без описания (alt). Описание читают поисковики и программы чтения с экрана: напишите, что на картинке, — «Колода жреца на воскрешении — список карт», а не имя файла. Описание сохранится в медиатеке и подставится при следующей вставке картинки; в уже опубликованных записях alt правится в тексте.', 'indexpilot' ); ?></p>
			<p class="description"><?php esc_html_e( 'Картинке-украшению (разделитель, фон, значок рядом с текстом) описание не нужно: оставьте поле пустым, а в записи добавьте картинке класс is-decorative — тогда анализ не будет считать её ошибкой.', 'indexpilot' ); ?></p>
			<?php if ( $saved >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: число сохранённых описаний. */
					echo esc_html( sprintf( __( 'Сохранено описаний: %d.', 'indexpilot' ), $saved ) );
					?>
				</p></div>
			<?php endif; ?>
			<p data-indexpilot-images-count>
				<?php
				/* translators: %d: число картинок. */
				echo esc_html( sprintf( __( 'Картинок без описания: %d.', 'indexpilot' ), $total ) );
				?>
			</p>
			<?php if ( array() === $ids ) : ?>
				<p><?php esc_html_e( 'У всех картинок медиатеки есть описание.', 'indexpilot' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col" style="width:80px"><?php esc_html_e( 'Картинка', 'indexpilot' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Файл', 'indexpilot' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Где загружена', 'indexpilot' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Описание (alt)', 'indexpilot' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ids as $id ) : ?>
								<?php $parent = (int) wp_get_post_parent_id( $id ); ?>
								<tr>
									<td><?php echo wp_get_attachment_image( $id, array( 60, 60 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- готовый тег картинки от WordPress. ?></td>
									<td><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?></td>
									<td>
										<?php if ( $parent > 0 && current_user_can( 'edit_post', $parent ) ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( $parent ) ); ?>"><?php echo esc_html( wp_strip_all_tags( get_the_title( $parent ) ) ); ?></a>
										<?php elseif ( $parent > 0 && current_user_can( 'read_post', $parent ) ) : ?>
											<?php echo esc_html( wp_strip_all_tags( get_the_title( $parent ) ) ); ?>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td>
										<label class="screen-reader-text" for="indexpilot-alt-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Описание картинки', 'indexpilot' ); ?></label>
										<input type="text" class="large-text" id="indexpilot-alt-<?php echo esc_attr( (string) $id ); ?>" name="alt[<?php echo esc_attr( (string) $id ); ?>]" maxlength="<?php echo esc_attr( (string) self::MAX_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Что на картинке', 'indexpilot' ); ?>">
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php submit_button( __( 'Сохранить описания', 'indexpilot' ) ); ?>
				</form>
				<?php
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=' . self::SLUG ) ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					echo '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Число картинок медиатеки без описания.
	 */
	private function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = %s )
				WHERE p.post_type = %s AND p.post_mime_type LIKE %s AND ( m.meta_value IS NULL OR m.meta_value = '' )",
				'_wp_attachment_image_alt',
				'attachment',
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);
	}

	/**
	 * Картинки без описания на странице отчёта, новые первыми.
	 *
	 * @param int $paged Номер страницы.
	 * @return list<int>
	 */
	private function without_alt( int $paged ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = %s )
				WHERE p.post_type = %s AND p.post_mime_type LIKE %s AND ( m.meta_value IS NULL OR m.meta_value = '' )
				ORDER BY p.ID DESC LIMIT %d OFFSET %d",
				'_wp_attachment_image_alt',
				'attachment',
				$wpdb->esc_like( 'image/' ) . '%',
				self::PER_PAGE,
				( $paged - 1 ) * self::PER_PAGE
			)
		);

		$ids = array_map( 'intval', (array) $ids );
		_prime_post_caches( $ids, false, true );

		return $ids;
	}
}
