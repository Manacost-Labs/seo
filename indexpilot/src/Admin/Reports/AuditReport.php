<?php
/**
 * Аудит сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\SettingsPage;
use IndexPilot\Audit\Runner;
use IndexPilot\Plugin;

/**
 * Страница «Аудит»: запуск проверки, сводка по проблемам и списки записей по каждой из них.
 */
final class AuditReport {

	public const SLUG = 'indexpilot-audit';

	/**
	 * Конструктор.
	 *
	 * @param Runner $runner Аудит записей.
	 */
	public function __construct( private Runner $runner ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 15 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — аудит', 'indexpilot' ),
			__( 'Аудит', 'indexpilot' ),
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

		wp_enqueue_script( 'indexpilot-batch', plugins_url( 'assets/js/batch.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
		wp_localize_script(
			'indexpilot-batch',
			'IndexPilotBatch',
			array(
				/* translators: 1: обработано, 2: всего. */
				'progress'  => __( 'Проверено %1$d из %2$d…', 'indexpilot' ),
				'remaining' => '',
				'failed'    => __( 'Запрос не выполнен. Нажмите кнопку ещё раз — проверка продолжится с места остановки.', 'indexpilot' ),
			)
		);
	}

	/**
	 * Проблемы: ключ => название и пояснение.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function problems(): array {
		return array(
			'duplicate_title_hash'       => array( __( 'Одинаковые заголовки', 'indexpilot' ), __( 'У нескольких открытых страниц один и тот же заголовок: поисковик не понимает, какую показывать.', 'indexpilot' ) ),
			'duplicate_description_hash' => array( __( 'Одинаковые описания', 'indexpilot' ), __( 'У нескольких открытых страниц одно и то же описание.', 'indexpilot' ) ),
			'near_duplicates'            => array( __( 'Похожие тексты', 'indexpilot' ), __( 'Пары записей, у которых основной текст почти совпадает, даже если заголовки разные. Поисковик покажет только одну из них — объедините их или перепишите одну. Считаются пары; колоды и тексты короче 50 слов не сравниваются.', 'indexpilot' ) ),
			'no_description'             => array( __( 'Нет описания', 'indexpilot' ), __( 'Описание пустое: его не из чего собрать.', 'indexpilot' ) ),
			'auto_description'           => array( __( 'Описание собрано автоматически', 'indexpilot' ), __( 'Описание взято из начала текста. Написанное вручную обычно привлекает больше переходов.', 'indexpilot' ) ),
			'h1'                         => array( __( 'Лишние заголовки H1', 'indexpilot' ), __( 'В тексте записи есть заголовки H1, хотя H1 — это её название.', 'indexpilot' ) ),
			'thin'                       => array( __( 'Мало текста', 'indexpilot' ), __( 'Текст короче нормы для этого типа записей.', 'indexpilot' ) ),
			'stale'                      => array( __( 'Давно не обновлялись', 'indexpilot' ), __( 'Запись не менялась больше полутора лет: проверьте, не устарела ли она.', 'indexpilot' ) ),
			'closed'                     => array( __( 'Закрыты от индексации', 'indexpilot' ), __( 'Для сведения: опубликованные записи, которые поисковик не индексирует.', 'indexpilot' ) ),
		);
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state    = $this->runner->state();
		$problems = $this->problems();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- параметр только выбирает, какой список показать.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$view = isset( $problems[ $view ] ) ? $view : '';
		?>
		<div class="wrap" data-indexpilot-batch-root>
			<h1><?php esc_html_e( 'IndexPilot — аудит', 'indexpilot' ); ?></h1>
			<p><?php esc_html_e( 'Аудит проверяет все опубликованные записи. После первого прохода данные обновляются сами при сохранении записи; полный проход стоит повторять после смены шаблонов в настройках.', 'indexpilot' ); ?></p>
			<p>
				<button type="button" class="button button-primary" data-indexpilot-batch="/indexpilot/v1/audit/run" data-mode="progress" data-restart="<?php echo esc_attr( $state['done'] || 0 === $state['processed'] ? '1' : '0' ); ?>">
					<?php echo esc_html( $state['processed'] > 0 && ! $state['done'] ? __( 'Продолжить проверку', 'indexpilot' ) : __( 'Проверить сайт', 'indexpilot' ) ); ?>
				</button>
				<span data-indexpilot-batch-status>
					<?php
					if ( 0 === $state['processed'] ) {
						esc_html_e( 'Проверка ещё не запускалась.', 'indexpilot' );
					} elseif ( $state['done'] ) {
						/* translators: 1: число записей, 2: дата и время. */
						echo esc_html( sprintf( __( 'Проверено записей: %1$d. Последний полный проход: %2$s.', 'indexpilot' ), $state['processed'], wp_date( 'd.m.Y H:i', $state['updated'] ) ) );
					} else {
						/* translators: 1: обработано, 2: всего. */
						echo esc_html( sprintf( __( 'Проверка остановлена: %1$d из %2$d.', 'indexpilot' ), $state['processed'], $this->runner->total() ) );
					}
					?>
				</span>
			</p>
			<?php
			if ( 0 === $state['processed'] ) {
				echo '</div>';

				return;
			}

			$summary = $this->runner->summary();
			$similar = $this->runner->near_duplicates();

			$summary['near_duplicates'] = count( $similar );
			?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Проблема', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Записей', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Что это значит', 'indexpilot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $problems as $key => $problem ) : ?>
						<tr data-indexpilot-problem="<?php echo esc_attr( $key ); ?>">
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'view', $key, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>"><?php echo esc_html( $problem[0] ); ?></a>
							</td>
							<td data-indexpilot-count><?php echo esc_html( number_format_i18n( $summary[ $key ] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( $problem[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			if ( '' !== $view ) {
				echo '<h2>' . esc_html( $problems[ $view ][0] ) . '</h2>';

				if ( 'near_duplicates' === $view ) {
					$this->render_pairs( $similar );
				} elseif ( str_starts_with( $view, 'duplicate_' ) ) {
					$this->render_groups( $this->runner->duplicate_groups( substr( $view, 10 ) ) );
				} else {
					$this->render_posts( $this->runner->posts_with( $view ) );
				}
			}

			echo '</div>';
	}

	/**
	 * Список записей со ссылками на редактирование.
	 *
	 * @param list<int> $ids ID записей.
	 */
	private function render_posts( array $ids ): void {
		if ( array() === $ids ) {
			echo '<p>' . esc_html__( 'Таких записей нет.', 'indexpilot' ) . '</p>';

			return;
		}

		echo '<ol>';

		foreach ( $ids as $id ) {
			printf( '<li><a href="%1$s">%2$s</a> <code>%3$s</code></li>', esc_url( (string) get_edit_post_link( $id ) ), esc_html( wp_strip_all_tags( get_the_title( $id ) ) ), esc_html( (string) get_post_type( $id ) ) );
		}

		echo '</ol>';
	}

	/**
	 * Пары записей с похожим текстом и процент сходства.
	 *
	 * @param list<array{a: int, b: int, similarity: float}> $pairs Пары.
	 */
	private function render_pairs( array $pairs ): void {
		echo '<p class="description">' . esc_html__( 'Отпечатки текстов обновляются при сохранении записи. После обновления плагина один раз запустите полную проверку — иначе старые записи не сравниваются.', 'indexpilot' ) . '</p>';

		if ( array() === $pairs ) {
			echo '<p>' . esc_html__( 'Похожих текстов нет.', 'indexpilot' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'Запись', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Похожа на', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Сходство', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( $pairs as $pair ) {
			$cells = array();

			foreach ( array( $pair['a'], $pair['b'] ) as $id ) {
				$cells[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $id ) ), esc_html( wp_strip_all_tags( get_the_title( $id ) ) ) );
			}

			printf(
				'<tr data-indexpilot-pair="%1$d-%2$d"><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				(int) $pair['a'],
				(int) $pair['b'],
				$cells[0], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ссылка собрана выше из экранированных частей.
				$cells[1], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ссылка собрана выше из экранированных частей.
				esc_html( number_format_i18n( $pair['similarity'] * 100 ) . ' %' )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Группы записей с одинаковым текстом.
	 *
	 * @param list<list<int>> $groups Группы ID записей.
	 */
	private function render_groups( array $groups ): void {
		if ( array() === $groups ) {
			echo '<p>' . esc_html__( 'Повторов нет.', 'indexpilot' ) . '</p>';

			return;
		}

		foreach ( $groups as $group ) {
			/* translators: %d: число записей в группе. */
			echo '<h3>' . esc_html( sprintf( __( 'Группа из %d записей', 'indexpilot' ), count( $group ) ) ) . '</h3>';
			$this->render_posts( array_slice( $group, 0, 20 ) );
		}
	}
}
