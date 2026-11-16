<?php
/**
 * Данные встроенных роликов для разметки и карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

/**
 * Собирает запись о каждом ролике из найденного в тексте (VideoDetector), ответа oEmbed и данных самой записи.
 * Ответ сети нужен только для названия и превью ролика YouTube; дата загрузки и описание берутся из записи.
 * Класс не зависит от WordPress: сеть и мету обслуживает VideoMeta.
 */
final class VideoData {

	/**
	 * Мета записи: список роликов, собранный при сохранении.
	 */
	public const META = '_indexpilot_videos';

	/**
	 * Площадки с открытым oEmbed без ключа.
	 */
	private const OEMBED = array( 'youtube' );

	/**
	 * Наибольшая длина описания в карте сайта Google.
	 */
	private const MAX_DESCRIPTION = 2048;

	/**
	 * Ключ ролика: площадка и идентификатор.
	 *
	 * @param array<string, mixed> $video Ролик.
	 */
	public static function key( array $video ): string {
		return (string) ( $video['provider'] ?? '' ) . ':' . (string) ( $video['id'] ?? '' );
	}

	/**
	 * Нужно ли спросить у площадки название ролика: площадка отвечает без ключа, а ответа ещё нет.
	 *
	 * @param array<string, mixed>                $video Ролик из VideoDetector.
	 * @param array<string, array<string, string>> $known Уже полученные ответы: ключ ролика => title, thumbnail.
	 */
	public static function needs_fetch( array $video, array $known ): bool {
		return in_array( (string) ( $video['provider'] ?? '' ), self::OEMBED, true ) && ! isset( $known[ self::key( $video ) ] );
	}

	/**
	 * Адрес запроса oEmbed; пусто — площадка его не даёт.
	 *
	 * @param array<string, mixed> $video Ролик из VideoDetector.
	 */
	public static function oembed_url( array $video ): string {
		return 'youtube' === ( $video['provider'] ?? '' ) ? 'https://www.youtube.com/oembed?url=' . rawurlencode( (string) $video['url'] ) . '&format=json' : '';
	}

	/**
	 * Разбирает ответ oEmbed; null — ответ неполный или не JSON.
	 *
	 * @param string $json Тело ответа.
	 * @return array{title: string, thumbnail: string}|null
	 */
	public static function parse_oembed( string $json ): ?array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
			return null;
		}

		return array(
			'title'     => trim( (string) $data['title'] ),
			'thumbnail' => trim( (string) ( $data['thumbnail_url'] ?? '' ) ),
		);
	}

	/**
	 * Собирает записи о роликах.
	 *
	 * @param list<array<string, string>>          $detected Ролики из VideoDetector.
	 * @param array<string, array<string, string>> $known    Ответы oEmbed: ключ ролика => title, thumbnail.
	 * @param array<string, string>                $post     Данные записи: title, description, date (ISO 8601), thumbnail.
	 * @return list<array{provider: string, id: string, url: string, embed_url: string, thumbnail: string, title: string, description: string, upload_date: string, fetched: bool}>
	 */
	public static function build( array $detected, array $known, array $post ): array {
		$videos = array();

		foreach ( $detected as $video ) {
			$answer = $known[ self::key( $video ) ] ?? array();
			$title  = trim( (string) ( $answer['title'] ?? '' ) );

			if ( '' === $title ) {
				$title = trim( (string) ( $post['title'] ?? '' ) );
			}

			$thumbnail = (string) ( $video['thumbnail'] ?? '' );
			foreach ( array( (string) ( $answer['thumbnail'] ?? '' ), (string) ( $post['thumbnail'] ?? '' ) ) as $fallback ) {
				$thumbnail = '' !== $thumbnail ? $thumbnail : $fallback;
			}

			$description = trim( (string) ( $post['description'] ?? '' ) );

			$videos[] = array(
				'provider'    => (string) ( $video['provider'] ?? '' ),
				'id'          => (string) ( $video['id'] ?? '' ),
				'url'         => (string) ( $video['url'] ?? '' ),
				'embed_url'   => (string) ( $video['embed_url'] ?? '' ),
				'thumbnail'   => $thumbnail,
				'title'       => $title,
				'description' => mb_substr( '' !== $description ? $description : $title, 0, self::MAX_DESCRIPTION ),
				'upload_date' => (string) ( $post['date'] ?? '' ),
				'fetched'     => '' !== trim( (string) ( $answer['title'] ?? '' ) ),
			);
		}

		return $videos;
	}

	/**
	 * Ответы oEmbed, уже сохранённые в мете записи: ключ ролика => title, thumbnail.
	 *
	 * @param mixed $stored Значение меты.
	 * @return array<string, array{title: string, thumbnail: string}>
	 */
	public static function known( $stored ): array {
		$known = array();

		foreach ( self::stored( $stored ) as $video ) {
			if ( $video['fetched'] ) {
				$known[ self::key( $video ) ] = array(
					'title'     => $video['title'],
					'thumbnail' => $video['thumbnail'],
				);
			}
		}

		return $known;
	}

	/**
	 * Ролики из меты записи с проверкой типов.
	 *
	 * @param mixed $stored Значение меты.
	 * @return list<array{provider: string, id: string, url: string, embed_url: string, thumbnail: string, title: string, description: string, upload_date: string, fetched: bool}>
	 */
	public static function stored( $stored ): array {
		$videos = array();

		foreach ( is_array( $stored ) ? $stored : array() as $video ) {
			if ( ! is_array( $video ) ) {
				continue;
			}

			$clean = array();
			foreach ( array( 'provider', 'id', 'url', 'embed_url', 'thumbnail', 'title', 'description', 'upload_date' ) as $field ) {
				$clean[ $field ] = (string) ( $video[ $field ] ?? '' );
			}
			$clean['fetched'] = ! empty( $video['fetched'] );

			$videos[] = $clean;
		}

		return $videos;
	}

	/**
	 * Есть ли всё, что Google требует от VideoObject: название, превью и дата загрузки, а также адрес плеера.
	 *
	 * @param array<string, mixed> $video Ролик.
	 */
	public static function is_complete( array $video ): bool {
		foreach ( array( 'title', 'thumbnail', 'upload_date', 'embed_url' ) as $field ) {
			if ( '' === trim( (string) ( $video[ $field ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Узел VideoObject.
	 *
	 * @param array<string, mixed> $video Ролик.
	 * @param string               $id    Идентификатор узла.
	 * @return array<string, mixed>
	 */
	public static function node( array $video, string $id ): array {
		$node = array(
			'@type'        => 'VideoObject',
			'@id'          => $id,
			'name'         => (string) $video['title'],
			'description'  => (string) ( $video['description'] ?? '' ),
			'thumbnailUrl' => array( (string) $video['thumbnail'] ),
			'uploadDate'   => (string) $video['upload_date'],
			'embedUrl'     => (string) $video['embed_url'],
		);

		// Страница ролика на YouTube — его постоянный адрес.
		if ( 'youtube' === ( $video['provider'] ?? '' ) ) {
			$node['contentUrl'] = (string) $video['url'];
		}

		return $node;
	}
}
