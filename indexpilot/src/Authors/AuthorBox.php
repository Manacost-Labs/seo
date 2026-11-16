<?php
/**
 * Блок «Об авторе».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Authors;

use IndexPilot\Settings\Settings;

/**
 * Показывает под статьёй, кто её написал: фото, имя со ссылкой на страницу автора, роль, рассказ о себе и площадки.
 * Читателю и поисковику видно, что за материалом стоит конкретный человек с опытом в игре.
 *
 * Вывод: шорткод [indexpilot_author], функция indexpilot_author_box() или автоматически под текстом (настройка).
 */
final class AuthorBox {

	/**
	 * Конструктор.
	 *
	 * @param AuthorProfile $profiles Профили авторов.
	 * @param Settings      $settings Настройки.
	 */
	public function __construct( private AuthorProfile $profiles, private Settings $settings ) {
	}

	/**
	 * Регистрирует шорткод и автоматическую вставку.
	 */
	public function register(): void {
		add_shortcode( 'indexpilot_author', fn(): string => $this->html() );

		if ( $this->settings->general( 'author_box_auto' ) ) {
			add_filter( 'the_content', array( $this, 'append' ), 30 );
		}
	}

	/**
	 * Добавляет блок под текстом записи, если для её типа выбрана разметка статьи.
	 *
	 * @param string $content Текст записи.
	 */
	public function append( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$type = (string) ( $this->settings->post_type( (string) get_post_type() )['schema_type'] ?? '' );

		// Если блок уже вставлен шорткодом, второй раз не добавляем.
		if ( 'WebPage' === $type || '' === $type || str_contains( (string) $content, 'indexpilot-author' ) ) {
			return $content;
		}

		return $content . $this->html();
	}

	/**
	 * HTML блока для автора записи.
	 *
	 * @param int $post_id ID записи; 0 — текущая запись.
	 */
	public function html( int $post_id = 0 ): string {
		$post = get_post( $post_id > 0 ? $post_id : null );

		if ( ! $post instanceof \WP_Post || (int) $post->post_author <= 0 || ! post_type_supports( $post->post_type, 'author' ) ) {
			return '';
		}

		$profile = $this->profiles->get( (int) $post->post_author );

		if ( '' === $profile['name'] ) {
			return '';
		}

		$photo = null !== $profile['image'] ? $profile['image']['url'] : (string) get_avatar_url( (int) $post->post_author, array( 'size' => 192 ) );
		$links = '';

		foreach ( $profile['same_as'] as $url ) {
			$links .= sprintf( '<li><a href="%s" rel="me noopener" target="_blank">%s</a></li>', esc_url( $url ), esc_html( AuthorData::link_label( $url ) ) );
		}

		return sprintf(
			'<aside class="indexpilot-author" aria-label="%1$s">%2$s<div class="indexpilot-author-body"><p class="indexpilot-author-name"><a href="%3$s" rel="author">%4$s</a>%5$s</p>%6$s%7$s</div></aside>',
			esc_attr__( 'Об авторе', 'indexpilot' ),
			'' !== $photo ? sprintf( '<img class="indexpilot-author-photo" src="%s" alt="%s" width="96" height="96" loading="lazy">', esc_url( $photo ), esc_attr( $profile['name'] ) ) : '',
			esc_url( $profile['url'] ),
			esc_html( $profile['name'] ),
			'' !== $profile['role'] ? ' <span class="indexpilot-author-role">' . esc_html( $profile['role'] ) . '</span>' : '',
			'' !== $profile['description'] ? '<p class="indexpilot-author-bio">' . esc_html( $profile['description'] ) . '</p>' : '',
			'' !== $links ? '<ul class="indexpilot-author-links">' . $links . '</ul>' : ''
		);
	}
}
