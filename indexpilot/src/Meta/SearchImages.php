<?php
/**
 * Картинка для поиска: версии обложки в разных пропорциях.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Meta;

/**
 * Основная картинка записи и подготовленные редактором варианты для разметки статьи.
 */
final class SearchImages {

	/**
	 * Пропорции: ключ => ширина, высота, ключ меты.
	 */
	public const RATIOS = array(
		'1x1'  => array( 1, 1, MetaKeys::IMAGE_1X1 ),
		'4x3'  => array( 4, 3, MetaKeys::IMAGE_4X3 ),
		'16x9' => array( 16, 9, MetaKeys::IMAGE_16X9 ),
	);

	/**
	 * Наименьшая ширина, которую советует Google для картинок статьи.
	 */
	public const MIN_WIDTH = 1200;

	/**
	 * Насколько пропорция может отличаться от нужной (доля), чтобы Google не пришлось обрезать картинку.
	 */
	public const TOLERANCE = 0.03;

	public const OK    = '';
	public const SMALL = 'small';
	public const SHAPE = 'shape';

	/**
	 * Основное изображение записи для разметки и предпросмотра.
	 *
	 * @param int $post_id ID записи.
	 * @param int $default_id Изображение из настроек сайта.
	 * @return array{source: string, url: string, width: int, height: int}|null
	 */
	public static function primary( int $post_id, int $default_id ): ?array {
		$candidates = array(
			'social'    => (int) get_post_meta( $post_id, MetaKeys::OG_IMAGE_ID, true ),
			'thumbnail' => (int) get_post_thumbnail_id( $post_id ),
			'default'   => $default_id,
		);
		foreach ( $candidates as $source => $id ) {
			$image = $id > 0 ? wp_get_attachment_image_src( $id, 'full' ) : false;
			if ( is_array( $image ) ) {
				return array(
					'source' => $source,
					'url'    => (string) $image[0],
					'width'  => (int) $image[1],
					'height' => (int) $image[2],
				);
			}
		}
		return null;
	}

	/**
	 * Что не так с картинкой для этой пропорции: SHAPE — другая пропорция, SMALL — уже 1200 пикселей, OK — подходит.
	 *
	 * @param int    $width  Ширина.
	 * @param int    $height Высота.
	 * @param string $ratio  Ключ пропорции, например «1x1».
	 */
	public static function problem( int $width, int $height, string $ratio ): string {
		if ( $width <= 0 || $height <= 0 || ! isset( self::RATIOS[ $ratio ] ) ) {
			return self::OK;
		}

		list( $w, $h ) = self::RATIOS[ $ratio ];

		if ( abs( ( $width / $height ) / ( $w / $h ) - 1 ) > self::TOLERANCE ) {
			return self::SHAPE;
		}

		return $width < self::MIN_WIDTH ? self::SMALL : self::OK;
	}

	/**
	 * Выбранные для записи картинки: ключ пропорции => ID вложения. Пустые не попадают.
	 *
	 * @param int $post_id ID записи.
	 * @return array<string, int>
	 */
	public static function ids( int $post_id ): array {
		$ids = array();

		foreach ( self::RATIOS as $ratio => $spec ) {
			$id = (int) get_post_meta( $post_id, $spec[2], true );

			if ( $id > 0 ) {
				$ids[ $ratio ] = $id;
			}
		}

		return $ids;
	}
}
