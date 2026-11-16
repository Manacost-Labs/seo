<?php
/**
 * Страница автора и отметка «Проверил» на сайте.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Authors;

use IndexPilot\Game\GameLexicon;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Schema\Graph;
use IndexPilot\Schema\IdFactory;

/**
 * Пересчитывает сводку автора и версию отметки «Проверил» при сохранении записи, выводит сводку на странице автора
 * и добавляет в разметку knowsAbout (страница автора) и reviewedBy (страница проверенного материала).
 * На страницах сайта всё читается из уже загруженной меты — запросов к базе не добавляется.
 */
final class AuthorPages {

	/**
	 * Сколько материалов автора читать при пересчёте.
	 */
	private const LIMIT = 500;

	/**
	 * Конструктор.
	 *
	 * @param AuthorProfile $profiles Профили авторов.
	 * @param GameLexicon   $lexicon  Словарь игры.
	 */
	public function __construct( private AuthorProfile $profiles, private GameLexicon $lexicon ) {
	}

	/**
	 * Подключает пересчёт при сохранении и шорткод сводки. Вызывается на init.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'after_save' ), 40, 4 );
		add_shortcode( 'indexpilot_author_summary', fn(): string => $this->html( (int) get_queried_object_id() ) );
	}

	/**
	 * Подключает вывод на странице сайта. Вызывается на template_redirect.
	 */
	public function register_front(): void {
		if ( is_author() ) {
			add_filter( 'get_the_archive_description', array( $this, 'description' ), 20 );
		}

		add_action( 'indexpilot_schema_graph', array( $this, 'schema' ), 15, 2 );
	}

	/**
	 * После сохранения записи: сводка автора (и прежнего автора) и состояние отметки «Проверил».
	 *
	 * @param int           $post_id     ID записи.
	 * @param \WP_Post|null $post        Запись.
	 * @param bool          $update      Обновление ли.
	 * @param \WP_Post|null $post_before Запись до сохранения.
	 */
	public function after_save( $post_id, $post = null, $update = false, $post_before = null ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$this->update_review( $post );

		$authors = array( (int) $post->post_author );

		if ( $post_before instanceof \WP_Post ) {
			$authors[] = (int) $post_before->post_author;
		}

		// Сводка меняется, только если запись опубликована сейчас или была опубликована раньше.
		if ( 'publish' === $post->post_status || ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) ) {
			foreach ( array_unique( array_filter( $authors ) ) as $author ) {
				$this->refresh( $author );
			}
		}
	}

	/**
	 * Версия отметки «Проверил»: подтверждение из панели записи получает отпечаток текущей версии,
	 * а после правки состава колоды или выводов отметка снимается — «нужна повторная проверка».
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function update_review( \WP_Post $post ): void {
		$reviewer = (int) get_post_meta( $post->ID, Review::REVIEWER, true );

		if ( $reviewer <= 0 ) {
			return;
		}

		$stored  = (string) get_post_meta( $post->ID, Review::VERSION, true );
		$current = self::fingerprint( $post );

		if ( \IndexPilot\Admin\ReviewBox::CONFIRM === $stored ) {
			update_post_meta( $post->ID, Review::VERSION, $current );
			update_post_meta( $post->ID, Review::DATE, gmdate( 'Y-m-d' ) );
		} elseif ( '' !== $stored && ! hash_equals( $stored, $current ) ) {
			delete_post_meta( $post->ID, Review::VERSION );
		}
	}

	/**
	 * Отпечаток проверяемой версии записи.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public static function fingerprint( \WP_Post $post ): string {
		$codes = array();

		foreach ( array( '_deck_code', '_hs_deck_code' ) as $key ) {
			$codes[] = (string) get_post_meta( $post->ID, $key, true );
		}

		return Review::fingerprint( (string) $post->post_content, $codes, (string) get_post_meta( $post->ID, MetaKeys::ANSWER, true ) );
	}

	/**
	 * Пересчитывает сводку автора одним запросом и хранит её в мете пользователя.
	 *
	 * @param int $user_id ID автора.
	 * @return array<string, mixed>
	 */
	public function refresh( int $user_id ): array {
		global $wpdb;

		$types = array();

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type && 'page' !== $type && is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}

		$rows = array();

		if ( array() !== $types ) {
			$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- одна выборка при сохранении записи; список IN собран из заполнителей.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_modified, p.comment_count, m.meta_value AS cornerstone FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_author = %d AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($in) ORDER BY p.post_modified DESC LIMIT %d",
					array_merge( array( MetaKeys::CORNERSTONE, $user_id ), $types, array( self::LIMIT ) )
				),
				ARRAY_A
			);
			// phpcs:enable
		}

		$posts = array();

		foreach ( $rows as $row ) {
			$posts[] = array(
				'title'    => wp_strip_all_tags( (string) $row['post_title'] ),
				'url'      => (string) $row['ID'],
				'modified' => (string) $row['post_modified'],
				// Опорный материал важнее обсуждаемого.
				'weight'   => ( '1' === (string) $row['cornerstone'] ? 100000 : 0 ) + (int) $row['comment_count'],
			);
		}

		/**
		 * Игра, о которой пишут авторы сайта: первая тема в knowsAbout.
		 *
		 * @param string $game Название игры.
		 */
		$summary = AuthorSummary::build( $posts, $this->lexicon, (string) apply_filters( 'indexpilot_author_game', 'Hearthstone' ) );

		foreach ( $summary['best'] as $index => $item ) {
			$summary['best'][ $index ]['url'] = (string) get_permalink( (int) $item['url'] );
		}

		update_user_meta( $user_id, AuthorSummary::META, $summary );

		return $summary;
	}

	/**
	 * Сохранённая сводка автора.
	 *
	 * @param int $user_id ID автора.
	 * @return array{count: int, updated: string, best: list<array{title: string, url: string}>, knows_about: list<string>}|null
	 */
	public static function summary( int $user_id ): ?array {
		$summary = $user_id > 0 ? get_user_meta( $user_id, AuthorSummary::META, true ) : '';

		return is_array( $summary ) && isset( $summary['count'], $summary['best'], $summary['knows_about'] ) ? $summary : null;
	}

	/**
	 * Сводка автора; если её ещё нет (автор не сохранял записей после установки плагина), она считается один раз.
	 *
	 * @param int $user_id ID автора.
	 * @return array<string, mixed>|null
	 */
	private function ensure( int $user_id ): ?array {
		$summary = self::summary( $user_id );

		if ( null === $summary && $user_id > 0 && get_userdata( $user_id ) instanceof \WP_User ) {
			$this->refresh( $user_id );
			$summary = self::summary( $user_id );
		}

		return $summary;
	}

	/**
	 * Описание архива автора со сводкой.
	 *
	 * @param string $description Описание архива.
	 */
	public function description( $description ): string {
		// Если тема уже вывела сводку шорткодом, второй раз не добавляем.
		if ( str_contains( (string) $description, 'indexpilot-author-summary' ) ) {
			return (string) $description;
		}

		return (string) $description . $this->html( (int) get_queried_object_id() );
	}

	/**
	 * HTML сводки: число материалов, последнее обновление, лучшие материалы.
	 *
	 * @param int $user_id ID автора.
	 */
	public function html( int $user_id ): string {
		$summary = is_author() ? $this->ensure( $user_id ) : null;

		if ( null === $summary || $summary['count'] < 1 ) {
			return '';
		}

		$updated = '' !== (string) $summary['updated'] ? mysql2date( (string) get_option( 'date_format' ), (string) $summary['updated'] ) : '';
		$html    = '<div class="indexpilot-author-summary"><p>';
		/* translators: %d: число материалов. */
		$html .= esc_html( sprintf( _n( '%d материал', '%d материалов', (int) $summary['count'], 'indexpilot' ), (int) $summary['count'] ) );

		if ( '' !== $updated ) {
			/* translators: %s: дата. */
			$html .= ' · ' . esc_html( sprintf( __( 'последнее обновление %s', 'indexpilot' ), $updated ) );
		}

		$html .= '</p>';

		if ( array() !== $summary['best'] ) {
			$html .= '<p><strong>' . esc_html__( 'Лучшие материалы', 'indexpilot' ) . '</strong></p><ul>';

			foreach ( $summary['best'] as $item ) {
				if ( '' !== (string) $item['url'] ) {
					$html .= '<li><a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( (string) $item['title'] ) . '</a></li>';
				}
			}

			$html .= '</ul>';
		}

		return $html . '</div>';
	}

	/**
	 * Разметка: knowsAbout у автора на его странице, reviewedBy и lastReviewed у проверенного материала.
	 *
	 * @param mixed $graph   Граф страницы.
	 * @param mixed $context Контекст страницы.
	 */
	public function schema( $graph, $context ): void {
		if ( ! $graph instanceof Graph || ! $context instanceof Context ) {
			return;
		}

		if ( Context::AUTHOR === $context->type ) {
			$summary = $this->ensure( $context->object_id );
			$person  = $graph->node( IdFactory::person( home_url( '/' ), get_author_posts_url( $context->object_id ) ) );

			if ( null !== $summary && null !== $person && array() !== $summary['knows_about'] ) {
				$graph->add( $person + array( 'knowsAbout' => array_values( array_map( 'strval', $summary['knows_about'] ) ) ) );
			}

			return;
		}

		if ( Context::POST !== $context->type ) {
			return;
		}

		$reviewer = (int) get_post_meta( $context->object_id, Review::REVIEWER, true );
		$version  = (string) get_post_meta( $context->object_id, Review::VERSION, true );
		$page     = $graph->first( 'WebPage' );

		// Отметка действует, только пока проверенная версия не менялась; под паролем разметки проверки нет.
		if ( $reviewer <= 0 || '' === $version || \IndexPilot\Admin\ReviewBox::CONFIRM === $version || null === $page || post_password_required( $context->object_id ) ) {
			return;
		}

		$profile = $this->profiles->get( $reviewer );

		if ( '' === $profile['name'] ) {
			return;
		}

		$id = IdFactory::person( home_url( '/' ), '' !== $profile['url'] ? $profile['url'] : $profile['name'] );

		if ( null === $graph->node( $id ) ) {
			$graph->add(
				array(
					'@type'  => 'Person',
					'@id'    => $id,
					'name'   => $profile['name'],
					'url'    => $profile['url'],
					'sameAs' => $profile['same_as'],
				)
			);
		}

		$graph->add(
			$page + array(
				'reviewedBy'   => array( '@id' => $id ),
				'lastReviewed' => (string) get_post_meta( $context->object_id, Review::DATE, true ),
			)
		);
	}
}
