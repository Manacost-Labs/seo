<?php
/**
 * REST-маршрут проверки целей редиректов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Redirects\RedirectService;

/**
 * Один запрос проверяет несколько адресов. Страница отчёта повторяет запрос, пока адреса не закончатся.
 */
final class RedirectsController {

	/**
	 * Конструктор.
	 *
	 * @param RedirectService $service Редиректы сайта.
	 */
	public function __construct( private RedirectService $service ) {
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function register(): void {
		register_rest_route(
			'indexpilot/v1',
			'/redirects/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'check' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Проверяет очередную пачку адресов.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check() {
		if ( ! $this->service->available() ) {
			return new \WP_Error( 'indexpilot_redirects_unavailable', __( 'Плагин Redirection не установлен.', 'indexpilot' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $this->service->check_batch() );
	}
}
