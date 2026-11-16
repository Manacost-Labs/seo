<?php
/**
 * Индекс заголовков сайта для подсказок ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

use IndexPilot\Game\SiteGames;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Redirects\RedirectedPosts;

/**
 * Собирает заголовки страниц, на которые стоит ссылаться, и подбирает из них подходящие к тексту записи.
 * В индекс не входят закрытые от индексации и перенаправленные страницы. Индекс пересобирается раз в несколько часов.
 */
final class SuggestionIndex {

	public const OPTION = 'indexpilot_link_index';

	private const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Конструктор.
	 *
	 * @param \wpdb           $db         База WordPress.
	 * @param Suggester       $suggester  Подбор по основам слов.
	 * @param RedirectedPosts $redirected Записи под редиректом.
	 * @param string          $signature  Отпечаток игрового словаря: индекс, построенный с другим словарём, не годится.
	 * @param SiteGames|null  $games      Игры сайта: подсказки — только на страницы игры записи; null — без разделения.
	 */
	public function __construct(
		private \wpdb $db,
		private Suggester $suggester,
		private RedirectedPosts $redirected,
		private string $signature = '',
		private ?SiteGames $games = null
	) {
	}

	/**
	 * Игры страниц индекса.
	 *
	 * @var array<string, string>
	 */
	private array $page_games = array();

	/**
	 * Страницы, на которые стоит сослаться из текста.
	 *
	 * @param int    $post_id ID записи, для которой подбираются ссылки.
	 * @param string $html    Текст записи.
	 * @param int    $limit   Сколько страниц вернуть.
	 * @return list<array{title: string, url: string}>
	 */
	public function suggest( int $post_id, string $html, int $limit = 5 ): array {
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$linked = array();

		// Страницы, на которые текст уже ссылается, повторно не предлагаются.
		foreach ( Extractor::extract( $html, $home ) as $link ) {
			if ( 'internal' === $link['type'] ) {
				$linked[ untrailingslashit( rawurldecode( (string) wp_parse_url( $link['url'], PHP_URL_PATH ) ) ) ] = true;
			}
		}

		$suggestions = array();

		// Запись о World of Warcraft получает подсказки только на страницы своей игры, и наоборот.
		$game = null !== $this->games && $this->games->active() && $post_id > 0 ? $this->games->post_game( $post_id ) : '';

		foreach ( $this->suggester->suggest( $this->index(), $html, array( 'p' . $post_id ), $limit * 3, $game ) as $item ) {
			$object_id = (int) substr( $item['id'], 1 );
			$url       = 'p' === $item['id'][0] ? get_permalink( $object_id ) : get_term_link( $object_id );

			if ( ! is_string( $url ) || isset( $linked[ untrailingslashit( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ] ) ) {
				continue;
			}

			$suggestions[] = array(
				'title' => $item['title'],
				'url'   => $url,
			);

			if ( count( $suggestions ) >= $limit ) {
				break;
			}
		}

		return $suggestions;
	}

	/**
	 * Индекс из кэша или построенный заново.
	 *
	 * @param bool $rebuild Построить заново, даже если кэш свежий.
	 * @return array<string, mixed>
	 */
	public function index( bool $rebuild = false ): array {
		$saved = get_option( self::OPTION, array() );

		if ( ! $rebuild && is_array( $saved ) && is_array( $saved['index'] ?? null ) && time() - (int) ( $saved['built'] ?? 0 ) < self::TTL && (string) ( $saved['signature'] ?? '' ) === $this->signature ) {
			return $saved['index'];
		}

		$index = $this->suggester->build( $this->titles(), $this->page_games );

		update_option(
			self::OPTION,
			array(
				'built'     => time(),
				'index'     => $index,
				'signature' => $this->signature,
			),
			false
		);

		return $index;
	}

	/**
	 * Заголовки страниц-кандидатов: «p123» — запись, «t45» — термин.
	 *
	 * @return array<string, string>
	 */
	private function titles(): array {
		/**
		 * Типы записей, на которые предлагаются ссылки. Колоды не входят: их названия повторяются сотни раз,
		 * вместо них предлагаются страницы архетипов.
		 *
		 * @param list<string> $types Типы записей.
		 */
		$types = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'indexpilot_link_suggestion_types', array( 'post', 'page' ) ) ), 'post_type_exists' ) );

		/**
		 * Таксономии, на архивы терминов которых предлагаются ссылки.
		 *
		 * @param list<string> $taxonomies Таксономии.
		 */
		$taxonomies = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'indexpilot_link_suggestion_taxonomies', array( 'deck_archetype' ) ) ), 'taxonomy_exists' ) );

		$titles = array();

		if ( array() !== $types ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- в запрос подставляется только список заполнителей %s по числу типов записей.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- два столбца по всем записям; результат кэшируется в опции.
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT p.ID, p.post_title, g.meta_value AS game FROM {$this->db->posts} p
					LEFT JOIN {$this->db->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = '1'
					LEFT JOIN {$this->db->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s
					WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ( {$placeholders} ) AND m.meta_id IS NULL",
					array_merge( array( MetaKeys::NOINDEX, SiteGames::META ), $types )
				),
				ARRAY_A
			);
			// phpcs:enable

			$redirected = $this->redirected->map( false );

			foreach ( (array) $rows as $row ) {
				if ( ! isset( $redirected[ (int) $row['ID'] ] ) ) {
					$titles[ 'p' . (int) $row['ID'] ] = wp_strip_all_tags( (string) $row['post_title'] );

					// Запись без поля игры относится к игре сайта по умолчанию.
					$this->page_games[ 'p' . (int) $row['ID'] ] = '' !== (string) ( $row['game'] ?? '' ) ? (string) $row['game'] : SiteGames::DEFAULT_GAME;
				}
			}
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( '1' !== (string) get_term_meta( $term->term_id, MetaKeys::NOINDEX, true ) ) {
					$titles[ 't' . (int) $term->term_id ]           = (string) $term->name;
					$this->page_games[ 't' . (int) $term->term_id ] = null !== $this->games ? $this->games->term_game( (int) $term->term_id, $taxonomy ) : SiteGames::DEFAULT_GAME;
				}
			}
		}

		return $titles;
	}
}
