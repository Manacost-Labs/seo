<?php
/**
 * Картинка для поиска: версии обложки в разных пропорциях.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Meta;

/**
 * Google показывает картинку статьи в выдаче квадратом и сам обрезает широкую обложку. В разметке статьи можно дать
 * ему готовые версии 1:1, 4:3 и 16:9 — тогда он берёт подходящую, а не вырезает середину обложки.
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
