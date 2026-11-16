<?php
/**
 * Меню плагина в админке.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Admin\Reports\AiReport;
use IndexPilot\Admin\Reports\AuditReport;
use IndexPilot\Admin\Reports\AuthorsReport;
use IndexPilot\Admin\Reports\ImagesReport;
use IndexPilot\Admin\Reports\LinksReport;
use IndexPilot\Admin\Reports\RedirectsReport;

/**
 * Сводит экраны плагина к нескольким пунктам меню: Обзор, Контент, Переходы, Техника, Настройки, Импорт.
 * Экраны одной группы остаются отдельными страницами и переключаются вкладками вверху.
 * Сами страницы об этом не знают: они регистрируют себя как раньше, а этот класс перестраивает готовое меню.
 */
final class Menu {

	/**
	 * Экраны плагина по порядку. Строка — отдельный пункт меню, массив — группа: название и её экраны.
	 */
	private const LAYOUT = array(
		OverviewPage::SLUG,
		array(
			'content',
			array( BulkEditor::SLUG, AuditReport::SLUG, LinksReport::SLUG, ImagesReport::SLUG, AuthorsReport::SLUG, PatchReport::SLUG, Reports\QueriesReport::SLUG, Reports\TopicsReport::SLUG, Reports\StaleReport::SLUG ),
		),
		array(
			'promo',
			array( 'indexpilot-promo-report', 'indexpilot-promo', AiReport::SLUG ),
		),
		array(
			'tech',
			array( RedirectsPage::SLUG, NotFoundPage::SLUG, RedirectsReport::SLUG, HealthPage::SLUG, HeadInspector::SLUG, SearchEnginesPage::SLUG, SearchDataPage::SLUG, ShortLinksPage::SLUG ),
		),
		SettingsPage::SLUG,
		ImportPage::SLUG,
	);

	/**
	 * Названия групп по умолчанию; в админке заменяются переводимыми строками.
	 */
	private const LABELS = array(
		'content' => 'Контент',
		'promo'   => 'Переходы',
		'tech'    => 'Техника',
	);

	/**
	 * Готовое меню текущего пользователя.
	 *
	 * @var array{items: list<array<int, mixed>>, active: array<string, string>, tabs: array<string, list<array{slug: string, title: string}>>}|null
	 */
	private ?array $menu = null;

	/**
	 * Перестраивает список экранов.
	 *
	 * @param list<array<int, mixed>>  $items  Строки подменю WordPress: название, право, адрес страницы.
	 * @param callable( string ): bool $can    Есть ли у пользователя право.
	 * @param array<string, string>    $labels Названия групп; пусто — названия по умолчанию.
	 * @return array{items: list<array<int, mixed>>, active: array<string, string>, tabs: array<string, list<array{slug: string, title: string}>>}
	 */
	public static function arrange( array $items, callable $can, array $labels = array() ): array {
		$labels = $labels + self::LABELS;
		$known  = array();

		foreach ( $items as $item ) {
			if ( $can( (string) $item[1] ) ) {
				$known[ (string) $item[2] ] = $item;
			}
		}

		$result = array(
			'items'  => array(),
			'active' => array(),
			'tabs'   => array(),
		);

		foreach ( self::LAYOUT as $entry ) {
			if ( is_string( $entry ) ) {
				if ( isset( $known[ $entry ] ) ) {
					$result['items'][] = $known[ $entry ];
					unset( $known[ $entry ] );
				}

				continue;
			}

			list( $group, $slugs ) = $entry;
			$pages                 = array();

			foreach ( $slugs as $slug ) {
				if ( isset( $known[ $slug ] ) ) {
					$pages[] = array(
						'slug'  => $slug,
						'title' => (string) $known[ $slug ][0],
					);
				}
			}

			if ( array() === $pages ) {
				continue;
			}

			$first    = $pages[0]['slug'];
			$item     = $known[ $first ];
			$item[0]  = $labels[ $group ];
			$has_tabs = count( $pages ) > 1;

			$result['items'][] = $item;

			foreach ( $pages as $page ) {
				$result['active'][ $page['slug'] ] = $first;

				if ( $has_tabs ) {
					$result['tabs'][ $page['slug'] ] = $pages;
				}

				unset( $known[ $page['slug'] ] );
			}
		}

		// Экран, которого нет в раскладке, не теряется: он остаётся в конце меню.
		foreach ( $known as $item ) {
			$result['items'][] = $item;
		}

		return $result;
	}

	/**
	 * Подключает перестройку меню. Она идёт на хуке admin_head, а не admin_menu: к этому моменту WordPress
	 * уже проверил по полному меню право на открытую страницу. Если убрать пункт раньше, страница перестанет открываться.
	 */
	public function register(): void {
		add_action( 'admin_head', array( $this, 'rebuild' ), 1 );
		add_filter( 'submenu_file', array( $this, 'highlight' ) );
		add_action( 'all_admin_notices', array( $this, 'tabs' ), 1 );
	}

	/**
	 * Заменяет подменю плагина сокращённым.
	 */
	public function rebuild(): void {
		global $submenu;

		if ( empty( $submenu[ SettingsPage::SLUG ] ) || ! is_array( $submenu[ SettingsPage::SLUG ] ) ) {
			return;
		}

		$this->menu = self::arrange(
			array_values( $submenu[ SettingsPage::SLUG ] ),
			static fn( string $cap ): bool => current_user_can( $cap ),
			array(
				'content' => __( 'Контент', 'indexpilot' ),
				'promo'   => __( 'Переходы', 'indexpilot' ),
				'tech'    => __( 'Техника', 'indexpilot' ),
			)
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- меняется только подменю самого плагина; страницы остаются зарегистрированными и открываются по своим адресам.
		$submenu[ SettingsPage::SLUG ] = $this->menu['items'];
	}

	/**
	 * Подсвечивает пункт группы, когда открыт любой её экран.
	 *
	 * @param string|null $submenu_file Подсвечиваемый пункт подменю.
	 * @return string|null
	 */
	public function highlight( $submenu_file ) {
		$page = $this->page();

		return null !== $this->menu && isset( $this->menu['active'][ $page ] ) ? $this->menu['active'][ $page ] : $submenu_file;
	}

	/**
	 * Печатает вкладки группы над содержимым экрана.
	 */
	public function tabs(): void {
		$page = $this->page();

		if ( null === $this->menu || ! isset( $this->menu['tabs'][ $page ] ) ) {
			return;
		}

		echo '<div class="wrap" style="margin-bottom:0"><nav class="nav-tab-wrapper">';

		foreach ( $this->menu['tabs'][ $page ] as $tab ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$tab['slug'] === $page ? ' nav-tab-active' : '',
				esc_url( admin_url( 'admin.php?page=' . $tab['slug'] ) ),
				esc_html( $tab['title'] )
			);
		}

		echo '</nav></div>';
	}

	/**
	 * Адрес открытой страницы плагина.
	 */
	private function page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- параметр только сравнивается со списком своих страниц.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}
}
