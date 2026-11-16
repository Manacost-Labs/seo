<?php
/**
 * Маршруты REST коротких ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\ShortLinks\Router;
use IndexPilot\ShortLinks\Service;
use IndexPilot\ShortLinks\Store;

/**
 * GET /indexpilot/v1/shortlinks — список с поиском; POST — создание ссылки.
 */
final class ShortLinksController {

	public function __construct( private Service $service, private Store $store, private Router $router ) {
	}

	/**
	 * Регистрирует маршруты.
	 */
	public function register(): void {
		register_rest_route(
			'indexpilot/v1',
			'/shortlinks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list' ),
					'permission_callback' => static fn(): bool => current_user_can( 'edit_others_posts' ),
					'args'                => array(
						'search' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => static fn(): bool => current_user_can( 'edit_others_posts' ),
					'args'                => array(
						'target'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'slug'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'code'     => array(
							'type'    => 'integer',
							'default' => 302,
						),
						'campaign' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Список ссылок.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response
	 */
	public function list( \WP_REST_Request $request ) {
		$search = (string) $request->get_param( 'search' );
		$links  = $this->store->all( 100, 0, null, '' === $search ? null : $search );

		return rest_ensure_response(
			array_map(
				fn( array $link ): array => array(
					'id'     => (int) $link['id'],
					'slug'   => (string) $link['slug'],
					'url'    => $this->router->url( (string) $link['slug'] ),
					'target' => (string) $link['target'],
					'code'   => (int) $link['code'],
					'clicks' => (int) $link['clicks'],
				),
				$links
			)
		);
	}

	/**
	 * Создаёт ссылку.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$result = $this->service->acting_as( get_current_user_id(), current_user_can( 'manage_options' ) )->create(
			esc_url_raw( trim( (string) $request->get_param( 'target' ) ) ),
			sanitize_text_field( (string) $request->get_param( 'slug' ) ),
			(int) $request->get_param( 'code' ),
			array(),
			(string) $request->get_param( 'campaign' )
		);

		if ( is_string( $result ) ) {
			return new \WP_Error( 'indexpilot_shortlink_invalid', $result, array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( $result + array( 'url' => $this->router->url( (string) $result['slug'] ) ), 201 );
	}
}
