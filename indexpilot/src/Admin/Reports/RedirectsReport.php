<?php
/**
 * Отчёт по редиректам.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\SettingsPage;
use IndexPilot\Plugin;
use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Redirects\RedirectService;

/**
 * Показывает, что стоит поправить в плагине Redirection: цепочки, петли, цели, которые не открываются,
 * и опубликованные записи, с адреса которых стоит редирект. Сам отчёт редиректы не меняет.
 */
final class RedirectsReport {

	public const SLUG = 'indexpilot-redirects';

	private const ROW_LIMIT = 200;

	/**
	 * Конструктор.
	 *
	 * @param RedirectService $service Редиректы сайта.
	 * @param RedirectedPosts $posts   Записи под редиректом.
	 */
	public function __construct( private RedirectService $service, private RedirectedPosts $posts ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — редиректы', 'indexpilot' ),
			__( 'Редиректы', 'indexpilot' ),
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

		wp_enqueue_script( 'indexpilot-redirects', plugins_url( 'assets/js/redirects.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
		wp_localize_script(
			'indexpilot-redirects',
			'IndexPilotRedirects',
			array(
				/* translators: %d: сколько адресов осталось проверить. */
				'checking' => __( 'Проверка… осталось адресов: %d', 'indexpilot' ),
				'failed'   => __( 'Запрос не выполнен. Нажмите кнопку ещё раз — проверка продолжится.', 'indexpilot' ),
			)
		);
	}

	/**
	 * Данные отчёта.
	 *
	 * @return array{total: int, skipped: int, pending: int, chains: list<array<string, mixed>>, dead: list<array<string, mixed>>, posts: list<array<string, mixed>>}
	 */
	public function data(): array {
		$resolver = $this->service->resolver();
		$targets  = $this->service->targets();
		$chains   = array();
		$dead     = array();

		foreach ( $resolver->problems() as $problem ) {
			$chains[] = array(
				'source' => $problem['redirect']->source,
				'target' => $problem['redirect']->target,
				'final'  => $problem['final'],
				'hops'   => count( $problem['hops'] ),
				'loop'   => $problem['loop'],
				'hits'   => $problem['redirect']->hits,
			);
		}

		foreach ( $this->service->all() as $redirect ) {
			$result = $resolver->resolve( $redirect->source );
			$status = $result['loop'] ? null : $targets->status( $result['final'] );

			if ( null !== $status && ( 0 === $status || $status >= 400 ) ) {
				$dead[] = array(
					'source' => $redirect->source,
					'final'  => $result['final'],
					'status' => $status,
					'hits'   => $redirect->hits,
				);
			}
		}

		$posts = array();
		foreach ( array_keys( $this->posts->map() ) as $post_id ) {
			$posts[] = array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'type'    => get_post_type( $post_id ),
				'final'   => $this->posts->target( $post_id ),
			);
		}

		// Сначала то, что срабатывает чаще: исправление даёт больше пользы.
		$by_hits = static fn( array $a, array $b ): int => $b['hits'] <=> $a['hits'];
		usort( $chains, $by_hits );
		usort( $dead, $by_hits );

		return array(
			'total'   => count( $this->service->all() ),
			'skipped' => $this->service->skipped(),
			'pending' => count( $this->service->pending() ),
			'chains'  => $chains,
			'dead'    => $dead,
			'posts'   => $posts,
		);
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap" data-indexpilot-redirects>';
		echo '<h1>' . esc_html__( 'IndexPilot — редиректы', 'indexpilot' ) . '</h1>';

		if ( ! $this->service->available() ) {
			echo '<p>' . esc_html__( 'Плагин Redirection не установлен: редиректов для проверки нет.', 'indexpilot' ) . '</p></div>';

			return;
		}

		$data = $this->data();
		?>
		<p>
			<?php esc_html_e( 'Редиректами управляет плагин Redirection. Здесь показано, что в них стоит поправить; сам отчёт ничего не меняет.', 'indexpilot' ); ?>
		</p>
		<ul class="ul-disc">
			<li>
				<?php
				/* translators: 1: учтено редиректов, 2: не учтено редиректов. */
				echo esc_html( sprintf( __( 'Редиректов учтено: %1$d. Не учтено (регулярные выражения и условия): %2$d.', 'indexpilot' ), $data['total'], $data['skipped'] ) );
				?>
			</li>
			<li>
				<?php
				/* translators: 1: цепочек и петель, 2: целей не открывается, 3: записей под редиректом. */
				echo esc_html( sprintf( __( 'Цепочек и петель: %1$d. Цель не открывается: %2$d. Опубликованных записей под редиректом: %3$d.', 'indexpilot' ), count( $data['chains'] ), count( $data['dead'] ), count( $data['posts'] ) ) );
				?>
			</li>
		</ul>
		<p>
			<button type="button" class="button button-primary" data-indexpilot-check><?php esc_html_e( 'Проверить цели редиректов', 'indexpilot' ); ?></button>
			<span data-indexpilot-check-status>
				<?php
				/* translators: %d: сколько адресов ждёт проверки. */
				echo esc_html( sprintf( __( 'Ждёт проверки адресов: %d.', 'indexpilot' ), $data['pending'] ) );
				?>
			</span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Проверка запрашивает каждую цель и находит переходы через другой домен и цели, которые больше не открываются. Результат хранится неделю.', 'indexpilot' ); ?>
		</p>

		<h2><?php esc_html_e( 'Цепочки и петли', 'indexpilot' ); ?></h2>
		<?php
		if ( array() === $data['chains'] ) {
			echo '<p>' . esc_html__( 'Не найдено: каждый редирект ведёт сразу на конечный адрес.', 'indexpilot' ) . '</p>';
		} else {
			$this->table(
				array(
					__( 'Исходный адрес', 'indexpilot' ),
					__( 'Сейчас ведёт на', 'indexpilot' ),
					__( 'Переходов', 'indexpilot' ),
					__( 'Что сделать', 'indexpilot' ),
					__( 'Срабатываний', 'indexpilot' ),
				),
				array_map(
					static fn( array $row ): array => array(
						$row['source'],
						$row['target'],
						(string) $row['hops'],
						$row['loop']
							? __( 'Петля: редиректы ведут по кругу. Исправьте цель вручную.', 'indexpilot' )
							/* translators: %s: конечный адрес. */
							: sprintf( __( 'Заменить цель на %s', 'indexpilot' ), $row['final'] ),
						number_format_i18n( $row['hits'] ),
					),
					$data['chains']
				)
			);
		}
		?>

		<h2><?php esc_html_e( 'Цель не открывается', 'indexpilot' ); ?></h2>
		<?php
		if ( array() === $data['dead'] ) {
			echo '<p>' . esc_html__( 'Среди проверенных целей таких нет.', 'indexpilot' ) . '</p>';
		} else {
			$this->table(
				array(
					__( 'Исходный адрес', 'indexpilot' ),
					__( 'Конечный адрес', 'indexpilot' ),
					__( 'Ответ', 'indexpilot' ),
					__( 'Срабатываний', 'indexpilot' ),
				),
				array_map(
					static fn( array $row ): array => array(
						$row['source'],
						$row['final'],
						0 === $row['status'] ? __( 'нет ответа', 'indexpilot' ) : (string) $row['status'],
						number_format_i18n( $row['hits'] ),
					),
					$data['dead']
				)
			);
		}
		?>

		<h2><?php esc_html_e( 'Опубликованные записи под редиректом', 'indexpilot' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Запись опубликована, но с её адреса стоит редирект: читатель её не увидит. Такие записи не попадают в карту сайта. Если материал перенесён окончательно — переведите запись в черновики.', 'indexpilot' ); ?>
		</p>
		<?php
		if ( array() === $data['posts'] ) {
			echo '<p>' . esc_html__( 'Таких записей нет.', 'indexpilot' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Запись', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Тип', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Посетитель попадает на', 'indexpilot' ) . '</th></tr></thead><tbody>';

			foreach ( array_slice( $data['posts'], 0, self::ROW_LIMIT ) as $row ) {
				printf(
					'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
					esc_url( (string) get_edit_post_link( $row['post_id'] ) ),
					esc_html( $row['title'] ),
					esc_html( (string) $row['type'] ),
					esc_html( $row['final'] )
				);
			}

			echo '</tbody></table>';
			$this->more( count( $data['posts'] ) );
		}

		echo '</div>';
	}

	/**
	 * Печатает таблицу из текстовых ячеек.
	 *
	 * @param list<string>       $headers Заголовки столбцов.
	 * @param list<list<string>> $rows    Строки.
	 */
	private function table( array $headers, array $rows ): void {
		echo '<table class="widefat striped"><thead><tr>';

		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( array_slice( $rows, 0, self::ROW_LIMIT ) as $row ) {
			echo '<tr>';

			foreach ( $row as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->more( count( $rows ) );
	}

	/**
	 * Сообщает, что показаны не все строки.
	 *
	 * @param int $total Всего строк.
	 */
	private function more( int $total ): void {
		if ( $total > self::ROW_LIMIT ) {
			/* translators: 1: показано строк, 2: всего строк. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Показаны первые %1$d из %2$d.', 'indexpilot' ), self::ROW_LIMIT, $total ) ) . '</p>';
		}
	}
}
