<?php
/**
 * Срабатывание своих редиректов и журнал 404 на страницах сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Ищет правило на самом раннем хуке (init с приоритетом 0 — раньше плагина Redirection и до запроса записи).
 * Индекс правил лежит в опции с автозагрузкой, поэтому на обычной странице запросов к базе нет;
 * правило читается из таблицы только при совпадении. Журнал 404 пишется только на ответе 404.
 */
final class Handler {

	/**
	 * Код ответа «удалена», если сработало такое правило.
	 *
	 * @var int
	 */
	private int $gone = 0;

	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'handle' ), 0 );
		add_action( 'template_redirect', array( $this, 'log_not_found' ), 1000 );
	}

	/**
	 * Перенаправляет запрос, если для его адреса есть правило.
	 */
	public function handle(): void {
		if ( ! $this->is_page_request() ) {
			return;
		}

		$index = WpStore::index();

		if ( Matcher::is_empty( $index ) ) {
			return;
		}

		$store = new WpStore( $this->db );
		$match = ( new Matcher( $index, array( $store, 'find' ) ) )->match( $this->request_uri() );

		if ( null === $match ) {
			return;
		}

		$store->hit( $match['id'] );

		if ( Matcher::is_gone( $match['code'] ) ) {
			$this->gone = $match['code'];
			add_action( 'wp', array( $this, 'send_gone' ), 0 );

			return;
		}

		$target = $match['target'];

		// Путь на своём сайте отдаётся полным адресом: так заголовок Location понятен любому клиенту.
		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			$target = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . $target;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- цель задаёт администратор, она может вести на другой сайт.
		wp_redirect( $target, $match['code'], 'IndexPilot' );
		exit;
	}

	/**
	 * Отдаёт страницу 404 темы с кодом 410 (или 451): поисковик убирает адрес из индекса быстрее, чем при 404.
	 */
	public function send_gone(): void {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		// Иначе WordPress попробует угадать похожий адрес и перенаправит туда.
		remove_action( 'template_redirect', 'redirect_canonical' );
		status_header( $this->gone );
		nocache_headers();
	}

	/**
	 * Записывает адрес в журнал 404. Работает только на ответе 404, обычные страницы не трогает.
	 */
	public function log_not_found(): void {
		if ( ! is_404() || $this->gone > 0 || ! Schema::installed() || ! $this->is_page_request() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- адрес очищается и проверяется журналом, в базу пишется через подготовленный запрос.
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		( new NotFoundLog( new WpNotFoundStore( $this->db ) ) )->record( $this->request_uri(), $referrer );
	}

	/**
	 * Обычный запрос страницы сайта: не админка, не фоновые задачи, только GET и HEAD.
	 */
	private function is_page_request(): bool {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Адрес запроса.
	 */
	private function request_uri(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- адрес только сравнивается с правилами и проверяется журналом.
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	}
}
