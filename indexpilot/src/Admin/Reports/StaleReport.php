<?php
/**
 * Отчёт «Без жизни».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\HelpTip;
use IndexPilot\Admin\SearchDataPage;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Game\MaterialType;
use IndexPilot\Health\StalePages;
use IndexPilot\Links\Schema as LinkSchema;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\SearchData\Module;
use IndexPilot\SearchData\WpSite;

/**
 * Страницы без жизни: старше 90 дней, за 90 дней ни одного клика и почти нет показов, нет входящих ссылок.
 * Для каждой — причина, совет и похожая страница; действие одной кнопкой: оставить, закрыть от индексации
 * или перенести на похожую (редирект и запись в корзину). Колоды и короткие новости проблемой из-за длины не считаются.
 */
final class StaleReport {

	public const SLUG = 'indexpilot-stale';

	private const CAP   = 'manage_options';
	private const NONCE = 'indexpilot_stale';
	private const LIMIT = 200;

	/**
	 * Сообщение после действия.
	 *
	 * @var string
	 */
	private string $notice = '';

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
		$hook = add_submenu_page( SettingsPage::SLUG, __( 'IndexPilot — страницы без жизни', 'indexpilot' ), __( 'Без жизни', 'indexpilot' ), self::CAP, self::SLUG, array( $this, 'render' ) );

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Кнопки строки: оставить, закрыть от индексации, перенести на похожую.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_stale_action'], $_POST['post_id'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action  = sanitize_key( wp_unslash( $_POST['indexpilot_stale_action'] ) );
		$post_id = absint( $_POST['post_id'] );
		$target  = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( 'keep' === $action ) {
			update_post_meta( $post_id, WpSite::KEEP_META, time() );
			$this->notice = __( 'Страница оставлена: в отчёт она больше не попадёт.', 'indexpilot' );
		}

		if ( 'noindex' === $action ) {
			update_post_meta( $post_id, MetaKeys::NOINDEX, '1' );
			update_post_meta( $post_id, WpSite::KEEP_META, time() );
			$this->notice = __( 'Страница закрыта от индексации.', 'indexpilot' );
		}

		if ( 'merge' === $action && $target > 0 && 'publish' === get_post_status( $target ) && current_user_can( 'delete_post', $post_id ) ) {
			$rule = $this->module->plugin()->redirect_manager()->save(
				array(
					'source' => (string) get_permalink( $post_id ),
					'target' => (string) get_permalink( $target ),
					'code'   => 301,
					'note'   => 'страница без жизни',
				)
			);

			if ( is_array( $rule ) ) {
				wp_trash_post( $post_id );
				$this->notice = __( 'Поставлен редирект на похожую страницу, запись перенесена в корзину. Полезное из неё перенесите в похожую.', 'indexpilot' );
			} else {
				$this->notice = __( 'Редирект поставить не удалось: проверьте правила в разделе «Редиректы».', 'indexpilot' );
			}
		}
	}

	/**
	 * Страницы-кандидаты с данными за 90 дней.
	 *
	 * @param array<int, array{clicks: int, impressions: int}> $search Клики и показы записей.
	 * @return list<array<string, mixed>>
	 */
	private function pages( array $search ): array {
		$db      = $this->module->plugin()->db();
		$site    = $this->module->site();
		$types   = $this->module->plugin()->audit()->types();
		$kept    = $site->meta( WpSite::KEEP_META );
		$terms   = $site->terms( array( 'category' ) );
		$inbound = array();

		if ( LinkSchema::VERSION === (string) get_option( LinkSchema::OPTION, '' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- отчёт в админке по собственной таблице.
			foreach ( (array) $db->get_results( $db->prepare( "SELECT target_post_id, COUNT(*) AS links FROM %i WHERE type = 'internal' AND nofollow = 0 AND target_post_id > 0 GROUP BY target_post_id", LinkSchema::table( $db ) ), ARRAY_A ) as $row ) {
				$inbound[ (int) $row['target_post_id'] ] = (int) $row['links'];
			}
		}

		$pages = array();

		foreach ( $site->posts( $types ) as $post ) {
			$pages[] = array(
				'id'          => $post['id'],
				'title'       => $post['title'],
				'type'        => MaterialType::detect( $post['title'], $terms[ $post['id'] ]['category'] ?? array(), '', $post['type'] ),
				'published'   => (int) strtotime( $post['date'] . ' UTC' ),
				'words'       => 0,
				'clicks'      => $search[ $post['id'] ]['clicks'] ?? 0,
				'impressions' => $search[ $post['id'] ]['impressions'] ?? 0,
				'inbound'     => $inbound[ $post['id'] ] ?? 0,
				'kept'        => isset( $kept[ $post['id'] ] ),
				'stems'       => $site->stems( $post['title'] ),
			);
		}

		return $pages;
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		echo '<div class="wrap" data-indexpilot-stale>';
		echo '<h1>' . esc_html__( 'IndexPilot — страницы без жизни', 'indexpilot' ) . ' ';
		HelpTip::render(
			__( 'для каждой страницы выберите действие: обновить (откройте запись), объединить с похожей (редирект), закрыть от индексации или оставить как есть. Колода или короткая новость — нормальная страница: если она нужна, нажмите «Оставить».', 'indexpilot' ),
			__( 'страницы, которые никто не находит и на которые никто не ссылается, тратят внимание поисковика и размывают тему сайта.', 'indexpilot' )
		);
		echo '</h1>';

		if ( '' !== $this->notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $this->notice ) . '</p></div>';
		}

		$search = $this->module->has_data() ? $this->module->store()->posts_90_days() : null;

		if ( null === $search ) {
			echo '<p data-indexpilot-stale-off>' . esc_html__( 'Нужны данные Google Search Console за 90 дней: без них не видно, у каких страниц нет кликов. Подключите их:', 'indexpilot' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . SearchDataPage::SLUG ) ) . '">' . esc_html__( 'Данные поиска', 'indexpilot' ) . '</a>.</p></div>';

			return;
		}

		echo '<p>' . esc_html( sprintf( /* translators: 1: дней, 2: показов. */ __( 'Опубликована больше %1$d дней назад, за 90 дней ни одного клика и меньше %2$d показов, на страницу не ссылается ни одна запись. Длина текста причиной не считается.', 'indexpilot' ), StalePages::AGE_DAYS, StalePages::MAX_IMPRESSIONS ) ) . '</p>';

		$advice = array(
			'merge'   => __( 'Объединить с похожей: перенесите полезное и поставьте редирект', 'indexpilot' ),
			'update'  => __( 'Обновить: показы есть, но кликов нет — перепишите заголовок, описание и дополните текст', 'indexpilot' ),
			'noindex' => __( 'Колода или новость: короткий текст — не проблема; если страница устарела, закройте её от индексации, если нужна — оставьте', 'indexpilot' ),
			'remove'  => __( 'Закрыть от индексации или удалить с редиректом на близкую страницу', 'indexpilot' ),
		);
		$lines  = array();

		foreach ( array_slice( StalePages::find( $this->pages( $search ), time() ), 0, self::LIMIT ) as $page ) {
			$similar = (int) $page['similar'];
			$buttons = $this->button( 'keep', __( 'Оставить', 'indexpilot' ), (int) $page['id'] ) . $this->button( 'noindex', __( 'Закрыть от индексации', 'indexpilot' ), (int) $page['id'] );

			if ( $similar > 0 ) {
				$buttons .= $this->button( 'merge', __( 'Редирект на похожую', 'indexpilot' ), (int) $page['id'], $similar );
			}

			$lines[] = array(
				Module::object_link( 'post', (int) $page['id'], '' ) . '<br><span class="description">' . esc_html( MaterialType::label( (string) $page['type'] ) ) . '</span>',
				esc_html( (string) $page['reason'] ),
				esc_html( $advice[ $page['advice'] ] ) . ( $similar > 0 ? '<br>' . esc_html__( 'Похожая:', 'indexpilot' ) . ' ' . Module::object_link( 'post', $similar, '' ) : '' ),
				$buttons,
			);
		}

		QueriesReport::table( 'stale', array( __( 'Страница', 'indexpilot' ), __( 'Причина', 'indexpilot' ), __( 'Совет', 'indexpilot' ), __( 'Действие', 'indexpilot' ) ), $lines );
		echo '</div>';
	}

	/**
	 * Кнопка действия строки.
	 *
	 * @param string $action  Действие.
	 * @param string $label   Надпись.
	 * @param int    $post_id Запись.
	 * @param int    $target  Похожая запись.
	 */
	private function button( string $action, string $label, int $post_id, int $target = 0 ): string {
		return '<form method="post" style="display:inline-block;margin:0 4px 4px 0">'
			. wp_nonce_field( self::NONCE, '_wpnonce', true, false )
			. '<input type="hidden" name="indexpilot_stale_action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">'
			. ( $target > 0 ? '<input type="hidden" name="target_id" value="' . esc_attr( (string) $target ) . '">' : '' )
			. '<button type="submit" class="button button-small" data-indexpilot-stale-button="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button></form>';
	}
}
