<?php
/**
 * Подсказки карт в блоках IndexPilot.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

use IndexPilot\Meta\MetaKeys;

/**
 * Плагин подсказок карт (hs-tooltip) подключает свои стиль и скрипт, только если видит «[hs_card]» в тексте записи.
 * Карты в кратком ответе и в вопросах и ответах IndexPilot он не видит: шорткод выполняется, но подсказка
 * при наведении не всплывает. Класс подключает те же файлы под теми же именами — только если их ещё нет.
 */
final class CardTooltips {

	/**
	 * Имя стиля и скрипта плагина подсказок.
	 */
	public const HANDLE = 'hs-smart-tooltip';

	/**
	 * Есть ли в тексте карта шорткодом.
	 *
	 * @param string $text Краткий ответ или вопросы и ответы.
	 */
	public static function needs( string $text ): bool {
		return str_contains( $text, '[hs_card' );
	}

	/**
	 * Подключает проверку после плагина подсказок.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Подключает стиль и скрипт подсказок, если карты есть только в блоках IndexPilot.
	 */
	public function enqueue(): void {
		if ( ! is_singular() || ! function_exists( 'hs_smart_tooltip_enqueue_assets' ) || ! defined( 'HS_SMART_TOOLTIP_FRONTEND_SCRIPT' ) || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();

		if ( function_exists( 'hs_smart_tooltip_post_enabled' ) && ! hs_smart_tooltip_post_enabled( get_post( $post_id ) ) ) {
			return;
		}

		$text = (string) get_post_meta( $post_id, MetaKeys::ANSWER, true ) . wp_json_encode( get_post_meta( $post_id, MetaKeys::FAQ, true ), JSON_UNESCAPED_UNICODE );

		if ( ! self::needs( $text ) ) {
			return;
		}

		$base    = plugin_dir_url( (string) ( new \ReflectionFunction( 'hs_smart_tooltip_enqueue_assets' ) )->getFileName() ) . 'assets/';
		$version = defined( 'HS_SMART_TOOLTIP_VERSION' ) ? (string) constant( 'HS_SMART_TOOLTIP_VERSION' ) : false;

		wp_enqueue_style( self::HANDLE, $base . 'hs-tooltip.css', array(), $version );
		wp_enqueue_script( self::HANDLE, $base . (string) constant( 'HS_SMART_TOOLTIP_FRONTEND_SCRIPT' ), array(), $version, true );
	}
}
