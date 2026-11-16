<?php
/**
 * Отчёт «Запросы».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\HelpTip;
use IndexPilot\Admin\SearchDataPage;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\CrossSite\PartnerClient;
use IndexPilot\SearchData\Module;
use IndexPilot\SearchData\QueriesReport as Report;

/**
 * Что улучшить по данным поиска: страницы на позициях 5–15, страницы, теряющие клики, один запрос — две страницы
 * (в том числе на втором сайте), запросы без подходящей страницы и важные страницы не в индексе Google.
 */
final class QueriesReport {

	public const SLUG = 'indexpilot-queries';

	private const CAP   = 'manage_options';
	private const LIMIT = 50;

	public function __construct( private Module $module ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 12 );
	}

	/**
	 * Добавляет страницу.
	 */
	public function add(): void {
		add_submenu_page( SettingsPage::SLUG, __( 'IndexPilot — запросы', 'indexpilot' ), __( 'Запросы', 'indexpilot' ), self::CAP, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		echo '<div class="wrap" data-indexpilot-queries-report>';
		echo '<h1>' . esc_html__( 'IndexPilot — запросы', 'indexpilot' ) . '</h1>';

		if ( ! $this->module->has_data() ) {
			echo '<p data-indexpilot-queries-off>' . esc_html__( 'Данных поиска пока нет. Подключите Google Search Console и Яндекс Вебмастер и загрузите данные:', 'indexpilot' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . SearchDataPage::SLUG ) ) . '">' . esc_html__( 'Данные поиска', 'indexpilot' ) . '</a>.</p>';
			$this->inspections();
			echo '</div>';

			return;
		}

		$rows   = $this->module->store()->report_rows();
		$report = new Report( $this->module->normalizer() );

		$this->near_top( $report->near_top( $rows, self::LIMIT ) );
		$this->losing( $report->losing( $rows, self::LIMIT ) );
		$this->cannibal( $report->cannibal( $rows, self::LIMIT ), $rows );
		$this->without_page( $report->without_page( $rows, $this->module->site()->page_finder( $this->module->plugin()->audit()->types() ), self::LIMIT ) );
		$this->inspections();

		echo '</div>';
	}

	/**
	 * Заголовок раздела с подсказкой.
	 *
	 * @param string $title Заголовок.
	 * @param string $what  Что сделать.
	 * @param string $why   Зачем.
	 */
	private function heading( string $title, string $what, string $why ): void {
		echo '<h2>' . esc_html( $title ) . ' ';
		HelpTip::render( $what, $why );
		echo '</h2>';
	}

	/**
	 * Страницы на позициях 5–15.
	 *
	 * @param list<array<string, mixed>> $items Строки.
	 */
	private function near_top( array $items ): void {
		$this->heading(
			__( 'Немного не хватает до первой пятёрки', 'indexpilot' ),
			__( 'обновите эти страницы: допишите раздел под запрос, поставьте его в подзаголовок, добавьте ссылки на страницу с опорных материалов.', 'indexpilot' ),
			__( 'страница уже на 5–15 месте: небольшая правка поднимает её в первую пятёрку, где достаётся большинство кликов.', 'indexpilot' )
		);
		self::table(
			'near',
			array( __( 'Страница', 'indexpilot' ), __( 'Главный запрос', 'indexpilot' ), __( 'Позиция', 'indexpilot' ), __( 'Показы', 'indexpilot' ), __( 'Клики', 'indexpilot' ) ),
			array_map(
				static fn( array $item ): array => array(
					Module::object_link( $item['object_type'], $item['object_id'], $item['page'] ),
					esc_html( $item['query'] ),
					esc_html( number_format_i18n( $item['position'], 1 ) ),
					esc_html( number_format_i18n( $item['impressions'] ) ),
					esc_html( number_format_i18n( $item['clicks'] ) ),
				),
				$items
			)
		);
	}

	/**
	 * Страницы, теряющие клики.
	 *
	 * @param list<array<string, mixed>> $items Строки.
	 */
	private function losing( array $items ): void {
		$this->heading(
			__( 'Теряют клики', 'indexpilot' ),
			__( 'проверьте, не устарела ли страница, не появилась ли у конкурентов страница лучше, не изменились ли заголовок и адрес.', 'indexpilot' ),
			__( 'за последние 28 дней кликов на треть меньше, чем за предыдущие 28: раньше заметишь — проще вернуть.', 'indexpilot' )
		);
		self::table(
			'losing',
			array( __( 'Страница', 'indexpilot' ), __( 'Клики раньше', 'indexpilot' ), __( 'Клики сейчас', 'indexpilot' ) ),
			array_map(
				static fn( array $item ): array => array(
					Module::object_link( $item['object_type'], $item['object_id'], $item['page'] ),
					esc_html( number_format_i18n( $item['before'] ) ),
					esc_html( number_format_i18n( $item['after'] ) ),
				),
				$items
			)
		);
	}

	/**
	 * Один запрос — две страницы, в том числе на втором сайте.
	 *
	 * @param list<array<string, mixed>> $items Пары.
	 * @param list<array<string, mixed>> $rows  Все строки (для сверки со вторым сайтом).
	 */
	private function cannibal( array $items, array $rows ): void {
		$this->heading(
			__( 'Один запрос — несколько страниц', 'indexpilot' ),
			__( 'оставьте под запрос одну страницу: вторую объедините с ней или смените у второй основной ключ и заголовок.', 'indexpilot' ),
			__( 'когда по запросу показываются две ваши страницы, они делят клики и обе стоят ниже, чем стояла бы одна.', 'indexpilot' )
		);

		$lines = array();

		foreach ( $items as $item ) {
			$pages = array();

			foreach ( $item['pages'] as $page ) {
				/* translators: %s: позиция. */
				$pages[] = Module::object_link( $page['object_type'], $page['object_id'], $page['page'] ) . ' — ' . esc_html( sprintf( __( 'позиция %s', 'indexpilot' ), number_format_i18n( $page['position'], 1 ) ) );
			}

			$lines[] = array( esc_html( $item['query'] ), implode( '<br>', $pages ), esc_html( number_format_i18n( $item['impressions'] ) ) );
		}

		// Второй сайт: ответы сверены после загрузки данных и запомнены, здесь сеть не используется.
		$partner = new PartnerClient( $this->module->plugin()->settings() );

		if ( $partner->configured() ) {
			$seen = array();

			foreach ( $rows as $row ) {
				if ( 'current' !== $row['period'] || isset( $seen[ $row['query'] ] ) ) {
					continue;
				}

				$seen[ $row['query'] ] = true;
				$matches               = $partner->lookup( (string) $row['query'], false );

				if ( ! empty( $matches ) ) {
					$link    = '<a href="' . esc_url( $matches[0]['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $matches[0]['title'] ) . '</a>';
					$lines[] = array( esc_html( (string) $row['query'] ), Module::object_link( (string) $row['object_type'], (int) $row['object_id'], (string) $row['page'] ) . '<br>' . esc_html__( 'второй сайт:', 'indexpilot' ) . ' ' . $link, esc_html( number_format_i18n( (int) $row['impressions'] ) ) );
				}

				if ( count( $seen ) >= Module::PARTNER_QUERIES ) {
					break;
				}
			}
		}

		self::table( 'cannibal', array( __( 'Запрос', 'indexpilot' ), __( 'Страницы', 'indexpilot' ), __( 'Показы', 'indexpilot' ) ), $lines );
	}

	/**
	 * Запросы без подходящей страницы.
	 *
	 * @param list<array<string, mixed>> $items Запросы.
	 */
	private function without_page( array $items ): void {
		$this->heading(
			__( 'Запросы без подходящей страницы — темы для новых материалов', 'indexpilot' ),
			__( 'напишите материал под запрос или допишите раздел в близкую статью и поставьте запрос в её подзаголовок.', 'indexpilot' ),
			__( 'людей с этим вопросом поисковик уже приводит на сайт или показывает им сайт, но ответа здесь нет — его найдут у конкурента.', 'indexpilot' )
		);
		self::table(
			'topic',
			array( __( 'Запрос', 'indexpilot' ), __( 'Показы', 'indexpilot' ), __( 'Клики', 'indexpilot' ), __( 'Поисковик', 'indexpilot' ) ),
			array_map(
				static fn( array $item ): array => array(
					esc_html( $item['query'] ),
					esc_html( number_format_i18n( $item['impressions'] ) ),
					esc_html( number_format_i18n( $item['clicks'] ) ),
					esc_html( implode( ', ', array_map( static fn( string $engine ): string => 'yandex' === $engine ? 'Яндекс' : 'Google', $item['engines'] ) ) ),
				),
				$items
			)
		);
	}

	/**
	 * Важные страницы не в индексе Google.
	 */
	private function inspections(): void {
		$this->heading(
			__( 'Важные страницы не в индексе Google', 'indexpilot' ),
			__( 'исправьте причину из таблицы и попросите Google проверить страницу заново в Search Console («Проверка URL» → «Запросить индексирование»).', 'indexpilot' ),
			__( 'раз в сутки плагин проверяет опорные материалы, страницы классов и записи за последний месяц — у Google есть дневной предел, поэтому не все страницы, а важные.', 'indexpilot' )
		);

		$lines = array();

		foreach ( $this->module->inspections() as $url => $result ) {
			if ( ! empty( $result['indexed'] ) ) {
				continue;
			}

			$lines[] = array(
				'<a href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener">' . esc_html( (string) $url ) . '</a>',
				esc_html( (string) $result['reason'] ),
				esc_html( wp_date( 'd.m.Y', (int) $result['checked'] ) ),
			);
		}

		self::table( 'not-indexed', array( __( 'Адрес', 'indexpilot' ), __( 'Причина', 'indexpilot' ), __( 'Проверено', 'indexpilot' ) ), $lines );
	}

	/**
	 * Таблица; пусто — короткое сообщение.
	 *
	 * @param string             $kind    Метка строк для проверок.
	 * @param list<string>       $columns Заголовки.
	 * @param list<list<string>> $lines   Ячейки (уже экранированы).
	 */
	public static function table( string $kind, array $columns, array $lines ): void {
		if ( array() === $lines ) {
			echo '<p class="description" data-indexpilot-empty="' . esc_attr( $kind ) . '">' . esc_html__( 'Ничего не найдено.', 'indexpilot' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $lines as $cells ) {
			echo '<tr data-indexpilot-row="' . esc_attr( $kind ) . '"><td>' . implode( '</td><td>', $cells ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ячейки экранированы при сборке.
		}

		echo '</tbody></table>';
	}
}
