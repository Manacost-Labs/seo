<?php
/**
 * Чужие повторы тегов в head.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Темы и плагины печатают canonical, description, prev/next и теги соцсетей, если не видят известного
 * SEO-плагина (например, тема Newspaper на страницах с постраничным списком). Класс собирает вывод wp_head
 * и убирает вне блока IndexPilot те теги, которые есть в самом блоке. Остальной вывод не трогается.
 */
final class HeadDedupe {

	/**
	 * Начало и конец блока IndexPilot в head.
	 */
	public const OPEN  = '<!-- IndexPilot';
	public const CLOSE = '<!-- /IndexPilot -->';

	/**
	 * Теги: link rel=canonical/prev/next и meta name/property.
	 */
	private const TAG = '/<(?:link\s[^>]*\brel=["\'](canonical|prev|next)["\']|meta\s[^>]*\b(?:name|property)=["\']((?:description|og:[a-z_:]+|twitter:[a-z_:]+|article:[a-z_:]+))["\'])[^>]*>\s*/i';

	/**
	 * Убирает вне блока IndexPilot повторы тегов, которые есть в блоке.
	 *
	 * @param string $html Вывод wp_head.
	 */
	public static function clean( string $html ): string {
		$start = strpos( $html, self::OPEN );
		$end   = false === $start ? false : strpos( $html, self::CLOSE, $start );

		if ( false === $start || false === $end ) {
			return $html;
		}

		$end  += strlen( self::CLOSE );
		$block = substr( $html, $start, $end - $start );
		$ours  = array();

		if ( preg_match_all( self::TAG, $block, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $tag ) {
				$ours[ self::key( $tag ) ] = true;
			}
		}

		if ( array() === $ours ) {
			return $html;
		}

		$strip = static fn( string $part ): string => (string) preg_replace_callback(
			self::TAG,
			static fn( array $tag ): string => isset( $ours[ self::key( $tag ) ] ) ? '' : $tag[0],
			$part
		);

		return $strip( substr( $html, 0, $start ) ) . $block . $strip( substr( $html, $end ) );
	}

	/**
	 * Подключает сборку вывода wp_head. Вызывается, только когда теги печатает IndexPilot.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'start' ), PHP_INT_MIN );
		add_action( 'wp_head', array( $this, 'finish' ), PHP_INT_MAX );
	}

	/**
	 * Начинает сборку вывода.
	 */
	public function start(): void {
		ob_start();
	}

	/**
	 * Печатает собранный вывод без повторов.
	 */
	public function finish(): void {
		$html = ob_get_clean();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- вывод wp_head других плагинов и тем, из него только убираются повторы.
		echo false === $html ? '' : self::clean( $html );
	}

	/**
	 * Ключ тега: вид и имя.
	 *
	 * @param array<int|string, string> $tag Совпадение.
	 */
	private static function key( array $tag ): string {
		return '' !== ( $tag[1] ?? '' ) ? 'rel:' . strtolower( $tag[1] ) : 'meta:' . strtolower( (string) ( $tag[2] ?? '' ) );
	}
}
