<?php
/**
 * Экран журнала 404.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Redirects\TargetSuggester;
use IndexPilot\Redirects\WpNotFoundStore;

/**
 * Самые частые адреса, которые не открылись, с подсказкой цели и кнопкой «создать редирект».
 */
final class NotFoundPage {

	public const SLUG = 'indexpilot-404';

	private const CAP   = 'manage_options';
	private const NONCE = 'indexpilot_404';
	private const LIMIT = 100;

	/**
	 * Сообщение после действия.
	 *
	 * @var string
	 */
	private string $notice = '';

	public function __construct( private WpNotFoundStore $log, private \wpdb $db ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 12 );
	}

	/**
	 * Добавляет страницу в меню.
	 */
	public function add(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — журнал 404', 'indexpilot' ),
			__( 'Журнал 404', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Удаление строки и очистка журнала.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_404_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( 'clear' === sanitize_key( wp_unslash( $_POST['indexpilot_404_action'] ) ) ) {
			$this->log->clear();
			$this->notice = __( 'Журнал очищен.', 'indexpilot' );

			return;
		}

		$this->log->delete( isset( $_POST['row_id'] ) ? absint( wp_unslash( $_POST['row_id'] ) ) : 0 );
		$this->notice = __( 'Строка удалена.', 'indexpilot' );
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- поиск только читает данные.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$rows   = $this->log->top( $search, self::LIMIT );

		echo '<div class="wrap" data-indexpilot-404>';
		echo '<h1>' . esc_html__( 'IndexPilot — журнал 404', 'indexpilot' ) . '</h1>';

		if ( '' !== $this->notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $this->notice ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Адреса сайта, которые ответили «не найдено». Сверху — те, по которым приходят чаще. Если страница переехала, создайте редирект: посетитель попадёт куда нужно, поисковик перенесёт вес старого адреса. Файлы, служебные адреса и попытки взлома в журнал не попадают; хранятся последние 5 000 адресов.', 'indexpilot' ) . '</p>';

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><p class="search-box">';
		echo '<label for="indexpilot-404-search">' . esc_html__( 'Найти адрес', 'indexpilot' ) . '</label> <input type="search" id="indexpilot-404-search" name="s" value="' . esc_attr( $search ) . '"> ';
		submit_button( __( 'Найти', 'indexpilot' ), 'secondary', '', false );
		echo '</p></form>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Журнал пуст.', 'indexpilot' ) . '</p></div>';

			return;
		}

		$suggestions = $this->suggestions( array_column( $rows, 'url' ) );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Адрес', 'indexpilot' ), __( 'Обращений', 'indexpilot' ), __( 'Последнее', 'indexpilot' ), __( 'Откуда пришли', 'indexpilot' ), __( 'Похожая страница', 'indexpilot' ), __( 'Действия', 'indexpilot' ) ) as $title ) {
			echo '<th scope="col">' . esc_html( $title ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$target = $suggestions[ $row['url'] ] ?? '';
			$create = add_query_arg(
				array(
					'page'     => RedirectsPage::SLUG,
					'source'   => rawurlencode( $row['url'] ),
					'target'   => rawurlencode( $target ),
					'from_404' => rawurlencode( $row['url'] ),
				),
				admin_url( 'admin.php' )
			) . '#new';

			printf( '<tr data-indexpilot-404-row="%s">', esc_attr( $row['url'] ) );
			echo '<td><code>' . esc_html( $row['url'] ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( $row['hits'] ) ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( $row['last_seen'], 'd.m.Y H:i' ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $row['referrer'] ? $row['referrer'] : '—' ) . '</td>';
			echo '<td>' . ( '' !== $target ? '<a href="' . esc_url( $target ) . '" target="_blank" rel="noopener" data-indexpilot-suggestion>' . esc_html( $target ) . '</a>' : '—' ) . '</td><td>';
			echo '<a class="button button-small" href="' . esc_url( $create ) . '">' . esc_html__( 'Создать редирект', 'indexpilot' ) . '</a> ';
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( self::NONCE, '_wpnonce', true, true );
			echo '<input type="hidden" name="indexpilot_404_action" value="delete"><input type="hidden" name="row_id" value="' . esc_attr( (string) $row['id'] ) . '">';
			echo '<button type="submit" class="button-link button-link-delete">' . esc_html__( 'Убрать', 'indexpilot' ) . '</button></form></td></tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="indexpilot_404_action" value="clear">';
		submit_button( __( 'Очистить журнал', 'indexpilot' ), 'delete', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Подсказки цели: самая похожая опубликованная запись или рубрика для каждого адреса.
	 *
	 * @param list<string> $urls Адреса из журнала.
	 * @return array<string, string> Адрес → полный адрес похожей страницы.
	 */
	private function suggestions( array $urls ): array {
		$types = array_values( array_filter( get_post_types( array( 'public' => true ) ), static fn( string $type ): bool => 'attachment' !== $type && is_post_type_viewable( $type ) ) );

		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %s по числу типов записей.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один запрос ярлыков на экране админки.
		$posts = $this->db->get_results( $this->db->prepare( "SELECT ID, post_name FROM {$this->db->posts} WHERE post_status = 'publish' AND post_name <> '' AND post_type IN ( {$placeholders} ) ORDER BY post_date DESC LIMIT 5000", $types ), ARRAY_A );
		// phpcs:enable

		$candidates = array();

		foreach ( (array) $posts as $post ) {
			$candidates[ 'post:' . $post['ID'] ] = (string) $post['post_name'];
		}

		$terms = get_terms(
			array(
				'taxonomy'   => array_values( array_filter( get_taxonomies( array( 'public' => true ) ), 'is_taxonomy_viewable' ) ),
				'hide_empty' => true,
				'fields'     => 'id=>slug',
				'number'     => 2000,
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term_id => $slug ) {
			$candidates[ 'term:' . $term_id ] = (string) $slug;
		}

		$result = array();

		foreach ( $urls as $url ) {
			$ref = TargetSuggester::suggest( $url, $candidates );

			if ( '' === $ref ) {
				continue;
			}

			list( $kind, $id ) = explode( ':', $ref );
			$link              = 'post' === $kind ? get_permalink( (int) $id ) : get_term_link( (int) $id );

			if ( is_string( $link ) && '' !== $link ) {
				$result[ $url ] = $link;
			}
		}

		return $result;
	}
}
