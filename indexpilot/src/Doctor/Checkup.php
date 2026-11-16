<?php
/**
 * Замер нагрузки, которую плагин добавляет на страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Doctor;

/**
 * Открывает страницы сайта внутренним запросом с подписанным одноразовым параметром и читает сводку,
 * которую на этой странице сохранил Probe. Так измеряется настоящая страница, а не её подобие.
 */
final class Checkup {

	private const TIMEOUT = 60;

	/**
	 * Страницы для замера: запись, рубрика и экран админки.
	 *
	 * @return array<string, array{label: string, url: string, limited: bool}> limited — действует ли предел запросов.
	 */
	public function targets(): array {
		$targets = array();

		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);

		if ( isset( $posts[0] ) ) {
			$targets['post'] = array(
				'label'   => __( 'Запись', 'indexpilot' ),
				'url'     => (string) get_permalink( $posts[0] ),
				'limited' => true,
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 1,
			)
		);
		$link  = is_array( $terms ) && isset( $terms[0] ) ? get_term_link( $terms[0] ) : '';

		if ( is_string( $link ) && '' !== $link ) {
			$targets['category'] = array(
				'label'   => __( 'Рубрика', 'indexpilot' ),
				'url'     => $link,
				'limited' => true,
			);
		}

		$targets['admin'] = array(
			'label'   => __( 'Админка', 'indexpilot' ),
			'url'     => admin_url( 'index.php' ),
			'limited' => false,
		);

		return $targets;
	}

	/**
	 * Замеряет страницу сайта так, как её видит посетитель.
	 *
	 * @param string                $url     Адрес страницы этого сайта.
	 * @param array<string, string> $cookies Куки запроса.
	 * @return array<string, mixed> Сводка QueryLog с полями url, status и page_ms либо поле error с причиной неудачи.
	 */
	public function page( string $url, array $cookies = array() ): array {
		if ( ! str_starts_with( $url, home_url( '/' ) ) && ! str_starts_with( $url, admin_url() ) ) {
			return array(
				'url'   => $url,
				'error' => __( 'Замеряются только страницы этого сайта.', 'indexpilot' ),
			);
		}

		$id = bin2hex( random_bytes( 8 ) );

		Probe::save( $id, 'pending' );

		$response = wp_remote_get(
			add_query_arg( Probe::PARAM, Token::issue( $id, time(), Probe::secret() ), $url ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'cookies'     => $cookies,
				// Сайт обращается к самому себе: сертификат не проверяется, как и в запросах WordPress к собственному wp-cron.php.
				'sslverify'   => false,
			)
		);

		$slot = $this->read_slot();

		delete_option( Probe::OPTION );

		if ( is_wp_error( $response ) ) {
			return array(
				'url'   => $url,
				/* translators: %s: текст ошибки. */
				'error' => sprintf( __( 'Сайт не смог открыть собственную страницу: %s', 'indexpilot' ), $response->get_error_message() ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$result = is_array( $slot ) && hash_equals( $id, (string) ( $slot['id'] ?? '' ) ) && 'done' === ( $slot['state'] ?? '' ) && is_array( $slot['result'] ?? null ) ? $slot['result'] : null;

		if ( null === $result ) {
			return array(
				'url'    => $url,
				'status' => $status,
				'error'  => $status >= 300 && $status < 400
					? __( 'Страница перенаправляет на другой адрес: замерять нечего.', 'indexpilot' )
					: __( 'Страница открылась, но замер на ней не включился: возможно, её отдал кэш или запуск замера не подключён в плагине.', 'indexpilot' ),
			);
		}

		if ( 'savequeries_off' === ( $result['error'] ?? '' ) ) {
			return array(
				'url'    => $url,
				'status' => $status,
				'error'  => __( 'В wp-config.php константа SAVEQUERIES выключена явно: уберите её на время замера.', 'indexpilot' ),
			);
		}

		return array(
			'url'    => $url,
			'status' => $status,
		) + $result;
	}

	/**
	 * Замеряет экран админки от имени пользователя. Сеанс создаётся на время запроса и сразу закрывается.
	 *
	 * @param string $url     Адрес экрана админки.
	 * @param int    $user_id ID пользователя с доступом в админку.
	 * @return array<string, mixed>
	 */
	public function admin_page( string $url, int $user_id ): array {
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return array(
				'url'   => $url,
				'error' => __( 'Не найден пользователь, от имени которого можно открыть админку.', 'indexpilot' ),
			);
		}

		$expires  = time() + 5 * MINUTE_IN_SECONDS;
		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$session  = $sessions->create( $expires );
		$secure   = 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME );

		try {
			return $this->page(
				$url,
				array(
					( $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE ) => wp_generate_auth_cookie( $user_id, $expires, $secure ? 'secure_auth' : 'auth', $session ),
					LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $session ),
				)
			);
		} finally {
			$sessions->destroy( $session );
		}
	}

	/**
	 * Пользователь для замера админки: текущий, если он может в неё войти, иначе первый администратор.
	 */
	public function admin_user(): int {
		if ( current_user_can( 'edit_posts' ) ) {
			return get_current_user_id();
		}

		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * Читает состояние замера прямо из базы: страница записала его в другом запросе,
	 * и кэш опций этого запроса о новой записи не знает.
	 *
	 * @return mixed
	 */
	private function read_slot() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- чтение в обход кэша опций, см. описание метода.
		return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Probe::OPTION ) ) );
	}
}
