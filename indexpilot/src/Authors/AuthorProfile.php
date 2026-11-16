<?php
/**
 * Профиль автора из WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Authors;

/**
 * Собирает сведения об авторе: имя, страницу на сайте, рассказ о себе, роль, фото и ссылки на его площадки.
 * Одни и те же данные идут в разметку, в блок «Об авторе» и в проверку анализа.
 */
final class AuthorProfile {

	public const ROLE     = '_indexpilot_author_role';
	public const LINKS    = '_indexpilot_author_links';
	public const IMAGE_ID = '_indexpilot_author_image_id';

	/**
	 * Профиль автора.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array{name: string, url: string, description: string, role: string, image: array{url: string, width: int, height: int}|null, same_as: list<string>}
	 */
	public function get( int $user_id ): array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof \WP_User ) {
			return array(
				'name'        => '',
				'url'         => '',
				'description' => '',
				'role'        => '',
				'image'       => null,
				'same_as'     => array(),
			);
		}

		$image_id = (int) get_user_meta( $user_id, self::IMAGE_ID, true );
		$image    = $image_id > 0 ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
		$links    = AuthorData::links( get_user_meta( $user_id, self::LINKS, true ) );

		// Поле «Сайт» из штатного профиля тоже считается площадкой автора — если это не адрес самого сайта.
		$own_site = wp_parse_url( (string) $user->user_url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' !== (string) $user->user_url && ! $own_site ) {
			$links = AuthorData::links( array_merge( $links, array( (string) $user->user_url ) ) );
		}

		return array(
			'name'        => (string) $user->display_name,
			'url'         => get_author_posts_url( $user_id ),
			'description' => trim( wp_strip_all_tags( (string) $user->description ) ),
			'role'        => trim( (string) get_user_meta( $user_id, self::ROLE, true ) ),
			'image'       => is_array( $image )
				? array(
					'url'    => (string) $image[0],
					'width'  => (int) $image[1],
					'height' => (int) $image[2],
				)
				: null,
			'same_as'     => $links,
		);
	}

	/**
	 * Число опубликованных записей автора во всех типах записей, у которых есть страницы на сайте.
	 *
	 * @param int $user_id ID пользователя.
	 */
	public static function post_count( int $user_id ): int {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type && is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}

		return (int) count_user_posts( $user_id, $types, true );
	}
}
