<?php
/**
 * Редиректы сами собой: при смене адреса записи или термина и при удалении записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

use IndexPilot\Admin\HelpTip;

/**
 * При смене адреса опубликованной записи или термина создаёт редирект 301 со старого адреса на новый.
 * При удалении опубликованной записи показывает уведомление: куда вести её адрес — на другую страницу,
 * ответить «удалена навсегда» (410) или ничего не делать.
 */
final class AutoRedirect {

	/**
	 * Удалённые записи, по которым ещё не решили, что делать с адресом. Хранятся у пользователя:
	 * его мета загружается вместе с проверкой прав, отдельного запроса к базе нет.
	 */
	public const USER_META = '_indexpilot_deleted_posts';

	private const ACTION = 'indexpilot_deleted_post';
	private const KEEP   = 10;

	/**
	 * Адреса терминов до сохранения: номер термина → адрес.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $term_links = array();

	/**
	 * @param callable(): Manager $manager Создаёт менеджер редиректов (таблица читается только при сохранении).
	 */
	public function __construct( private $manager ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'edit_terms', array( $this, 'before_term_update' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'term_updated' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'post_deleted' ) );
		add_action( 'before_delete_post', array( $this, 'post_deleted' ) );
		add_action( 'untrashed_post', array( $this, 'forget' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'notice' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'admin_post_' . self::ACTION, array( $this, 'decide' ) );
		}
	}

	/**
	 * Адрес опубликованной записи изменился: редирект со старого адреса.
	 *
	 * @param int      $post_id Номер записи.
	 * @param \WP_Post $after   Запись после сохранения.
	 * @param \WP_Post $before  Запись до сохранения.
	 */
	public function post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}

		if ( wp_is_post_revision( $after ) || ! is_post_type_viewable( $after->post_type ) || ( $before->post_name === $after->post_name && $before->post_parent === $after->post_parent ) ) {
			return;
		}

		$old = (string) get_permalink( $before );
		$new = (string) get_permalink( $after );

		if ( '' !== $old && '' !== $new && $old !== $new ) {
			( $this->manager )()->auto( $old, $new );
		}
	}

	/**
	 * Запоминает адреса термина и его дочерних терминов до сохранения.
	 *
	 * @param int    $term_id  Номер термина.
	 * @param string $taxonomy Таксономия.
	 */
	public function before_term_update( $term_id, $taxonomy ): void {
		$this->term_links[ (int) $term_id ] = $this->term_links( (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Адрес термина изменился: редиректы со старых адресов термина и его дочерних терминов.
	 *
	 * @param int    $term_id  Номер термина.
	 * @param int    $tt_id    Номер в таксономии.
	 * @param string $taxonomy Таксономия.
	 */
	public function term_updated( $term_id, $tt_id, $taxonomy = '' ): void {
		$before = $this->term_links[ (int) $term_id ] ?? array();
		unset( $this->term_links[ (int) $term_id ] );

		if ( array() === $before ) {
			return;
		}

		$after   = $this->term_links( (int) $term_id, (string) $taxonomy );
		$manager = null;

		foreach ( $before as $id => $old ) {
			$new = $after[ $id ] ?? '';

			if ( '' !== $new && $old !== $new ) {
				$manager = $manager ?? ( $this->manager )();
				$manager->auto( $old, $new );
			}
		}
	}

	/**
	 * Опубликованная запись удаляется: запоминаем её адрес, чтобы спросить, куда его вести.
	 *
	 * @param int $post_id Номер записи.
	 */
	public function post_deleted( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || 0 === get_current_user_id() ) {
			return;
		}

		$list = $this->pending();

		$list[ (int) $post->ID ] = array(
			'url'    => (string) get_permalink( $post ),
			'title'  => (string) get_the_title( $post ),
			'target' => $this->fallback( $post ),
		);

		update_user_meta( get_current_user_id(), self::USER_META, array_slice( $list, -self::KEEP, null, true ) );
	}

	/**
	 * Запись восстановили из корзины: спрашивать больше не о чем.
	 *
	 * @param int $post_id Номер записи.
	 */
	public function forget( $post_id ): void {
		$list = $this->pending();

		if ( isset( $list[ (int) $post_id ] ) ) {
			unset( $list[ (int) $post_id ] );
			$this->save_pending( $list );
		}
	}

	/**
	 * Стили значка подсказки — только пока уведомление об удалённой записи показывается.
	 */
	public function enqueue(): void {
		if ( current_user_can( 'manage_options' ) && array() !== $this->pending() ) {
			wp_enqueue_style( 'indexpilot-panel', plugins_url( 'assets/css/panel.css', INDEXPILOT_FILE ), array(), \IndexPilot\Plugin::VERSION );
		}
	}

	/**
	 * Уведомление в админке: куда вести адрес удалённой записи.
	 */
	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pending = $this->pending();

		if ( array() === $pending ) {
			return;
		}

		$count = count( $pending );

		echo '<div class="notice notice-warning indexpilot-deleted-notice">';

		if ( 1 === $count ) {
			$item = reset( $pending );
			/* translators: 1: название записи, 2: её адрес. */
			echo '<p>' . esc_html( sprintf( __( 'Запись «%1$s» удалена. Её адрес %2$s теперь отвечает «не найдено». Куда вести посетителей и поисковики?', 'indexpilot' ), $item['title'], \IndexPilot\Admin\ReadableUrl::of( $item['url'] ) ) ) . '</p>';
		} else {
			/* translators: %d: число удалённых записей. */
			echo '<p><strong>' . esc_html( sprintf( __( 'Удалено записей: %d.', 'indexpilot' ), $count ) ) . '</strong> ' . esc_html__( 'Их адреса теперь отвечают «не найдено». Для каждой выберите, куда вести посетителей и поисковики.', 'indexpilot' ) . '</p>';
		}

		$shown = 0;

		foreach ( $pending as $post_id => $item ) {
			if ( 3 === $shown ) {
				/* translators: %d: сколько ещё записей ждут решения. */
				echo '<details class="indexpilot-deleted-more"><summary>' . esc_html( sprintf( __( 'Показать ещё %d', 'indexpilot' ), $count - $shown ) ) . '</summary>';
			}

			$this->notice_row( (int) $post_id, $item, $count > 1 );
			++$shown;
		}

		if ( $shown > 3 ) {
			echo '</details>';
		}

		echo '</div>';
	}

	/**
	 * Строка уведомления об одной удалённой записи: своя форма с выбором действия.
	 *
	 * @param int                                               $post_id   Номер записи.
	 * @param array{url: string, title: string, target: string} $item      Запись, ждущая решения.
	 * @param bool                                              $with_name Показать название и адрес записи в строке.
	 */
	private function notice_row( int $post_id, array $item, bool $with_name ): void {
		echo '<form class="indexpilot-deleted-row" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-indexpilot-deleted-post="' . esc_attr( (string) $post_id ) . '">';

		if ( $with_name ) {
			$url = \IndexPilot\Admin\ReadableUrl::of( $item['url'] );
			echo '<p class="indexpilot-deleted-name"><strong>' . esc_html( $item['title'] ) . '</strong> <span class="indexpilot-deleted-url" title="' . esc_attr( $url ) . '">' . esc_html( $url ) . '</span></p>';
		}

		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '">';
		echo '<p class="indexpilot-deleted-actions"><label class="indexpilot-deleted-target"><span>' . esc_html__( 'Новый адрес:', 'indexpilot' ) . '</span> <input type="text" class="regular-text" name="target" value="' . esc_attr( $item['target'] ) . '"></label> ';
		HelpTip::render( __( 'адрес похожей страницы или рубрики, например /gajdy/. Подставлена рубрика удалённой записи.', 'indexpilot' ), __( 'посетитель по старой ссылке попадёт на близкую страницу, а поисковик перенесёт на неё вес старой.', 'indexpilot' ) );
		echo ' <button type="submit" class="button button-primary" name="choice" value="redirect">' . esc_html__( 'Создать редирект', 'indexpilot' ) . '</button> ';
		echo '<button type="submit" class="button" name="choice" value="gone">' . esc_html__( 'Удалена навсегда (410)', 'indexpilot' ) . '</button> ';
		echo '<button type="submit" class="button-link" name="choice" value="skip">' . esc_html__( 'Ничего не делать', 'indexpilot' ) . '</button>';
		echo '</p></form>';
	}

	/**
	 * Выбор из уведомления.
	 */
	public function decide(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'indexpilot' ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$choice  = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : 'skip';
		$target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$list    = $this->pending();
		$item    = $list[ $post_id ] ?? null;
		$result  = 'skip';

		if ( null !== $item && in_array( $choice, array( 'redirect', 'gone' ), true ) ) {
			$saved  = ( $this->manager )()->save(
				array(
					'source' => $item['url'],
					'target' => $target,
					'code'   => 'gone' === $choice ? 410 : 301,
					'note'   => 'авто: запись удалена',
				)
			);
			$result = is_array( $saved ) ? 'saved' : 'error';
		}

		if ( 'error' !== $result ) {
			unset( $list[ $post_id ] );
			$this->save_pending( $list );
		}

		wp_safe_redirect( add_query_arg( 'indexpilot_deleted', $result, wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}

	/**
	 * Записи, ждущие решения.
	 *
	 * @return array<int, array{url: string, title: string, target: string}>
	 */
	public function pending(): array {
		$list = get_user_meta( get_current_user_id(), self::USER_META, true );

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Сохраняет список записей, ждущих решения.
	 *
	 * @param array<int, array<string, string>> $pending Список.
	 */
	private function save_pending( array $pending ): void {
		if ( array() === $pending ) {
			delete_user_meta( get_current_user_id(), self::USER_META );
		} else {
			update_user_meta( get_current_user_id(), self::USER_META, $pending );
		}
	}

	/**
	 * Цель по умолчанию — первая рубрика записи (у страниц — родитель или главная).
	 *
	 * @param \WP_Post $post Запись.
	 */
	private function fallback( \WP_Post $post ): string {
		if ( $post->post_parent > 0 ) {
			return (string) get_permalink( $post->post_parent );
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! is_taxonomy_hierarchical( $taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy );

			if ( is_array( $terms ) && array() !== $terms ) {
				$link = get_term_link( $terms[0] );

				return is_string( $link ) ? $link : home_url( '/' );
			}
		}

		return home_url( '/' );
	}

	/**
	 * Адреса термина и его дочерних терминов.
	 *
	 * @param int    $term_id  Номер термина.
	 * @param string $taxonomy Таксономия.
	 * @return array<int, string>
	 */
	private function term_links( int $term_id, string $taxonomy ): array {
		if ( '' === $taxonomy || ! is_taxonomy_viewable( $taxonomy ) ) {
			return array();
		}

		$ids   = is_taxonomy_hierarchical( $taxonomy ) ? array_map( 'intval', (array) get_term_children( $term_id, $taxonomy ) ) : array();
		$links = array();

		foreach ( array_merge( array( $term_id ), array_slice( $ids, 0, 200 ) ) as $id ) {
			clean_term_cache( $id, $taxonomy );
			$link = get_term_link( $id, $taxonomy );

			if ( is_string( $link ) ) {
				$links[ $id ] = $link;
			}
		}

		return $links;
	}
}
