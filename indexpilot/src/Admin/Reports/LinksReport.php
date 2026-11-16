<?php
/**
 * Отчёт по ссылкам.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\SettingsPage;
use IndexPilot\Links\DepthMap;
use IndexPilot\Links\Reports;
use IndexPilot\Links\Scanner;
use IndexPilot\Plugin;

/**
 * Страница «Ссылки»: обход записей, записи без входящих ссылок и внутренние ссылки с проблемой.
 */
final class LinksReport {

	public const SLUG = 'indexpilot-links';

	/**
	 * Конструктор.
	 *
	 * @param Scanner $scanner Обход записей.
	 * @param Reports       $reports Отчёты.
	 * @param DepthMap|null $depth   Глубина записей; null — создаётся по базе WordPress.
	 */
	public function __construct( private Scanner $scanner, private Reports $reports, private ?DepthMap $depth = null ) {
		if ( null === $this->depth ) {
			global $wpdb;

			$this->depth = new DepthMap( $wpdb, $scanner );
		}
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 13 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — ссылки', 'indexpilot' ),
			__( 'Ссылки', 'indexpilot' ),
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
				'progress'  => __( 'Обработано %1$d из %2$d…', 'indexpilot' ),
				/* translators: %d: осталось адресов. */
				'remaining' => __( 'Осталось адресов: %d…', 'indexpilot' ),
				'failed'    => __( 'Запрос не выполнен. Нажмите кнопку ещё раз — работа продолжится с места остановки.', 'indexpilot' ),
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

		$state = $this->scanner->state();
		$total = $this->scanner->total();
		$types = $this->scanner->types();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- параметр только выбирает, какой тип записей показать в отчёте.
		$selected = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		$selected = in_array( $selected, $types, true ) ? $selected : ( $types[0] ?? 'post' );
		?>
		<div class="wrap" data-indexpilot-batch-root>
			<h1><?php esc_html_e( 'IndexPilot — ссылки', 'indexpilot' ); ?></h1>
			<p>
				<?php esc_html_e( 'Плагин запоминает, какие записи ссылаются друг на друга. Первый раз нужно обойти все записи; дальше данные обновляются сами при каждом сохранении записи.', 'indexpilot' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" data-indexpilot-batch="/indexpilot/v1/links/scan" data-mode="progress">
					<?php echo esc_html( $state['processed'] > 0 && ! $state['done'] ? __( 'Продолжить обход', 'indexpilot' ) : __( 'Обойти записи', 'indexpilot' ) ); ?>
				</button>
				<?php if ( $state['processed'] > 0 ) : ?>
					<button type="button" class="button" data-indexpilot-batch="/indexpilot/v1/links/scan" data-mode="progress" data-restart="1"><?php esc_html_e( 'Обойти заново', 'indexpilot' ); ?></button>
				<?php endif; ?>
				<span data-indexpilot-batch-status>
					<?php
					if ( 0 === $state['processed'] ) {
						esc_html_e( 'Обход ещё не запускался.', 'indexpilot' );
					} elseif ( $state['done'] ) {
						/* translators: %d: число записей. */
						echo esc_html( sprintf( __( 'Обход завершён, записей: %d.', 'indexpilot' ), $state['processed'] ) );
					} else {
						/* translators: 1: обработано, 2: всего. */
						echo esc_html( sprintf( __( 'Обход остановлен: %1$d из %2$d.', 'indexpilot' ), $state['processed'], $total ) );
					}
					?>
				</span>
			</p>

			<h2><?php esc_html_e( 'Записи без входящих ссылок', 'indexpilot' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'На такую запись не ссылается ни одна другая: читатель и поисковый робот могут попасть на неё только из списков. Поставьте на неё ссылки из близких по теме материалов.', 'indexpilot' ); ?>
			</p>
			<ul class="subsubsub">
				<?php foreach ( $types as $index => $type ) : ?>
					<?php $object = get_post_type_object( $type ); ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'post_type', $type, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>" <?php echo $type === $selected ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $object ? $object->labels->name : $type ); ?></a><?php echo $index < count( $types ) - 1 ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="clear"></div>
			<?php
			$orphans = $state['processed'] > 0 ? $this->reports->orphans( $selected ) : array();

			if ( 0 === $state['processed'] ) {
				echo '<p>' . esc_html__( 'Сначала обойдите записи.', 'indexpilot' ) . '</p>';
			} elseif ( array() === $orphans ) {
				echo '<p>' . esc_html__( 'Таких записей нет.', 'indexpilot' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Запись', 'indexpilot' ) . '</th></tr></thead><tbody>';

				foreach ( $orphans as $row ) {
					printf( '<tr><td><a href="%1$s">%2$s</a></td></tr>', esc_url( (string) get_edit_post_link( $row['post_id'] ) ), esc_html( $row['title'] ) );
				}

				echo '</tbody></table>';
			}
			$this->render_depth( $state['processed'] > 0 );
			?>

			<h2><?php esc_html_e( 'Внутренние ссылки с проблемой', 'indexpilot' ); ?></h2>
			<p>
				<button type="button" class="button" data-indexpilot-batch="/indexpilot/v1/links/check" data-mode="remaining"><?php esc_html_e( 'Проверить адреса', 'indexpilot' ); ?></button>
				<span class="description">
					<?php
					/* translators: %d: число адресов. */
					echo esc_html( sprintf( __( 'Адресов, которые не являются записями и ещё не проверены: %d. Проверка находит ссылки на несуществующие страницы.', 'indexpilot' ), count( $this->reports->addresses_to_check() ) ) );
					?>
				</span>
			</p>
			<?php
			$broken = $state['processed'] > 0 ? $this->reports->broken() : array();

			if ( array() === $broken ) {
				echo '<p>' . esc_html__( 'Проблем не найдено.', 'indexpilot' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Ссылка', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Проблема', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Где стоит', 'indexpilot' ) . '</th></tr></thead><tbody>';

				foreach ( $broken as $row ) {
					printf(
						'<tr><td>%1$s</td><td>%2$s</td><td><a href="%3$s">%4$s</a>%5$s</td></tr>',
						esc_html( $row['url'] ),
						esc_html( $this->problem_text( $row['problem'], $row['detail'] ) ),
						esc_url( (string) get_edit_post_link( $row['post_id'] ) ),
						esc_html( $row['title'] ),
						/* translators: %d: число других записей с той же ссылкой. */
						$row['sources'] > 1 ? ' ' . esc_html( sprintf( __( 'и ещё записей: %d', 'indexpilot' ), $row['sources'] - 1 ) ) : ''
					);
				}

				echo '</tbody></table>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Раздел «Глубина записей»: сколько переходов от главной до записи.
	 *
	 * @param bool $scanned Обходились ли записи.
	 */
	private function render_depth( bool $scanned ): void {
		$depth = $this->depth->stored();
		?>
		<h2 data-indexpilot-depth><?php esc_html_e( 'Глубина записей', 'indexpilot' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Сколько переходов по ссылкам нужно сделать от главной страницы, чтобы попасть на запись. Отсчёт идёт от главной и меню сайта. Чем глубже запись, тем реже до неё доходят робот и читатель: главные материалы должны быть не дальше трёх переходов.', 'indexpilot' ); ?>
		</p>
		<p>
			<button type="button" class="button" data-indexpilot-batch="/indexpilot/v1/links/depth" data-mode="progress"><?php esc_html_e( 'Пересчитать глубину', 'indexpilot' ); ?></button>
			<span class="description">
				<?php
				echo esc_html(
					$depth['updated'] > 0
						/* translators: %s: дата и время. */
						? sprintf( __( 'Посчитано: %s. Глубина пересчитывается и после полного обхода записей.', 'indexpilot' ), wp_date( 'd.m.Y H:i', $depth['updated'] ) )
						: __( 'Ещё не считалась. Нажмите кнопку или обойдите записи.', 'indexpilot' )
				);
				?>
			</span>
		</p>
		<?php
		if ( ! $scanned || 0 === $depth['updated'] ) {
			return;
		}

		$levels = array();
		foreach ( DepthMap::distribution( $depth['depths'] ) as $level => $count ) {
			/* translators: 1: число переходов, 2: число записей. */
			$levels[] = sprintf( __( '%1$d — %2$d', 'indexpilot' ), $level, $count );
		}

		/* translators: %s: список «глубина — число записей». */
		echo '<p>' . esc_html( sprintf( __( 'Записей по числу переходов: %s.', 'indexpilot' ), array() === $levels ? '—' : implode( '; ', $levels ) ) ) . '</p>';

		$deep = array_slice( DepthMap::deep( $depth['depths'], $depth['cornerstone'] ), 0, 200 );

		echo '<h3>' . esc_html__( 'Глубже трёх переходов', 'indexpilot' ) . '</h3>';

		if ( array() === $deep ) {
			echo '<p>' . esc_html__( 'Таких записей нет.', 'indexpilot' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Запись', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Переходов от главной', 'indexpilot' ) . '</th></tr></thead><tbody>';

			foreach ( $deep as $row ) {
				printf(
					'<tr data-indexpilot-depth-row="%1$d"><td><a href="%2$s">%3$s</a>%4$s</td><td>%5$d</td></tr>',
					(int) $row['post_id'],
					esc_url( (string) get_edit_post_link( $row['post_id'] ) ),
					esc_html( wp_strip_all_tags( get_the_title( $row['post_id'] ) ) ),
					$row['cornerstone'] ? ' <strong>' . esc_html__( '— главный материал: поставьте на него ссылку с главной, из меню или из популярных записей', 'indexpilot' ) . '</strong>' : '',
					(int) $row['depth']
				);
			}

			echo '</tbody></table>';
		}

		$cornerstone = array_flip( $depth['cornerstone'] );
		$unreachable = $depth['unreachable'];
		usort( $unreachable, static fn( int $a, int $b ): int => array( ! isset( $cornerstone[ $a ] ), -$a ) <=> array( ! isset( $cornerstone[ $b ] ), -$b ) );
		$unreachable = $this->reports->indexable( $unreachable );

		echo '<h3>' . esc_html__( 'Не достижимы с главной', 'indexpilot' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'До этих записей нельзя дойти по ссылкам ни с главной, ни из меню — только через рубрики, поиск или карту сайта. Поставьте на них ссылки из близких по теме материалов.', 'indexpilot' ) . '</p>';

		if ( array() === $unreachable ) {
			echo '<p>' . esc_html__( 'Таких записей нет.', 'indexpilot' ) . '</p>';

			return;
		}

		echo '<ul data-indexpilot-unreachable>';
		foreach ( $unreachable as $id ) {
			printf(
				'<li><a href="%1$s">%2$s</a>%3$s</li>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( wp_strip_all_tags( get_the_title( $id ) ) ),
				isset( $cornerstone[ $id ] ) ? ' <strong>' . esc_html__( '— главный материал', 'indexpilot' ) . '</strong>' : ''
			);
		}
		echo '</ul>';
	}

	/**
	 * Описание проблемы для редактора.
	 *
	 * @param string $problem Вид проблемы.
	 * @param string $detail  Подробность: конечный адрес, название записи или код ответа.
	 */
	private function problem_text( string $problem, string $detail ): string {
		switch ( $problem ) {
			case 'redirect':
				/* translators: %s: конечный адрес. */
				return sprintf( __( 'Адрес перенаправляет. Замените ссылку на %s', 'indexpilot' ), $detail );

			case 'unpublished':
				/* translators: %s: название записи. */
				return sprintf( __( 'Запись «%s» не опубликована: ссылка ведёт на ошибку 404.', 'indexpilot' ), $detail );

			default:
				/* translators: %s: код ответа или пусто. */
				return trim( sprintf( __( 'Страница не открывается. %s', 'indexpilot' ), '' !== $detail ? __( 'Ответ сервера:', 'indexpilot' ) . ' ' . $detail : '' ) );
		}
	}
}
