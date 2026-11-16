<?php
/**
 * REST-маршрут импорта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Import\Importer;
use IndexPilot\Import\ImportState;
use IndexPilot\Import\Source;

/**
 * Один запрос обрабатывает одну пачку записей. Страница импорта повторяет запрос, пока источник не закончится.
 */
final class ImportController {

	private const BATCH = 200;

	/**
	 * Конструктор.
	 *
	 * @param array<string, Source> $sources  Источники: идентификатор => источник.
	 * @param Importer              $importer Импортёр.
	 * @param ImportState           $state    Состояние импорта.
	 */
	public function __construct(
		private array $sources,
		private Importer $importer,
		private ImportState $state
	) {
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function register(): void {
		register_rest_route(
			'indexpilot/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'source'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( $this->sources ),
					),
					'dry_run'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'overwrite' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'restart'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'after_id'  => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Обрабатывает пачку.
	 *
	 * Пробный прогон ничего не пишет и не запоминает: с какой записи продолжать, сообщает страница (after_id).
	 * Настоящий импорт продолжает с места, сохранённого на сервере.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$source = $this->sources[ (string) $request['source'] ] ?? null;

		if ( ! $source instanceof Source || ! $source->available() ) {
			return new \WP_Error( 'indexpilot_import_unavailable', __( 'Данных этого плагина на сайте нет.', 'indexpilot' ), array( 'status' => 400 ) );
		}

		$dry_run = (bool) $request['dry_run'];

		if ( $dry_run ) {
			$result = $this->importer->run( $source, (int) $request['after_id'], self::BATCH, true, (bool) $request['overwrite'] );

			return rest_ensure_response( $result );
		}

		if ( $request['restart'] ) {
			$this->state->reset( $source->id() );
		}

		$state  = $this->state->get( $source->id() );
		$result = $this->importer->run( $source, $state['last_id'], self::BATCH, false, (bool) $request['overwrite'] );
		$state  = $this->state->add( $source->id(), $result );

		// Закрытые от индексации виды страниц AIOSEO хранит в общих настройках: переносятся в конце импорта.
		if ( $result['done'] && 'aioseo' === $source->id() ) {
			\IndexPilot\Import\AioseoSettings::apply();
		}

		return rest_ensure_response(
			array(
				'done'  => $result['done'],
				'state' => $state,
			)
		);
	}
}
