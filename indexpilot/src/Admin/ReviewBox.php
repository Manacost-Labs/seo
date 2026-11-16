<?php
/**
 * Поле «Проверил» в редакторе записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Authors\AuthorPages;
use IndexPilot\Authors\Review;

/**
 * Небольшая панель сбоку редактора: кто из авторов подтвердил актуальность материала.
 * Подтверждение относится к версии текста: после правки состава колоды или выводов отметка снимается,
 * и панель пишет «нужна повторная проверка».
 */
final class ReviewBox {

	/**
	 * Временное значение версии: подтверждение принято, отпечаток посчитается после сохранения всех полей записи.
	 */
	public const CONFIRM = 'confirm';

	private const NONCE = 'indexpilot_review';

	/**
	 * Подключает панель.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Добавляет панель на экран записи открытого типа.
	 *
	 * @param string   $post_type Тип записи.
	 * @param \WP_Post $post      Запись.
	 */
	public function add( $post_type, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- подпись хука add_meta_boxes.
		if ( ! is_string( $post_type ) || ! is_post_type_viewable( $post_type ) || 'attachment' === $post_type ) {
			return;
		}

		add_meta_box( 'indexpilot-review', __( 'Проверил', 'indexpilot' ), array( $this, 'render' ), $post_type, 'side', 'low' );
	}

	/**
	 * Печатает панель.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$reviewer = (int) get_post_meta( $post->ID, Review::REVIEWER, true );
		$version  = (string) get_post_meta( $post->ID, Review::VERSION, true );
		$date     = (string) get_post_meta( $post->ID, Review::DATE, true );
		$status   = self::CONFIRM === $version ? Review::VALID : Review::status( $reviewer, $version, AuthorPages::fingerprint( $post ) );
		$users    = get_users(
			array(
				'capability' => 'edit_posts',
				'fields'     => array( 'ID', 'display_name' ),
				'number'     => 200,
				'orderby'    => 'display_name',
			)
		);

		wp_nonce_field( self::NONCE, 'indexpilot_review_nonce' );
		?>
		<p>
			<label for="indexpilot-reviewer"><?php esc_html_e( 'Кто проверил', 'indexpilot' ); ?></label>
			<?php HelpTip::render( __( 'Второго автора, который прочитал материал и подтвердил, что он верен для текущего патча.', 'indexpilot' ), __( 'Отметка о проверке видна поисковикам (reviewedBy) и повышает доверие к гайду. После смены колоды или выводов её нужно подтвердить заново.', 'indexpilot' ) ); ?>
			<select id="indexpilot-reviewer" name="indexpilot_reviewer" class="widefat">
				<option value="0"><?php esc_html_e( '— никто —', 'indexpilot' ); ?></option>
				<?php foreach ( $users as $user ) : ?>
					<?php if ( (int) $user->ID !== (int) $post->post_author ) : ?>
						<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $reviewer, (int) $user->ID ); ?>><?php echo esc_html( (string) $user->display_name ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><input type="checkbox" name="indexpilot_review_confirm" value="1"> <?php esc_html_e( 'Проверено: текущая версия верна', 'indexpilot' ); ?></label>
		</p>
		<p class="description" data-indexpilot-review="<?php echo esc_attr( $status ); ?>">
			<?php
			if ( Review::VALID === $status ) {
				/* translators: %s: дата проверки. */
				echo esc_html( '' !== $date ? sprintf( __( 'Проверено %s.', 'indexpilot' ), mysql2date( (string) get_option( 'date_format' ), $date ) ) : __( 'Проверено.', 'indexpilot' ) );
			} elseif ( Review::STALE === $status ) {
				echo '<strong>' . esc_html__( 'Нужна повторная проверка: колода или выводы изменились после проверки.', 'indexpilot' ) . '</strong>';
			} else {
				esc_html_e( 'Материал ещё не проверен вторым автором.', 'indexpilot' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Сохраняет проверяющего. Версия текста считается позже, в AuthorPages::after_save, когда сохранены все поля записи.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function save( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- подпись хука save_post.
		if ( ! isset( $_POST['indexpilot_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['indexpilot_review_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( (int) $post_id ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		$post_id  = (int) $post_id;
		$reviewer = isset( $_POST['indexpilot_reviewer'] ) ? absint( wp_unslash( $_POST['indexpilot_reviewer'] ) ) : 0;
		$before   = (int) get_post_meta( $post_id, Review::REVIEWER, true );

		// Проверяющим может быть только автор сайта.
		if ( $reviewer > 0 && ! user_can( $reviewer, 'edit_posts' ) ) {
			$reviewer = 0;
		}

		if ( 0 === $reviewer ) {
			delete_post_meta( $post_id, Review::REVIEWER );
			delete_post_meta( $post_id, Review::VERSION );
			delete_post_meta( $post_id, Review::DATE );

			return;
		}

		update_post_meta( $post_id, Review::REVIEWER, $reviewer );

		if ( ! empty( $_POST['indexpilot_review_confirm'] ) || $reviewer !== $before ) {
			update_post_meta( $post_id, Review::VERSION, self::CONFIRM );
		}
	}
}
