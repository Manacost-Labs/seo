<?php
/**
 * Спойлер в стиле сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

/**
 * Раскрывающийся блок на <details>: открывается без скриптов, текст внутри есть в HTML страницы и виден
 * поисковикам. Вид повторяет спойлеры Shortcodes Ultimate в стиле «fancy», которыми оформлены статьи сайта:
 * светлая рамка, серая шапка со стрелкой, белое поле текста.
 */
final class Spoiler {

	/**
	 * Стиль спойлера.
	 */
	public const CSS = '.indexpilot-spoiler{border:1px solid #ccc;border-radius:10px;background:#fff;color:#333;margin:0 0 1.5em}'
		. '.indexpilot-spoiler+.indexpilot-spoiler{margin-top:-.9em}'
		. '.indexpilot-spoiler>summary{list-style:none;cursor:pointer;position:relative;padding:8px 12px 8px 36px;background:#f0f0f0;border-radius:10px;font-weight:700;font-size:.95em;line-height:1.45}'
		. '.indexpilot-spoiler>summary::-webkit-details-marker{display:none}'
		. '.indexpilot-spoiler>summary:before{content:"";position:absolute;left:14px;top:50%;width:7px;height:7px;border:solid currentColor;border-width:0 2px 2px 0;transform:translateY(-50%) rotate(-45deg);transition:transform .2s}'
		. '.indexpilot-spoiler[open]>summary{border-bottom:1px solid #ccc;border-radius:10px 10px 0 0}'
		. '.indexpilot-spoiler[open]>summary:before{transform:translateY(-75%) rotate(45deg)}'
		. '.indexpilot-spoiler>summary h3{display:inline;margin:0;padding:0;font:inherit;color:inherit}'
		. '.indexpilot-spoiler-body{padding:14px}'
		. '.indexpilot-spoiler-body p{margin:0 0 .6em}.indexpilot-spoiler-body p:last-child{margin-bottom:0}';

	/**
	 * HTML спойлера.
	 *
	 * @param string $kind    Класс блока.
	 * @param string $summary HTML заголовка (уже экранирован).
	 * @param string $body    HTML содержимого (уже экранирован).
	 * @param bool   $open    Раскрыт ли сразу.
	 */
	public static function html( string $kind, string $summary, string $body, bool $open = false ): string {
		return '<details class="indexpilot-spoiler ' . $kind . '"' . ( $open ? ' open' : '' ) . '>'
			. '<summary>' . $summary . '</summary>'
			. '<div class="indexpilot-spoiler-body">' . $body . '</div>'
			. '</details>';
	}

	/**
	 * Печатает стиль в head записи, где есть краткий ответ или вопросы и ответы. Стиль не идёт вместе с блоком:
	 * тема или другой плагин могут прогнать текст записи заранее (для анонса), и стиль ушёл бы туда.
	 */
	public static function print_style(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();

		if ( '' === trim( (string) get_post_meta( $post_id, \IndexPilot\Meta\MetaKeys::ANSWER, true ) ) && empty( get_post_meta( $post_id, \IndexPilot\Meta\MetaKeys::FAQ, true ) ) ) {
			return;
		}

		echo '<style id="indexpilot-spoiler">' . self::CSS . '</style>' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- постоянная строка стиля.
	}
}
