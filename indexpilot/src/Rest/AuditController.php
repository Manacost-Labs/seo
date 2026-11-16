<?php
/**
 * REST-маршрут аудита.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Audit\Runner;

/**
 * Один запрос проверяет одну пачку записей. Страница аудита повторяет запрос до конца.
 */
final class AuditController {

	/**
	 * Конструктор.
	 *
	 * @param Runner $runner Аудит записей.
	 */
	public function __construct( private Runner $runner ) {
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function register(): void {
		register_rest_route(
			'indexpilot/v1',
			'/audit/run',
			array(
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $request ) => rest_ensure_response( $this->runner->run_batch( (bool) $request['restart'] ) ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'restart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}
}
