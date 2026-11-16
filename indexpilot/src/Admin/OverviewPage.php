<?php
/**
 * Экран «Обзор».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Admin\Reports\AuditReport;
use IndexPilot\Admin\Reports\LinksReport;
use IndexPilot\Admin\Reports\RedirectsReport;
use IndexPilot\Overview\Facts;
use IndexPilot\Overview\TaskList;

/**
 * Один экран «что сделать сегодня»: до пяти самых важных дел со ссылками на отчёты.
 *
 * Экран открывается быстро на сайте любого размера: он не обходит записи, а только считает строки
 * в таблицах аудита и ссылок и читает сохранённые состояния. Скриптов и стилей у экрана нет.
 */
final class OverviewPage {

	public const SLUG = 'indexpilot-overview';

	public const CAPABILITY = 'edit_others_posts';

	/**
	 * Конструктор.
	 *
	 * @param Facts $facts Цифры из готовых данных аудита, ссылок и редиректов.
	 */
	public function __construct( private Facts $facts ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 11 );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — обзор', 'indexpilot' ),
			__( 'Обзор', 'indexpilot' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Дела: ключ => текст с числом, пояснение, отчёт и параметр списка в отчёте.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	private function tasks(): array {
		return array(
			'run_audit'              => array( __( 'Проверьте сайт аудитом', 'indexpilot' ), __( 'Аудит ещё не запускался или остановлен на середине: без него неизвестно, у каких записей нет описания и повторяются заголовки.', 'indexpilot' ), AuditReport::SLUG, '' ),
			'scan_links'             => array( __( 'Обойдите записи для графа ссылок', 'indexpilot' ), __( 'Обход ещё не запускался или остановлен на середине: без него не видны битые ссылки и записи без входящих ссылок.', 'indexpilot' ), LinksReport::SLUG, '' ),
			/* translators: %d: число ссылок. */
			'broken_links'           => array( __( 'Битых внутренних ссылок: %d', 'indexpilot' ), __( 'Ссылки ведут на снятые с публикации записи или на адреса, которые не открываются.', 'indexpilot' ), LinksReport::SLUG, '' ),
			/* translators: %d: число редиректов. */
			'dead_redirects'         => array( __( 'Редиректов, цель которых не открывается: %d', 'indexpilot' ), __( 'Посетитель и поисковик попадают на страницу с ошибкой.', 'indexpilot' ), RedirectsReport::SLUG, '' ),
			/* translators: %d: число адресов. */
			'frequent_404'           => array( __( 'Частых адресов «не найдено» (404): %d', 'indexpilot' ), __( 'По этим адресам приходят снова и снова: поставьте редирект на похожую страницу.', 'indexpilot' ), NotFoundPage::SLUG, '' ),
			/* translators: %d: число адресов. */
			'dead_outbound'          => array( __( 'Мёртвых ссылок наружу, картинок и роликов: %d', 'indexpilot' ), __( 'Ссылка, картинка или ролик в тексте не открывается три проверки подряд.', 'indexpilot' ), HealthPage::SLUG, '' ),
			/* translators: %d: число страниц. */
			'health_problems'        => array( __( 'Страниц с проблемами по самопроверке: %d', 'indexpilot' ), __( 'Страница из карты сайта отвечает ошибкой, перенаправляет, долго открывается, почти пуста или архив выводит записи целиком.', 'indexpilot' ), HealthPage::SLUG, '' ),
			/* translators: %d: число страниц. */
			'duplicate_titles'       => array( __( 'Страниц с повторяющимся заголовком: %d', 'indexpilot' ), __( 'Поисковик не понимает, какую из одинаково названных страниц показывать.', 'indexpilot' ), AuditReport::SLUG, 'duplicate_title_hash' ),
			/* translators: %d: число страниц. */
			'no_description'         => array( __( 'Страниц без описания: %d', 'indexpilot' ), __( 'Описание пустое, и собрать его не из чего.', 'indexpilot' ), AuditReport::SLUG, 'no_description' ),
			/* translators: %d: число редиректов. */
			'redirect_chains'        => array( __( 'Цепочек и петель редиректов: %d', 'indexpilot' ), __( 'Редирект ведёт на адрес, с которого стоит ещё один редирект.', 'indexpilot' ), RedirectsReport::SLUG, '' ),
			/* translators: %d: число записей. */
			'orphans'                => array( __( 'Записей без входящих ссылок: %d', 'indexpilot' ), __( 'На такую запись не ссылается ни одна другая.', 'indexpilot' ), LinksReport::SLUG, '' ),
			/* translators: %d: число записей. */
			'thin'                   => array( __( 'Записей, где мало текста: %d', 'indexpilot' ), __( 'Текст короче нормы для своего типа записей.', 'indexpilot' ), AuditReport::SLUG, 'thin' ),
			/* translators: %d: число записей. */
			'h1'                     => array( __( 'Записей с лишним заголовком H1: %d', 'indexpilot' ), __( 'H1 — это название записи; в тексте его быть не должно.', 'indexpilot' ), AuditReport::SLUG, 'h1' ),
			/* translators: %d: число страниц. */
			'duplicate_descriptions' => array( __( 'Страниц с повторяющимся описанием: %d', 'indexpilot' ), __( 'У нескольких открытых страниц одно и то же описание.', 'indexpilot' ), AuditReport::SLUG, 'duplicate_description_hash' ),
			/* translators: %d: число записей. */
			'auto_description'       => array( __( 'Записей с описанием, собранным автоматически: %d', 'indexpilot' ), __( 'Описание, написанное вручную, обычно привлекает больше переходов.', 'indexpilot' ), AuditReport::SLUG, 'auto_description' ),
			/* translators: %d: число записей. */
			'stale'                  => array( __( 'Записей, которые давно не обновлялись: %d', 'indexpilot' ), __( 'Запись не менялась больше полутора лет: проверьте, не устарела ли она.', 'indexpilot' ), AuditReport::SLUG, 'stale' ),
		);
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$labels = $this->tasks();
		$tasks  = TaskList::pick( $this->facts->counts() );
		// Отчёты открыты только администраторам: остальным дело показывается без ссылки.
		$linked = current_user_can( 'manage_options' );

		echo '<div class="wrap" data-indexpilot-overview>';
		echo '<h1>' . esc_html__( 'IndexPilot — обзор', 'indexpilot' ) . '</h1>';

		if ( array() === $tasks ) {
			echo '<p>' . esc_html__( 'Срочных дел нет: аудит, ссылки и редиректы в порядке.', 'indexpilot' ) . '</p></div>';

			return;
		}

		echo '<p>' . esc_html__( 'Что сделать сегодня — самые важные дела по данным аудита, ссылок и редиректов. Сверху то, что важнее.', 'indexpilot' ) . '</p>';
		echo '<ol>';

		foreach ( $tasks as $task ) {
			list( $title, $hint, $page, $view ) = $labels[ $task['key'] ];

			$title = sprintf( $title, $task['count'] );
			$url   = admin_url( 'admin.php?page=' . $page );
			$url   = '' === $view ? $url : add_query_arg( 'view', $view, $url );

			printf( '<li data-indexpilot-task="%s"><p><strong>', esc_attr( $task['key'] ) );

			if ( $linked ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $title ) );
			} else {
				echo esc_html( $title );
			}

			echo '</strong><br><span class="description">' . esc_html( $hint ) . '</span></p></li>';
		}

		echo '</ol></div>';
	}
}
