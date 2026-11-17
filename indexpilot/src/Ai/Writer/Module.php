<?php
/**
 * ИИ-помощник: маршрут для кнопки в панели записи и экран подключения.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai\Writer;

use IndexPilot\Admin\AiWriterPage;
use IndexPilot\Settings\Settings;

/**
 * Подключает маршрут POST /indexpilot/v1/ai-fill и экран «ИИ-помощник». Запрос к модели идёт только по кнопке
 * в панели записи — сам плагин модель не вызывает.
 */
final class Module {

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );

		if ( is_admin() ) {
			add_action(
				'init',
				function (): void {
					( new AiWriterPage( new Account() ) )->register();
				},
				100
			);
		}
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function routes(): void {
		register_rest_route(
			'indexpilot/v1',
			'/ai-fill',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'fill' ),
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) ),
				'args'                => array(
					'post_id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'title'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'content'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'slug'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'description' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Значения полей от модели.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function fill( \WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'post_id' ) );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'indexpilot_ai_post', __( 'Запись не найдена.', 'indexpilot' ), array( 'status' => 404 ) );
		}

		$title   = trim( (string) $request->get_param( 'title' ) );
		$content = (string) $request->get_param( 'content' );
		$title   = '' !== $title ? $title : $post->post_title;
		$content = '' !== trim( $content ) ? $content : $post->post_content;

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( Account::TIMEOUT + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- ответ модели с картинками собирается до двух минут.
		}

		try {
			return new \WP_REST_Response(
				( new Writer( new Account(), $this->settings ) )->fill(
					$title,
					$content,
					'' !== (string) $request->get_param( 'slug' ) ? (string) $request->get_param( 'slug' ) : $post->post_name,
					(string) $request->get_param( 'description' )
				)
			);
		} catch ( WriterException $error ) {
			return new \WP_Error( 'indexpilot_ai_failed', $error->getMessage(), array( 'status' => 400 ) );
		}
	}
}
