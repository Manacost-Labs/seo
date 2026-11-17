<?php
/**
 * Объединение директив IndexPilot и фильтра WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Robots;

/** Сохраняет запреты и выбирает более строгое ограничение предпросмотра. */
final class Directives {

	/**
	 * @param array<string, bool|string> $existing Директивы WordPress и других модулей.
	 * @param list<string>               $requested Директивы IndexPilot.
	 * @return array<string, bool|string>
	 */
	public static function merge( array $existing, array $requested ): array {
		$output = array();
		foreach ( $requested as $directive ) {
			$parts               = explode( ':', $directive, 2 );
			$output[ $parts[0] ] = $parts[1] ?? true;
		}

		foreach ( $existing as $key => $value ) {
			if ( false === $value || '' === $value ) {
				continue;
			}
			if ( 'max-image-preview' === $key ) {
				$order = array(
					'none'     => 0,
					'standard' => 1,
					'large'    => 2,
				);
				if ( isset( $output[ $key ], $order[ $output[ $key ] ], $order[ $value ] ) && $order[ $output[ $key ] ] <= $order[ $value ] ) {
					continue;
				}
				// WordPress добавляет large по умолчанию даже на закрытых страницах.
				if ( ! isset( $output[ $key ] ) && 'large' === $value && ! empty( $output['noindex'] ) ) {
					continue;
				}
			}
			if ( in_array( $key, array( 'max-snippet', 'max-video-preview' ), true ) && isset( $output[ $key ] ) ) {
				if ( '-1' === (string) $value || ( (int) $output[ $key ] >= 0 && (int) $output[ $key ] <= (int) $value ) ) {
					continue;
				}
			}
			$output[ $key ] = $value;
		}

		if ( ! empty( $output['none'] ) || ! empty( $output['noindex'] ) ) {
			unset( $output['index'], $output['all'] );
		}
		if ( ! empty( $output['none'] ) || ! empty( $output['nofollow'] ) ) {
			unset( $output['follow'], $output['all'] );
		}
		if ( ! empty( $output['nosnippet'] ) ) {
			unset( $output['max-snippet'], $output['max-video-preview'] );
		}
		return $output;
	}
}
