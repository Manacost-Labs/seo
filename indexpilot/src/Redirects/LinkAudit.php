<?php
/**
 * Ссылки в тексте, ведущие на адреса с редиректом.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Ссылка на адрес с редиректом заставляет посетителя и поискового робота делать лишний переход:
 * её стоит заменить ссылкой на конечный адрес.
 */
final class LinkAudit {

	/**
	 * Находит в HTML ссылки на адреса этого сайта, с которых стоит редирект.
	 *
	 * @param string        $html     HTML текста.
	 * @param ChainResolver $resolver Редиректы сайта.
	 * @return list<array{href: string, final: string}> Каждая ссылка — один раз, в порядке появления.
	 */
	public static function find( string $html, ChainResolver $resolver ): array {
		if ( ! preg_match_all( '#<a\b[^>]*\bhref=(["\'])(.*?)\1#isu', $html, $matches ) ) {
			return array();
		}

		$found = array();

		foreach ( $matches[2] as $href ) {
			$href = html_entity_decode( trim( $href ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( '' === $href || isset( $found[ $href ] ) || ! $resolver->is_local( $href ) ) {
				continue;
			}

			$result = $resolver->resolve( $href );

			if ( array() !== $result['hops'] ) {
				$found[ $href ] = array(
					'href'  => $href,
					'final' => $result['final'],
				);
			}
		}

		return array_values( $found );
	}
}
