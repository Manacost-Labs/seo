<?php
/**
 * Адрес в виде, удобном для чтения в админке.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

/**
 * Русские буквы вместо %D0%BC… — только для показа человеку. Сохраняется и отправляется всегда исходный адрес.
 */
final class ReadableUrl {

	/**
	 * Раскодированный адрес; если после раскодирования получается не UTF-8 — исходный.
	 *
	 * @param string $url Адрес.
	 */
	public static function of( string $url ): string {
		$decoded = rawurldecode( $url );

		return mb_check_encoding( $decoded, 'UTF-8' ) ? $decoded : $url;
	}
}
