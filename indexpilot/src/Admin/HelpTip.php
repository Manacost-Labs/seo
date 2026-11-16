<?php
/**
 * Значок «?» с подсказкой у поля панели.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

/**
 * Печатает кружок со знаком вопроса. При наведении мыши или переходе клавишей Tab появляется подсказка:
 * что вписать в поле и зачем это нужно. Подсказка сделана стилями, без скриптов.
 */
final class HelpTip {

	/**
	 * HTML значка.
	 *
	 * @param string $what Что вписать или что делает пункт.
	 * @param string $why  Зачем это нужно.
	 */
	public static function html( string $what, string $why = '' ): string {
		$text = __( 'Что вписать:', 'indexpilot' ) . ' ' . $what;

		if ( '' !== $why ) {
			$text .= "\n\n" . __( 'Зачем:', 'indexpilot' ) . ' ' . $why;
		}

		return sprintf(
			'<span class="indexpilot-help" tabindex="0" role="note" aria-label="%1$s" data-tip="%1$s">?</span>',
			esc_attr( $text )
		);
	}

	/**
	 * Печатает значок.
	 *
	 * @param string $what Что вписать или что делает пункт.
	 * @param string $why  Зачем это нужно.
	 */
	public static function render( string $what, string $why = '' ): void {
		echo self::html( $what, $why ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- текст экранирован в html().
	}
}
