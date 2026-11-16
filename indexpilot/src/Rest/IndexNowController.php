<?php
/**
 * REST-маршрут ручной работы с IndexNow.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\IndexNow\Notifier;
use IndexPilot\Sitemap\Router;

/**
 * Действия со страницы «Поисковики»: отправить очередь сейчас, отправить заданные адреса,
 * отправить все страницы сайта (по одному файлу карты сайта за запрос).
 */
final class IndexNowController {

	/**
	 * Конструктор.
	 *
	 * @param Notifier $notifier Очередь и отправка.
	 * @param Router   $sitemap  Карта сайта: источник адресов всех страниц.
	 */
	public function __construct( private Notifier $notifier, private Router $sitemap ) {
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function register(): void {
		register_rest_route(
			'indexpilot/v1',
			'/indexnow',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'send_queue', 'submit', 'submit_all' ),
					),
					'urls'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'step'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Выполняет действие.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		if ( ! $this->notifier->enabled() ) {
			return new \WP_Error( 'indexpilot_indexnow_disabled', __( 'IndexNow выключен в настройках плагина или сайт закрыт от поисковиков.', 'indexpilot' ), array( 'status' => 409 ) );
		}

		switch ( (string) $request['action'] ) {
			case 'send_queue':
				$this->notifier->send();

				return rest_ensure_response(
					array(
						'queue' => $this->notifier->queue()->count(),
						'done'  => true,
					)
				);

			case 'submit':
				$urls = preg_split( '/\s+/u', (string) $request['urls'], -1, PREG_SPLIT_NO_EMPTY );

				return rest_ensure_response( $this->notifier->submit( array_slice( array_map( 'esc_url_raw', (array) $urls ), 0, 1000 ) ) + array( 'done' => true ) );

			default:
				$files = $this->sitemap->file_requests();
				$step  = (int) $request['step'];

				if ( ! isset( $files[ $step ] ) ) {
					return rest_ensure_response(
						array(
							'step'  => $step,
							'files' => count( $files ),
							'sent'  => 0,
							'ok'    => true,
							'done'  => true,
						)
					);
				}

				$result = $this->notifier->submit( $this->sitemap->locs( $files[ $step ] ) );

				return rest_ensure_response(
					$result + array(
						'step'  => $step + 1,
						'files' => count( $files ),
						// При отказе поисковика продолжать нет смысла: причина одна для всех файлов.
						'done'  => ! $result['ok'] || $step + 1 >= count( $files ),
					)
				);
		}
	}
}
