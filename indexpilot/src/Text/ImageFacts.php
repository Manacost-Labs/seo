<?php
/**
 * Чистый анализ изображений.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Text;

/** Извлекает изображения и их проблемы. */
final class ImageFacts {
	public const NO_ALT          = 'no_alt';
	public const ALT_IS_FILENAME = 'alt_is_filename';
	public const DUPLICATE_ALT   = 'duplicate_alt';
	public const NO_SIZE         = 'no_size';
	/**
	 * Читает изображения по порядку.
	 * @param string $html Фрагмент HTML.
	 * @return array
	 */
	public static function extract( string $html ): array {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$result = array();
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$classes = preg_split( '/\s+/', trim( $img->getAttribute( 'class' ) ) );
			preg_match( '/(?:^|\s)wp-image-(\d+)(?=\s|$)/', $img->getAttribute( 'class' ), $match );
			$src      = $img->getAttribute( 'src' );
			$result[] = array(
				'src'           => $src,
				'alt'           => $img->getAttribute( 'alt' ),
				'alt_present'   => $img->hasAttribute( 'alt' ),
				'width'         => is_numeric( $img->getAttribute( 'width' ) ) ? (int) $img->getAttribute( 'width' ) : 0,
				'height'        => is_numeric( $img->getAttribute( 'height' ) ) ? (int) $img->getAttribute( 'height' ) : 0,
				'decorative'    => in_array( 'is-decorative', $classes, true ) || 'presentation' === strtolower( $img->getAttribute( 'role' ) ) || 'true' === strtolower( $img->getAttribute( 'aria-hidden' ) ),
				'filename'      => basename( explode( '?', $src, 2 )[0] ),
				'attachment_id' => (int) ( $match[1] ?? 0 ),
			);
		}
		return $result;
	}
	/**
	 * Находит проблемы изображений.
	 * @param array $images Изображения.
	 * @return array
	 */
	public static function issues( array $images ): array {
		$result = array();
		$seen   = array();
		foreach ( $images as $index => $img ) {
			$alt    = mb_strtolower( trim( $img['alt'] ), 'UTF-8' );
			$issues = array();
			if ( ! $img['decorative'] ) {
				if ( '' === $alt ) {
					$issues[] = self::NO_ALT; } else {
							$normalize = static fn( string $value ): string => preg_replace( '/[\s_-]+/u', ' ', mb_strtolower( $value, 'UTF-8' ) );
					if ( in_array( $normalize( $alt ), array( $normalize( $img['filename'] ), $normalize( pathinfo( $img['filename'], PATHINFO_FILENAME ) ) ), true ) || preg_match( '/^(img|image|dsc|dscn|pxl|photo|screenshot|снимок экрана|скриншот|изображение)[\s_\-]*\d/iu', $alt ) ) {
						$issues[] = self::ALT_IS_FILENAME; }
					if ( isset( $seen[ $alt ] ) ) {
						$issues[] = self::DUPLICATE_ALT; }
					$seen[ $alt ] = true;
					}
			}
			if ( 0 === $img['width'] || 0 === $img['height'] ) {
				$issues[] = self::NO_SIZE; }
			foreach ( $issues as $issue ) {
				$result[] = array(
					'index' => $index,
					'src'   => $img['src'],
					'issue' => $issue,
				); }
		}
		return $result;
	}
}
