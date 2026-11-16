<?php
/**
 * Выключатель блоков перехода у записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Флажок «Не показывать блоки перехода» на вкладке «Дополнительно» панели записи.
 *
 * Флажок печатает и сохраняет панель (Admin\ClassicMetabox): она же проверяет nonce и право на правку записи.
 */
final class PostSwitch {

	public const FIELD = 'indexpilot_promo_off';

	/**
	 * Печатает флажок.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		?>
		<p class="indexpilot-field" data-indexpilot-promo-switch>
			<label><input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" <?php checked( self::is_off( (int) $post->ID ) ); ?>> <?php esc_html_e( 'Не показывать блоки перехода', 'indexpilot' ); ?></label> <?php \IndexPilot\Admin\HelpTip::render( __( 'отметьте, если в этой статье не нужны блоки со ссылками на Boosty, Hearthpulse и соцсети: траурная новость, правила конкурса, материал партнёра.', 'indexpilot' ), __( 'блоки перехода приводят читателей на платные площадки; их правила задаются в разделе IndexPilot → Переходы, а здесь их можно выключить для одной статьи.', 'indexpilot' ) ); ?>
			<span class="description"><?php esc_html_e( 'В этой записи не будет блоков со ссылками на площадки (IndexPilot → Переходы).', 'indexpilot' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Выключены ли блоки перехода у записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function is_off( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, Blocks::META_OFF, true );
	}

	/**
	 * Сохраняет флажок. Вызывающий код уже проверил nonce и право на правку записи.
	 *
	 * @param int  $post_id ID записи.
	 * @param bool $off     Выключить ли блоки перехода.
	 */
	public function save( int $post_id, bool $off ): void {
		if ( $off ) {
			update_post_meta( $post_id, Blocks::META_OFF, '1' );
		} else {
			delete_post_meta( $post_id, Blocks::META_OFF );
		}
	}
}
