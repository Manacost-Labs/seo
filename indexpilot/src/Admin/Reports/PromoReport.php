<?php
/**
 * Отчёт «Переходы».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\SettingsPage;
use IndexPilot\Promo\ClickStore;
use IndexPilot\Promo\Destinations;
use IndexPilot\Promo\Report;
use IndexPilot\Promo\Rules;

/**
 * Страница отчёта: сколько переходов на площадки дали статьи — по площадкам, записям, вариантам блока и дням.
 * Считаются переходы, а не люди: адреса посетителей плагин не хранит.
 */
final class PromoReport {

	public const SLUG = 'indexpilot-promo-report';

	private const CAP       = 'edit_others_posts';
	private const TOP_POSTS = 50;
	private const TOP_PAID  = 10;

	/**
	 * Конструктор.
	 *
	 * @param ClickStore   $store        Счётчик переходов.
	 * @param Destinations $destinations Площадки.
	 * @param Rules        $rules        Правила блоков.
	 */
	public function __construct( private ClickStore $store, private Destinations $destinations, private Rules $rules ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 17 );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — переходы', 'indexpilot' ),
			__( 'Отчёт о переходах', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- параметр только выбирает период отчёта.
		$days  = Report::period( isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : Report::DEFAULT_PERIOD );
		$range = Report::range( $days, wp_date( 'Y-m-d' ) );
		$rows  = $this->store->rows( $range['from'], $range['to'] );
		$names = array_column( $this->destinations->all(), 'name', 'code' );
		$paid  = array();

		foreach ( $this->destinations->all() as $destination ) {
			if ( 'paid' === $destination['kind'] ) {
				$paid[] = (string) $destination['code'];
			}
		}

		$by_post = Report::by_post( $rows, self::TOP_POSTS );
		$top     = Report::top_posts( $rows, $paid, self::TOP_PAID );

		// Названия записей отчёта загружаются одним запросом.
		$ids = array_column( $by_post, 'post_id' );

		foreach ( $top as $posts ) {
			$ids = array_merge( $ids, array_column( $posts, 'post_id' ) );
		}

		_prime_post_caches( array_values( array_unique( $ids ) ), false, false );
		?>
		<div class="wrap" data-indexpilot-promo-report>
			<h1><?php esc_html_e( 'IndexPilot — переходы', 'indexpilot' ); ?></h1>
			<p><?php esc_html_e( 'Сколько раз читатели перешли из статей на ваши площадки. Считаются переходы, а не люди: адреса посетителей не сохраняются, роботы не учитываются.', 'indexpilot' ); ?></p>
			<ul class="subsubsub" style="float:none">
				<?php foreach ( Report::PERIODS as $number => $period ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'days', $period, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>" <?php echo $period === $days ? 'class="current" aria-current="page"' : ''; ?>>
							<?php
							/* translators: %d: число дней. */
							echo esc_html( sprintf( _n( '%d день', '%d дней', $period, 'indexpilot' ), $period ) );
							?>
						</a><?php echo $number < count( Report::PERIODS ) - 1 ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<strong data-indexpilot-promo-total="<?php echo esc_attr( (string) Report::total( $rows ) ); ?>">
					<?php
					/* translators: 1: число переходов, 2: первый день периода, 3: последний день периода. */
					echo esc_html( sprintf( __( 'Всего переходов: %1$s (%2$s — %3$s).', 'indexpilot' ), number_format_i18n( Report::total( $rows ) ), $this->date( $range['from'] ), $this->date( $range['to'] ) ) );
					?>
				</strong>
			</p>
			<?php
			if ( array() === $rows ) {
				echo '<p>' . esc_html__( 'За этот период переходов не было.', 'indexpilot' ) . '</p></div>';

				return;
			}

			echo '<h2>' . esc_html__( 'По площадкам', 'indexpilot' ) . '</h2>';
			$this->table(
				array( __( 'Площадка', 'indexpilot' ), __( 'Переходов', 'indexpilot' ) ),
				array_map( fn( array $row ): array => array( $this->destination( $row['destination'], $names ), $row['hits'] ), Report::by_destination( $rows ) )
			);

			foreach ( $top as $code => $posts ) {
				/* translators: %s: название площадки. */
				echo '<h2>' . esc_html( sprintf( __( 'Какие статьи ведут на «%s»', 'indexpilot' ), $this->destination( (string) $code, $names ) ) ) . '</h2>';
				$this->table(
					array( __( 'Запись', 'indexpilot' ), __( 'Переходов', 'indexpilot' ) ),
					array_map( fn( array $row ): array => array( $this->post_link( $row['post_id'] ), $row['hits'] ), $posts ),
					true
				);
			}

			echo '<h2>' . esc_html__( 'По записям', 'indexpilot' ) . '</h2>';
			$this->table(
				array( __( 'Запись', 'indexpilot' ), __( 'Переходов на все площадки', 'indexpilot' ) ),
				array_map( fn( array $row ): array => array( $this->post_link( $row['post_id'] ), $row['hits'] ), $by_post ),
				true
			);

			$texts = array();

			foreach ( $this->rules->all() as $rule ) {
				foreach ( $rule['variants'] as $number => $variant ) {
					$texts[ $rule['id'] . '-' . $number ] = (string) $variant['text'];
				}
			}

			echo '<h2>' . esc_html__( 'По вариантам блоков', 'indexpilot' ) . '</h2>';
			$this->table(
				array( __( 'Правило', 'indexpilot' ), __( 'Вариант текста', 'indexpilot' ), __( 'Площадка', 'indexpilot' ), __( 'Переходов', 'indexpilot' ) ),
				array_map(
					fn( array $row ): array => array(
						'№' . $row['rule_id'],
						( $row['variant'] + 1 ) . '. ' . ( $texts[ $row['rule_id'] . '-' . $row['variant'] ] ?? __( '(текст изменён или правило удалено)', 'indexpilot' ) ),
						$this->destination( $row['destination'], $names ),
						$row['hits'],
					),
					Report::by_variant( $rows )
				)
			);

			echo '<h2>' . esc_html__( 'По дням', 'indexpilot' ) . '</h2>';
			$this->table(
				array( __( 'День', 'indexpilot' ), __( 'Переходов', 'indexpilot' ) ),
				array_map( fn( array $row ): array => array( $this->date( $row['day'] ), $row['hits'] ), Report::by_day( $rows ) )
			);

			echo '</div>';
	}

	/**
	 * Печатает таблицу отчёта. Последний столбец — число.
	 *
	 * @param list<string>                 $head Заголовки столбцов.
	 * @param list<list<string|int|float>> $rows Строки.
	 * @param bool                         $html Первый столбец — готовая, уже экранированная разметка (ссылка на запись).
	 */
	private function table( array $head, array $rows, bool $html = false ): void {
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Нет данных.', 'indexpilot' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';

		foreach ( $head as $title ) {
			echo '<th scope="col">' . esc_html( $title ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$last = count( $row ) - 1;

			echo '<tr>';

			foreach ( $row as $number => $cell ) {
				if ( $number === $last ) {
					echo '<td data-indexpilot-hits>' . esc_html( number_format_i18n( (int) $cell ) ) . '</td>';
				} elseif ( $html && 0 === $number ) {
					echo '<td>' . wp_kses_post( (string) $cell ) . '</td>';
				} else {
					echo '<td>' . esc_html( (string) $cell ) . '</td>';
				}
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Название площадки по коду; у удалённой площадки остаётся код.
	 *
	 * @param string                $code  Код площадки.
	 * @param array<string, string> $names Названия площадок по кодам.
	 */
	private function destination( string $code, array $names ): string {
		return isset( $names[ $code ] ) && '' !== $names[ $code ] ? $names[ $code ] : $code;
	}

	/**
	 * Ссылка на запись.
	 *
	 * @param int $post_id ID записи.
	 */
	private function post_link( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			/* translators: %d: ID записи. */
			return esc_html( sprintf( __( 'Запись №%d (удалена)', 'indexpilot' ), $post_id ) );
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $post ) ), esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) );
	}

	/**
	 * День в привычном виде.
	 *
	 * @param string $day День, ГГГГ-ММ-ДД.
	 */
	private function date( string $day ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day );

		return false === $date ? $day : $date->format( 'd.m.Y' );
	}
}
