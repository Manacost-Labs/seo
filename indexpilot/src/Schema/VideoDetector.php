<?php
/**
 * Чистое обнаружение встроенного видео.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Schema;

/** Распознаёт поддерживаемые видеоплощадки. */
final class VideoDetector {
	/**
	 * Возвращает уникальные видео по порядку.
	 * @param string $html Содержимое записи.
	 * @return array
	 */
	public static function detect( string $html ): array {
		$pattern = '~<iframe\b[^>]*\bsrc\s*=\s*(["\x27])(?<frame>.*?)\1[^>]*>.*?</iframe\s*>|\[embed(?:\s[^\]]*)?\]\s*(?<short>https?://[^\s<]+)\s*\[/embed\]|<p\b[^>]*>\s*(?<paragraph>https?://[^\s<]+)\s*</p\s*>|(?:^|\n)[\t ]*(?<bare>https?://[^\s<]+)[\t ]*(?=\r?$)~ims';
		preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER );
		$result = array();
		$seen   = array();
		foreach ( $matches as $match ) {
			$raw = '';
			foreach ( array( 'frame', 'short', 'paragraph', 'bare' ) as $field ) {
				if ( ! empty( $match[ $field ] ) ) {
					$raw = $match[ $field ];
					break; }
			}
			$url = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			// Чистый класс не зависит от WordPress.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$parts = parse_url( $url );
			$host  = strtolower( $parts['host'] ?? '' );
			$host  = preg_replace( '/^www\./', '', $host );
			$path  = $parts['path'] ?? '/';
			parse_str( $parts['query'] ?? '', $query );
			$provider  = '';
			$id        = '';
			$canonical = '';
			$embed     = '';
			$thumbnail = '';
			if ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ), true ) ) {
				$candidate = 'youtu.be' === $host ? substr( $path, 1 ) : ( '/watch' === $path ? ( $query['v'] ?? '' ) : ( preg_match( '~^/(?:embed|shorts|live)/([^/]+)/?$~', $path, $m ) ? $m[1] : '' ) );
				if ( is_string( $candidate ) && preg_match( '/^[A-Za-z0-9_-]{11}$/D', $candidate ) ) {
					$provider  = 'youtube';
					$id        = $candidate;
					$canonical = 'https://www.youtube.com/watch?v=' . $id;
					$embed     = 'https://www.youtube.com/embed/' . $id;
					$thumbnail = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'; }
			} elseif ( in_array( $host, array( 'vk.com', 'vkvideo.ru' ), true ) ) {
				if ( preg_match( '~^/video(-?\d+)_([0-9]+)/?$~', $path, $m ) ) {
					$id = $m[1] . '_' . $m[2]; } elseif ( '/video_ext.php' === $path && preg_match( '/^-?\d+$/D', (string) ( $query['oid'] ?? '' ) ) && preg_match( '/^\d+$/D', (string) ( $query['id'] ?? '' ) ) ) {
								$id = $query['oid'] . '_' . $query['id']; }
					if ( '' !== $id ) {
						$provider        = 'vk';
						$canonical       = 'https://vkvideo.ru/video' . $id;
						list($oid, $vid) = explode( '_', $id );
						$embed           = 'https://vk.com/video_ext.php?oid=' . $oid . '&id=' . $vid; }
			} elseif ( in_array( $host, array( 'player.twitch.tv', 'twitch.tv', 'clips.twitch.tv' ), true ) ) {
				if ( ( 'player.twitch.tv' === $host && preg_match( '/^v?(\d+)$/D', (string) ( $query['video'] ?? '' ), $m ) ) || ( 'twitch.tv' === $host && preg_match( '~^/videos/(\d+)/?$~', $path, $m ) ) ) {
					$provider  = 'twitch';
					$id        = $m[1];
					$canonical = 'https://www.twitch.tv/videos/' . $id;
					$embed     = 'https://player.twitch.tv/?video=' . $id; } else {
						$slug = 'clips.twitch.tv' === $host ? ( '/embed' === $path ? ( $query['clip'] ?? '' ) : trim( $path, '/' ) ) : ( preg_match( '~^/[^/]+/clip/([^/]+)/?$~', $path, $m ) ? $m[1] : '' );
					if ( is_string( $slug ) && preg_match( '/^[A-Za-z0-9_-]+$/D', $slug ) ) {
						$provider  = 'twitch_clip';
						$id        = $slug;
						$canonical = 'https://clips.twitch.tv/' . $id;
						$embed     = 'https://clips.twitch.tv/embed?clip=' . $id; }
					}
			}
			$key = $provider . ':' . $id;
			if ( '' !== $provider && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$result[]     = array(
					'provider'  => $provider,
					'id'        => $id,
					'url'       => $canonical,
					'embed_url' => $embed,
					'thumbnail' => $thumbnail,
				); }
		}
		return $result;
	}
}
