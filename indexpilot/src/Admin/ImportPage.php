<?php
/**
 * Страница импорта из других SEO-плагинов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Import\ImportState;
use IndexPilot\Import\Source;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;

/**
 * Показывает найденные источники и запускает перенос пачками через REST (см. ImportController).
 */
final class ImportPage {

	public const SLUG = 'indexpilot-import';

	/**
	 * Конструктор.
	 *
	 * @param array<string, Source> $sources Источники: идентификатор => источник.
	 * @param ImportState           $state   Состояние импорта.
	 */
	public function __construct( private array $sources, private ImportState $state ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — импорт', 'indexpilot' ),
			__( 'Импорт', 'indexpilot' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Подключает скрипт страницы.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( ! str_ends_with( (string) $hook, '_page_' . self::SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'indexpilot-import', plugins_url( 'assets/js/import.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
		wp_localize_script(
			'indexpilot-import',
			'IndexPilotImport',
			array(
				'labels' => array(
					MetaKeys::TITLE          => __( 'Заголовок', 'indexpilot' ),
					MetaKeys::DESCRIPTION    => __( 'Описание', 'indexpilot' ),
					MetaKeys::FOCUS_KEYWORD  => __( 'Focus Keyword', 'indexpilot' ),
					MetaKeys::KEYWORDS       => __( 'Ключевые слова', 'indexpilot' ),
					MetaKeys::CANONICAL      => __( 'Каноникал', 'indexpilot' ),
					MetaKeys::NOINDEX        => __( 'noindex', 'indexpilot' ),
					MetaKeys::NOFOLLOW       => __( 'nofollow', 'indexpilot' ),
					MetaKeys::OG_TITLE       => __( 'Заголовок для соцсетей', 'indexpilot' ),
					MetaKeys::OG_DESCRIPTION => __( 'Описание для соцсетей', 'indexpilot' ),
					MetaKeys::OG_IMAGE_ID    => __( 'Картинка для соцсетей (ID)', 'indexpilot' ),
					MetaKeys::CORNERSTONE    => __( 'Опорная страница', 'indexpilot' ),
				),
				'text'   => array(
					/* translators: 1: обработано записей, 2: всего записей. */
					'checking'  => __( 'Проверено %1$d из %2$d…', 'indexpilot' ),
					/* translators: 1: обработано записей, 2: всего записей. */
					'importing' => __( 'Обработано %1$d из %2$d…', 'indexpilot' ),
					/* translators: 1: записей, 2: полей, 3: пропущено записей. */
					'preview'   => __( 'Будет перенесено: записей — %1$d, полей — %2$d. Без изменений останется записей: %3$d.', 'indexpilot' ),
					/* translators: 1: записей, 2: полей, 3: пропущено записей. */
					'finished'  => __( 'Готово. Перенесено: записей — %1$d, полей — %2$d. Пропущено записей: %3$d.', 'indexpilot' ),
					'samples'   => __( 'Примеры', 'indexpilot' ),
					/* translators: %d: ID записи. */
					'post'      => __( 'Запись №%d', 'indexpilot' ),
					'failed'    => __( 'Запрос не выполнен. Импорт можно продолжить с того же места.', 'indexpilot' ),
					'confirm'   => __( 'Начать импорт сначала? Уже перенесённые поля останутся на месте.', 'indexpilot' ),
				),
			)
		);
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap" data-indexpilot-import>
			<h1><?php esc_html_e( 'IndexPilot — импорт', 'indexpilot' ); ?></h1>
			<p>
				<?php esc_html_e( 'Импорт переносит заголовок, описание, ключевые слова, каноникал, noindex и поля для соцсетей из другого SEO-плагина. Данные исходного плагина не меняются и не удаляются, поэтому к нему всегда можно вернуться.', 'indexpilot' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Импорт идёт пачками. Если закрыть страницу или связь оборвётся, нажмите «Продолжить» — перенос возобновится с места остановки.', 'indexpilot' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Источник', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Записей с данными', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Состояние', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Действия', 'indexpilot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $this->sources as $source ) {
						$this->row( $source );
					}
					?>
				</tbody>
			</table>

			<p>
				<label>
					<input type="checkbox" data-indexpilot-overwrite>
					<?php esc_html_e( 'Заменять поля, уже заполненные в IndexPilot (по умолчанию они не трогаются)', 'indexpilot' ); ?>
				</label>
			</p>

			<div data-indexpilot-log aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Строка источника.
	 *
	 * @param Source $source Источник.
	 */
	private function row( Source $source ): void {
		$available = $source->available();
		$total     = $available ? $source->total() : 0;
		$state     = $this->state->get( $source->id() );
		$started   = $state['processed'] > 0;
		?>
		<tr data-indexpilot-source="<?php echo esc_attr( $source->id() ); ?>" data-total="<?php echo esc_attr( (string) $total ); ?>" data-processed="<?php echo esc_attr( (string) $state['processed'] ); ?>">
			<td>
				<strong><?php echo esc_html( $source->label() ); ?></strong>
				<?php if ( $source->is_fallback() ) : ?>
					<br><span class="description"><?php esc_html_e( 'Запасной источник: берётся только для записей, у которых после основного импорта нет ни заголовка, ни описания. Запускайте его последним.', 'indexpilot' ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $available ? number_format_i18n( $total ) : '—' ); ?></td>
			<td data-indexpilot-status>
				<?php
				if ( ! $available ) {
					esc_html_e( 'Данных на сайте нет', 'indexpilot' );
				} elseif ( ! $started ) {
					esc_html_e( 'Не запускался', 'indexpilot' );
				} elseif ( $state['done'] ) {
					/* translators: 1: перенесено записей, 2: пропущено записей. */
					echo esc_html( sprintf( __( 'Завершён. Перенесено записей: %1$d, пропущено: %2$d.', 'indexpilot' ), $state['imported'], $state['skipped'] ) );
				} else {
					/* translators: 1: обработано записей, 2: всего записей, 3: перенесено записей. */
					echo esc_html( sprintf( __( 'Остановлен: обработано %1$d из %2$d, перенесено записей: %3$d. Можно продолжить.', 'indexpilot' ), $state['processed'], $total, $state['imported'] ) );
				}
				?>
			</td>
			<td>
				<?php if ( $available ) : ?>
					<button type="button" class="button" data-indexpilot-action="preview"><?php esc_html_e( 'Показать, что будет перенесено', 'indexpilot' ); ?></button>
					<button type="button" class="button button-primary" data-indexpilot-action="run">
						<?php echo esc_html( $started && ! $state['done'] ? __( 'Продолжить', 'indexpilot' ) : __( 'Перенести', 'indexpilot' ) ); ?>
					</button>
					<?php if ( $started ) : ?>
						<button type="button" class="button-link" data-indexpilot-action="restart"><?php esc_html_e( 'Начать сначала', 'indexpilot' ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
