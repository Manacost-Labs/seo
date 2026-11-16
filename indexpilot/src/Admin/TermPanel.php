<?php
/**
 * SEO-поля на экране редактирования рубрики, метки и других терминов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;

/**
 * Рубрика — посадочная страница: у неё свои заголовок, описание, ключевое слово и тексты над и под списком записей.
 */
final class TermPanel {

	private const NONCE_ACTION = 'indexpilot_save_term';
	private const NONCE_FIELD  = 'indexpilot_term_nonce';

	/**
	 * Поля формы: имя поля => ключ меты.
	 */
	private const FIELDS = array(
		'indexpilot_title'         => MetaKeys::TITLE,
		'indexpilot_description'   => MetaKeys::DESCRIPTION,
		'indexpilot_focus_keyword' => MetaKeys::FOCUS_KEYWORD,
		'indexpilot_intro'         => MetaKeys::INTRO,
		'indexpilot_outro'         => MetaKeys::OUTRO,
	);

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер мета-данных.
	 */
	public function __construct( private MetaResolver $resolver ) {
	}

	/**
	 * Подключает поля ко всем публичным таксономиям. Вызывается на init после регистрации таксономий.
	 */
	public function register(): void {
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render' ), 20, 2 );
		}

		add_action( 'edited_term', array( $this, 'save' ), 10, 3 );
	}

	/**
	 * Печатает поля в форме термина.
	 *
	 * @param \WP_Term $term     Термин.
	 * @param string   $taxonomy Таксономия.
	 */
	public function render( $term, $taxonomy ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$resolved = $this->resolver->resolve( new Context( Context::TERM, (int) $term->term_id, (string) $taxonomy ) );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<tr class="form-field">
			<th scope="row" colspan="2">
				<h2 class="indexpilot-term-heading"><?php esc_html_e( 'IndexPilot: SEO страницы', 'indexpilot' ); ?></h2>
			</th>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Сейчас в поиске', 'indexpilot' ); ?></th>
			<td>
				<p class="indexpilot-term-serp-title"><?php echo esc_html( $resolved->title ); ?></p>
				<p class="indexpilot-term-serp-description">
					<?php echo '' !== $resolved->description ? esc_html( $resolved->description ) : esc_html__( 'Описания нет.', 'indexpilot' ); ?>
				</p>
				<p class="description">
					<?php
					echo $resolved->is_indexable()
						? esc_html__( 'Страница открыта для индексации.', 'indexpilot' )
						: esc_html__( 'Страница закрыта от индексации (noindex).', 'indexpilot' );
					?>
				</p>
			</td>
		</tr>
		<?php
		/* translators: %s: список переменных шаблона. */
		$title_help = sprintf( __( 'Пусто — заголовок соберётся по шаблону таксономии. Можно использовать переменные: %s.', 'indexpilot' ), '%term%, %sep%, %sitename%' );
		$this->text_row( $term, 'indexpilot_title', __( 'SEO-заголовок', 'indexpilot' ), $title_help );
		$this->textarea_row( $term, 'indexpilot_description', __( 'Метаописание', 'indexpilot' ), __( 'Рекомендуемая длина — 120–155 символов. Пусто — возьмётся описание термина.', 'indexpilot' ) );
		$this->text_row( $term, 'indexpilot_focus_keyword', __( 'Главный запрос', 'indexpilot' ), __( 'Фраза, по которой эта страница должна находиться в поиске, например «колоды жреца».', 'indexpilot' ) );
		$this->editor_row( $term, 'indexpilot_intro', __( 'Текст над списком записей', 'indexpilot' ), __( 'Вступление: о чём раздел и что в нём найдёт читатель. Показывается на первой странице.', 'indexpilot' ) );
		$this->editor_row( $term, 'indexpilot_outro', __( 'Текст под списком записей', 'indexpilot' ), __( 'Подробный текст раздела. Показывается на первой странице.', 'indexpilot' ) );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Индексация', 'indexpilot' ); ?></th>
			<td>
				<label for="indexpilot_noindex">
					<input type="checkbox" id="indexpilot_noindex" name="indexpilot_noindex" value="1" <?php checked( (bool) get_term_meta( $term->term_id, MetaKeys::NOINDEX, true ) ); ?>>
					<?php esc_html_e( 'Не показывать эту страницу в поиске (noindex)', 'indexpilot' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Сохраняет поля термина.
	 *
	 * @param int    $term_id  ID термина.
	 * @param int    $tt_id    ID связи термина с таксономией.
	 * @param string $taxonomy Таксономия.
	 */
	public function save( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id, $taxonomy );

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}

		foreach ( self::FIELDS as $field => $meta_key ) {
			// Значение очищает sanitize_callback, заданный при регистрации меты (см. MetaRegistrar).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
			update_term_meta( (int) $term_id, $meta_key, is_string( $value ) ? $value : '' );
		}

		update_term_meta( (int) $term_id, MetaKeys::NOINDEX, isset( $_POST['indexpilot_noindex'] ) ? '1' : '' );
	}

	/**
	 * Строка с однострочным полем.
	 *
	 * @param \WP_Term $term  Термин.
	 * @param string   $field Имя поля.
	 * @param string   $label Подпись.
	 * @param string   $help  Пояснение.
	 */
	private function text_row( \WP_Term $term, string $field, string $label, string $help ): void {
		$value = (string) get_term_meta( $term->term_id, self::FIELDS[ $field ], true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка с многострочным полем.
	 *
	 * @param \WP_Term $term  Термин.
	 * @param string   $field Имя поля.
	 * @param string   $label Подпись.
	 * @param string   $help  Пояснение.
	 */
	private function textarea_row( \WP_Term $term, string $field, string $label, string $help ): void {
		$value = (string) get_term_meta( $term->term_id, self::FIELDS[ $field ], true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка с визуальным редактором.
	 *
	 * @param \WP_Term $term  Термин.
	 * @param string   $field Имя поля.
	 * @param string   $label Подпись.
	 * @param string   $help  Пояснение.
	 */
	private function editor_row( \WP_Term $term, string $field, string $label, string $help ): void {
		$value = (string) get_term_meta( $term->term_id, self::FIELDS[ $field ], true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$value,
					$field,
					array(
						'textarea_name' => $field,
						'textarea_rows' => 6,
						'media_buttons' => false,
						'teeny'         => true,
					)
				);
				?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}
}
