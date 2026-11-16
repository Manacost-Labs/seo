<?php
/**
 * Экран самопроверки: страницы сайта и внешние адреса.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Health\Module as HealthModule;
use IndexPilot\Health\OutboundCheck;

/**
 * Отчёт самопроверки (ответ не 200, перенаправление, долгий ответ, пустая страница, архив с полными текстами)
 * и мёртвых внешних ссылок, картинок и роликов — с записью, где они стоят.
 */
final class HealthPage {

	public const SLUG = 'indexpilot-health';

	private const CAP   = 'manage_options';
	private const NONCE = 'indexpilot_health';
	private const LIMIT = 200;

	/**
	 * Сообщение после действия.
	 *
	 * @var string
	 */
	private string $notice = '';

	public function __construct( private HealthModule $module ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 13 );
	}

	/**
	 * Добавляет страницу в меню.
	 */
	public function add(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — самопроверка', 'indexpilot' ),
			__( 'Самопроверка', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Кнопки: начать или продолжить обход, проверить пачку внешних адресов.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_health_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['indexpilot_health_action'] ) );

		if ( 'start' === $action ) {
			$this->module->self_check()->start( $this->module->sitemap_urls() );
		}

		if ( in_array( $action, array( 'start', 'continue' ), true ) ) {
			$result = $this->module->self_check()->run_batch( 5 );
			/* translators: 1: проверено адресов всего, 2: адресов в обходе. */
			$this->notice = sprintf( __( 'Проверено адресов: %1$d из %2$d. Остальные проверятся в фоне.', 'indexpilot' ), $result['cursor'], $result['total'] );

			if ( ! $result['done'] && ! wp_next_scheduled( HealthModule::BATCH ) ) {
				wp_schedule_single_event( time() + 30, HealthModule::BATCH );
			}

			return;
		}

		if ( 'outbound' === $action ) {
			$this->module->collect_batch( 200 );
			/* translators: %d: проверено адресов. */
			$this->notice = sprintf( __( 'Проверено внешних адресов: %d. Остальные проверятся в фоне.', 'indexpilot' ), $this->module->outbound()->check_due( 5, time() ) );
		}
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$store = $this->module->store();
		$state = $this->module->self_check()->state();

		echo '<div class="wrap" data-indexpilot-health>';
		echo '<h1>' . esc_html__( 'IndexPilot — самопроверка', 'indexpilot' ) . '</h1>';

		if ( '' !== $this->notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $this->notice ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Страницы сайта', 'indexpilot' ) . '</h2>';
		echo '<p>' . esc_html__( 'Раз в неделю плагин сам открывает все адреса из карты сайта — не чаще одного в секунду, чтобы не нагружать сервер, — и отмечает страницы, которые отвечают ошибкой, перенаправляют, долго открываются, почти пусты или (у рубрик и авторов) выводят записи целиком вместо выдержек.', 'indexpilot' ) . '</p>';

		if ( $state['started'] > 0 ) {
			echo '<p>';
			echo esc_html(
				$state['done']
					/* translators: %s: дата начала обхода. */
					? sprintf( __( 'Последний обход начат %s и закончен.', 'indexpilot' ), wp_date( 'd.m.Y H:i', $state['started'] ) )
					/* translators: 1: дата начала, 2: проверено, 3: всего. */
					: sprintf( __( 'Обход начат %1$s, проверено %2$d из %3$d — продолжается в фоне.', 'indexpilot' ), wp_date( 'd.m.Y H:i', $state['started'] ), $state['cursor'], $state['total'] )
			);
			echo '</p>';
		}

		$this->button( 'start', __( 'Начать проверку сейчас', 'indexpilot' ) );

		if ( ! $state['done'] ) {
			$this->button( 'continue', __( 'Проверить следующие адреса', 'indexpilot' ) );
		}

		$this->problems( $store->problems( self::LIMIT ) );

		echo '<h2>' . esc_html__( 'Ссылки наружу, картинки и ролики', 'indexpilot' ) . '</h2>';
		echo '<p>' . esc_html__( 'Внешние ссылки, картинки и ролики YouTube из текстов записей и цели коротких ссылок проверяются пачками в фоне: исправные — раз в неделю, со сбоями — раз в сутки. Адрес считается мёртвым после трёх неудач подряд.', 'indexpilot' ) . '</p>';
		$this->button( 'outbound', __( 'Проверить пачку сейчас', 'indexpilot' ) );
		$this->dead( $store->dead( self::LIMIT ) );

		echo '</div>';
	}

	/**
	 * Таблица страниц с проблемами.
	 *
	 * @param list<array<string, mixed>> $rows Строки.
	 */
	private function problems( array $rows ): void {
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Проблем на страницах не найдено.', 'indexpilot' ) . '</p>';

			return;
		}

		$labels = array(
			'status'    => __( 'ответ не 200', 'indexpilot' ),
			'redirect'  => __( 'неожиданное перенаправление', 'indexpilot' ),
			'slow'      => __( 'открывается дольше 3 секунд', 'indexpilot' ),
			'empty'     => __( 'почти пустая страница', 'indexpilot' ),
			'full_text' => __( 'архив выводит записи целиком — нужны выдержки', 'indexpilot' ),
		);

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Адрес', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Что не так', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Ответ', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Время, мс', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Слов', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$what = array();
			foreach ( explode( ',', (string) $row['problems'] ) as $problem ) {
				$what[] = $labels[ $problem ] ?? $problem;
			}

			printf(
				'<tr data-indexpilot-health-problem="%1$s"><td><a href="%2$s" target="_blank" rel="noopener">%3$s</a></td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_attr( (string) $row['problems'] ),
				esc_url( (string) $row['url'] ),
				esc_html( (string) $row['url'] ),
				esc_html( implode( '; ', $what ) ),
				esc_html( 0 === (int) $row['status'] ? __( 'нет ответа', 'indexpilot' ) : (string) $row['status'] ),
				esc_html( number_format_i18n( (int) $row['ms'] ) ),
				esc_html( number_format_i18n( (int) $row['words'] ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Таблица мёртвых внешних адресов. Адрес, которого уже нет в тексте записи, убирается из отчёта.
	 *
	 * @param list<array<string, mixed>> $rows Строки.
	 */
	private function dead( array $rows ): void {
		$store  = $this->module->store();
		$labels = array(
			'link'  => __( 'ссылка не открывается', 'indexpilot' ),
			'image' => __( 'картинка не найдена', 'indexpilot' ),
			'video' => __( 'ролик удалён или закрыт', 'indexpilot' ),
		);
		$shown  = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];

			if ( $post_id > 0 ) {
				$content = (string) get_post_field( 'post_content', $post_id );
				$url     = (string) $row['url'];
				$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
				// Ролики записи пересобираются при каждом сохранении; ссылки и картинки ищутся в тексте (картинка своего сайта — по пути).
				$present = 'video' === $row['kind'] || str_contains( $content, $url ) || str_contains( $content, esc_attr( $url ) ) || ( 'image' === $row['kind'] && strlen( $path ) > 1 && str_contains( $content, $path ) );

				if ( 'publish' !== get_post_status( $post_id ) || ! $present ) {
					$store->delete( (string) $row['kind'], (string) $row['url'] );
					continue;
				}
			}

			$shown[] = $row;
		}

		if ( array() === $shown ) {
			echo '<p>' . esc_html__( 'Мёртвых адресов не найдено.', 'indexpilot' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Что', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Адрес', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Где стоит', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Ответ', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( $shown as $row ) {
			$post_id = (int) $row['post_id'];
			$where   = $post_id > 0
				? '<a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html( get_the_title( $post_id ) ) . '</a>'
				: esc_html__( 'короткая ссылка', 'indexpilot' );

			printf(
				'<tr data-indexpilot-dead="%1$s"><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_attr( (string) $row['kind'] ),
				esc_html( $labels[ (string) $row['kind'] ] ?? (string) $row['kind'] ),
				esc_html( (string) $row['url'] ),
				$where, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- части экранированы выше.
				esc_html( 0 === (int) $row['status'] ? __( 'нет ответа', 'indexpilot' ) : (string) $row['status'] )
			);
		}

		echo '</tbody></table>';
		/* translators: %d: после скольких неудач подряд адрес мёртв. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Мёртвым считается адрес, не открывшийся %d раза подряд.', 'indexpilot' ), OutboundCheck::DEAD_AFTER ) ) . '</p>';
	}

	/**
	 * Кнопка действия.
	 *
	 * @param string $action Действие.
	 * @param string $label  Надпись.
	 */
	private function button( string $action, string $label ): void {
		echo '<form method="post" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="indexpilot_health_action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}
}
