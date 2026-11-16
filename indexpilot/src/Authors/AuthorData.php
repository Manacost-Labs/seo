<?php
/**
 * Данные профиля автора.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Authors;

/**
 * Разбор и проверка того, что автор указал о себе. Не зависит от WordPress.
 */
final class AuthorData {

	public const MAX_LINKS = 12;

	/**
	 * Известные площадки: часть домена => название.
	 */
	private const PLATFORMS = array(
		'twitch.tv'     => 'Twitch',
		'youtube.com'   => 'YouTube',
		'youtu.be'      => 'YouTube',
		't.me'          => 'Telegram',
		'telegram.me'   => 'Telegram',
		'vk.com'        => 'ВКонтакте',
		'vkvideo.ru'    => 'VK Видео',
		'x.com'         => 'X',
		'twitter.com'   => 'X',
		'discord.gg'    => 'Discord',
		'discord.com'   => 'Discord',
		'boosty.to'     => 'Boosty',
		'instagram.com' => 'Instagram',
		'tiktok.com'    => 'TikTok',
		'kick.com'      => 'Kick',
		'trovo.live'    => 'Trovo',
	);

	/**
	 * Ссылки на площадки автора: только полные адреса http(s), без повторов.
	 *
	 * @param mixed $input Текст (адреса через перевод строки, пробел или запятую) или массив адресов.
	 * @return list<string>
	 */
	public static function links( mixed $input ): array {
		$items = is_array( $input ) ? $input : preg_split( '/[\s,]+/u', (string) $input, -1, PREG_SPLIT_NO_EMPTY );
		$links = array();

		foreach ( (array) $items as $item ) {
			$item = is_scalar( $item ) ? trim( (string) $item ) : '';

			if ( 1 !== preg_match( '#^https?://[^\s/?\#<>"\']+\.[^\s<>"\']+$#iu', $item ) ) {
				continue;
			}

			$links[ $item ] = true;

			if ( count( $links ) >= self::MAX_LINKS ) {
				break;
			}
		}

		return array_map( 'strval', array_keys( $links ) );
	}

	/**
	 * Название площадки по адресу; для неизвестной — домен.
	 *
	 * @param string $url Адрес.
	 */
	public static function link_label( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress и проверяется модульными тестами.
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$host = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;

		foreach ( self::PLATFORMS as $domain => $label ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $label;
			}
		}

		return $host;
	}

	/**
	 * Заполнен ли профиль: есть рассказ о себе или хотя бы одна ссылка на площадку.
	 *
	 * @param array<string, mixed> $profile Профиль автора.
	 */
	public static function is_complete( array $profile ): bool {
		return '' !== trim( (string) ( $profile['description'] ?? '' ) ) || array() !== (array) ( $profile['same_as'] ?? array() );
	}
}
