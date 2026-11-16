<?php
/**
 * Ролики записи: сбор данных при сохранении.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Text\Cleaner;

/**
 * При сохранении записи находит встроенные ролики и один раз спрашивает у YouTube их название (oEmbed, без ключа).
 * Результат хранится в мете записи: разметка и карта сайта читают его без запросов в сеть.
 * Если сеть не ответила, запись сохраняется как обычно, а название берётся из записи; при следующем сохранении
 * запрос повторится.
 */
final class VideoMeta {

	/**
	 * Сколько новых роликов спрашивать за одно сохранение.
	 */
	private const MAX_FETCH = 5;

	/**
	 * Сколько секунд ждать ответа площадки.
	 */
	private const TIMEOUT = 5;

	/**
	 * Подключает сбор при сохранении.
	 */
	public function register(): void {
		// Раньше пересчёта оценки (20) и аудита (25): мета записи к этому моменту уже сохранена.
		add_action( 'wp_after_insert_post', array( $this, 'update' ), 12, 2 );
	}

	/**
	 * Обновляет список роликов записи.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function update( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$post_id  = (int) $post_id;
		$detected = VideoDetector::detect( $post->post_content );
		$stored   = get_post_meta( $post_id, VideoData::META, true );

		if ( array() === $detected ) {
			if ( '' !== $stored ) {
				delete_post_meta( $post_id, VideoData::META );
			}

			return;
		}

		$known   = VideoData::known( $stored );
		$fetches = 0;

		foreach ( $detected as $video ) {
			if ( $fetches >= self::MAX_FETCH || ! VideoData::needs_fetch( $video, $known ) ) {
				continue;
			}

			++$fetches;
			$answer = $this->fetch( VideoData::oembed_url( $video ) );

			if ( null !== $answer ) {
				$known[ VideoData::key( $video ) ] = $answer;
			}
		}

		update_post_meta( $post_id, VideoData::META, VideoData::build( $detected, $known, $this->post_data( $post ) ) );
	}

	/**
	 * Запрос oEmbed; null — сеть не ответила или ответ неполный.
	 *
	 * @param string $url Адрес запроса.
	 * @return array{title: string, thumbnail: string}|null
	 */
	private function fetch( string $url ): ?array {
		if ( '' === $url ) {
			return null;
		}

		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return VideoData::parse_oembed( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Запасные данные из записи: название, описание, дата публикации и миниатюра.
	 *
	 * @param \WP_Post $post Запись.
	 * @return array{title: string, description: string, date: string, thumbnail: string}
	 */
	private function post_data( \WP_Post $post ): array {
		$description = trim( (string) get_post_meta( $post->ID, MetaKeys::ANSWER, true ) );

		if ( '' === $description ) {
			$description = trim( wp_strip_all_tags( $post->post_excerpt ) );
		}

		if ( '' === $description ) {
			$description = wp_trim_words( Cleaner::to_plain_text( strip_shortcodes( $post->post_content ), false ), 40, '…' );
		}

		return array(
			'title'       => wp_strip_all_tags( get_the_title( $post ) ),
			'description' => $description,
			'date'        => (string) get_post_time( 'c', true, $post ),
			'thumbnail'   => (string) get_the_post_thumbnail_url( $post, 'full' ),
		);
	}
}
