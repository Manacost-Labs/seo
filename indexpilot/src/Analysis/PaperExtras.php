<?php
/**
 * Данные записи для проверок картинок и рекламных ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Promo\Destinations;
use IndexPilot\Text\ImageFacts;

/**
 * Отметка «В статье есть оплаченное размещение», свои хосты, вес картинок и размеры миниатюры.
 * Вес картинок читается из медиатеки только при анализе — на страницах сайта этот класс не используется.
 */
final class PaperExtras {

	/**
	 * Мета записи: отметка оплаченного размещения.
	 */
	public const SPONSORED = '_indexpilot_sponsored';

	/**
	 * Поле формы панели записи.
	 */
	public const FIELD = 'indexpilot_sponsored';

	/**
	 * Сколько картинок одной записи взвешивать: дальше — без проверки веса.
	 */
	private const MAX_IMAGES = 50;

	/**
	 * Отмечено ли оплаченное размещение.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function sponsored( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::SPONSORED, true );
	}

	/**
	 * Хосты своих площадок (страница «Переходы») и второго сайта из настроек.
	 *
	 * @param string $partner_url Адрес второго сайта.
	 * @return list<string>
	 */
	public static function own_hosts( string $partner_url ): array {
		$urls = array_column( ( new Destinations() )->all(), 'url' );

		if ( '' !== trim( $partner_url ) ) {
			$urls[] = $partner_url;
		}

		$hosts = array();
		foreach ( $urls as $url ) {
			$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Вес картинок медиатеки из текста: ID вложения => размер файла, который стоит в src (полный или уменьшенный).
	 *
	 * @param string $html HTML текста.
	 * @return array<int, int>
	 */
	public static function image_bytes( string $html ): array {
		$bytes = array();

		foreach ( array_slice( ImageFacts::extract( $html ), 0, self::MAX_IMAGES ) as $image ) {
			$id = (int) $image['attachment_id'];

			if ( $id <= 0 || isset( $bytes[ $id ] ) ) {
				continue;
			}

			$size = self::file_size( $id, (string) $image['filename'] );

			if ( $size > 0 ) {
				$bytes[ $id ] = $size;
			}
		}

		return $bytes;
	}

	/**
	 * Размеры миниатюры записи; пустой массив — миниатюры нет.
	 *
	 * @param int $post_id ID записи.
	 * @return array{width: int, height: int}|array{}
	 */
	public static function featured_image( int $post_id ): array {
		$id    = (int) get_post_thumbnail_id( $post_id );
		$image = $id > 0 ? wp_get_attachment_image_src( $id, 'full' ) : false;

		return is_array( $image )
			? array(
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
			)
			: array();
	}

	/**
	 * Вес файла вложения: уменьшенной копии с этим именем или полного файла.
	 *
	 * @param int    $id       ID вложения.
	 * @param string $filename Имя файла из src.
	 */
	private static function file_size( int $id, string $filename ): int {
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && (string) ( $size['file'] ?? '' ) === $filename && (int) ( $size['filesize'] ?? 0 ) > 0 ) {
				return (int) $size['filesize'];
			}
		}

		if ( (int) ( $meta['filesize'] ?? 0 ) > 0 ) {
			return (int) $meta['filesize'];
		}

		$file = get_attached_file( $id );

		return is_string( $file ) && '' !== $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
	}
}
