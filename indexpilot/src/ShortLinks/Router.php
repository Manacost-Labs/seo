<?php
/**
 * Перенаправление по коротким ссылкам.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\ShortLinks;

/**
 * Обслуживает адреса вида /go/КОД: находит цель и перенаправляет на неё.
 * Сами короткие адреса в поиск попадать не должны — ответ помечается как неиндексируемый.
 */
final class Router {

	/**
	 * Конструктор.
	 *
	 * @param Service $service Правила коротких ссылок.
	 * @param string  $prefix  Префикс короткого адреса.
	 */
	public function __construct( private Service $service, private string $prefix ) {
	}

	/**
	 * Код ссылки из адреса запроса; пустая строка — это не короткая ссылка.
	 *
	 * @param string $request_uri Адрес запроса с путём и параметрами.
	 * @param string $home_path   Путь, по которому установлен сайт: «/» или «/blog/».
	 * @param string $prefix      Префикс короткого адреса.
	 */
	public static function slug_from_path( string $request_uri, string $home_path, string $prefix ): string {
		$path = (string) strtok( $request_uri, '?' );
		$base = '/' . trim( trim( $home_path, '/' ) . '/' . trim( $prefix, '/' ), '/' ) . '/';

		if ( '' === trim( $prefix, '/' ) || ! str_starts_with( $path, $base ) ) {
			return '';
		}

		$slug = Slug::normalize( trim( substr( $path, strlen( $base ) ), '/' ) );

		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug ) ? $slug : '';
	}

	/**
	 * Короткий адрес для кода.
	 *
	 * @param string $slug Код.
	 */
	public function url( string $slug ): string {
		return home_url( '/' . trim( $this->prefix, '/' ) . '/' . $slug );
	}

	/**
	 * Перенаправляет, если запрошена короткая ссылка. Вызывается на хуке parse_request.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- из адреса берётся только код, проверенный шаблоном.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$slug = self::slug_from_path( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $this->prefix );

		if ( '' === $slug ) {
			return;
		}

		$link = $this->service->resolve( $slug );

		if ( null === $link ) {
			return;
		}

			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$this->service->hit( $slug, $user_agent, $referrer );

		header( 'X-Robots-Tag: noindex, nofollow' );

		// Временное перенаправление не должно запоминаться браузером: иначе повторные переходы не будут засчитаны.
		if ( 301 !== $link['code'] ) {
			nocache_headers();
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- цель задаёт редактор сайта, она может вести на другой домен (Twitch, YouTube); адрес проверен при создании ссылки.
		wp_redirect( $link['target'], $link['code'], 'IndexPilot' );
		exit;
	}
}
