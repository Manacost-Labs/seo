<?php
/**
 * REST-маршруты графа ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Links\DepthMap;
use IndexPilot\Links\Reports;
use IndexPilot\Links\Scanner;
use IndexPilot\Links\SuggestionIndex;

/**
 * Обход записей и проверка адресов идут пачками: страница отчёта повторяет запрос до конца.
 */
final class LinksController {

	/**
	 * Конструктор.
	 *
	 * @param Scanner         $scanner     Обход записей.
	 * @param Reports         $reports     Отчёты и проверка адресов.
	 * @param SuggestionIndex $suggestions Подсказки ссылок.
	 * @param DepthMap|null   $depth       Глубина записей; null — создаётся по базе WordPress.
	 */
	public function __construct( private Scanner $scanner, private Reports $reports, private SuggestionIndex $suggestions, private ?DepthMap $depth = null ) {
		if ( null === $this->depth ) {
			global $wpdb;

			$this->depth = new DepthMap( $wpdb, $scanner );
		}
	}

	/**
	 * Регистрирует маршруты.
	 */
	public function register(): void {
		$permission = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			'indexpilot/v1',
			'/links/scan',
			array(
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $request ) => rest_ensure_response( $this->scan( (bool) $request['restart'] ) ),
				'permission_callback' => $permission,
				'args'                => array(
					'restart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Подсказки ссылок для текста из редактора: текст в базу не пишется.
		register_rest_route(
			'indexpilot/v1',
			'/links/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $request ) => rest_ensure_response( $this->suggestions->suggest( (int) $request['post_id'], (string) $request['content'] ) ),
				'permission_callback' => static fn( \WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['post_id'] ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'content' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn( $value ): string => is_string( $value ) ? $value : '',
					),
				),
			)
		);

		register_rest_route(
			'indexpilot/v1',
			'/links/check',
			array(
				'methods'             => 'POST',
				'callback'            => fn() => rest_ensure_response( $this->reports->check_batch() ),
				'permission_callback' => $permission,
			)
		);

		// Глубина записей от главной: один расчёт по графу ссылок, результат хранится.
		register_rest_route(
			'indexpilot/v1',
			'/links/depth',
			array(
				'methods'             => 'POST',
				'callback'            => fn() => rest_ensure_response( $this->depth->compute() ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Очередная пачка обхода; после последней пачки пересчитывается глубина записей.
	 *
	 * @param bool $restart Начать обход сначала.
	 * @return array{processed: int, total: int, done: bool}
	 */
	private function scan( bool $restart ): array {
		$result = $this->scanner->scan_batch( $restart );

		if ( $result['done'] ) {
			$this->depth->compute();
		}

		return $result;
	}
}
