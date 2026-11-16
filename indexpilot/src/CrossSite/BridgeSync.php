<?php
/**
 * Мост между сайтами: обмен по расписанию и вывод блока.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\CrossSite;

use IndexPilot\Analysis\KeywordUsage;
use IndexPilot\Game\Module as GameModule;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Promo\Bridge;

/**
 * Раз в час очередная пачка записей спрашивает второй сайт, есть ли там материал по тому же основному ключу
 * или архетипу; ответ хранится в мете записи. На странице записи блок печатается из этой меты — мета уже
 * загружена вместе с записью, поэтому запросов к базе и к сети нет. Если второй сайт не ответил, блока нет.
 */
final class BridgeSync {

	public const HOURLY = 'indexpilot_bridge_batch';
	public const CURSOR = 'indexpilot_bridge_cursor';
	public const ROUTE  = '/bridge';

	private const BATCH    = 25;
	private const FAILURES = 3;

	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( self::HOURLY, array( $this, 'refresh_batch' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'the_content', array( $this, 'append' ), 30 );

		add_action(
			'admin_init',
			function (): void {
				if ( $this->client()->configured() ) {
					if ( ! wp_next_scheduled( self::HOURLY ) ) {
						wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
					}
				} elseif ( wp_next_scheduled( self::HOURLY ) ) {
					wp_clear_scheduled_hook( self::HOURLY );
				}
			}
		);
	}

	/**
	 * Клиент второго сайта.
	 */
	private function client(): PartnerClient {
		return new PartnerClient( $this->plugin->settings() );
	}

	/**
	 * Добавляет блок после текста записи. Только сохранённые данные: сеть здесь не используется.
	 *
	 * @param string $content Текст.
	 */
	public function append( $content ): string {
		$content = (string) $content;

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || ! $this->client()->configured() ) {
			return $content;
		}

		return $content . Bridge::html( get_post_meta( (int) get_the_ID(), Bridge::META, true ) );
	}

	/**
	 * Маршрут для второго сайта: страницы с тем же ключом и страница архетипа.
	 */
	public function register_route(): void {
		register_rest_route(
			'indexpilot/v1',
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'answer' ),
				'permission_callback' => fn( \WP_REST_Request $request ): bool => KeywordAccess::allows(
					(string) $this->plugin->settings()->general( 'partner_site_key' ),
					(string) $request->get_header( KeywordAccess::HEADER )
				),
				'args'                => array(
					'keyword'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'archetype' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Ответ второму сайту.
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response
	 */
	public function answer( \WP_REST_Request $request ) {
		$keyword = trim( (string) $request['keyword'] );
		$name    = trim( (string) $request['archetype'] );
		$pages   = array();

		if ( '' !== $keyword ) {
			foreach ( ( new KeywordUsage( $this->plugin->db(), $this->plugin->analyzer()->matcher() ) )->posts( $keyword, 0, 3, true ) as $post_id ) {
				$pages[] = array(
					'title' => wp_strip_all_tags( get_the_title( $post_id ) ),
					'url'   => (string) get_permalink( $post_id ),
				);
			}
		}

		$archetype = null;

		if ( '' !== $name && taxonomy_exists( GameModule::ARCHETYPE ) ) {
			$term = get_term_by( 'name', $name, GameModule::ARCHETYPE );
			$link = $term instanceof \WP_Term && $term->count > 0 ? get_term_link( $term ) : '';

			if ( is_string( $link ) && '' !== $link ) {
				$archetype = array(
					'title' => $term->name,
					'url'   => $link,
				);
			}
		}

		return rest_ensure_response(
			array(
				'keyword'   => $pages,
				'archetype' => $archetype,
			)
		);
	}

	/**
	 * Очередная пачка записей: спрашивает второй сайт и сохраняет ответ в мету записи.
	 *
	 * @param int $limit Сколько записей.
	 * @return array{checked: int, failed: int, done: bool}
	 */
	public function refresh_batch( int $limit = self::BATCH ): array {
		$client = $this->client();
		$result = array(
			'checked' => 0,
			'failed'  => 0,
			'done'    => true,
		);

		if ( ! $client->configured() ) {
			return $result;
		}

		$last  = (int) get_option( self::CURSOR, 0 );
		$db    = $this->plugin->db();
		$types = $this->plugin->audit()->types();
		$in    = implode( ', ', array_fill( 0, max( 1, count( $types ) ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- только список заполнителей %s.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- фоновый обход пачками.
		$posts = array_map( 'intval', (array) $db->get_col( $db->prepare( "SELECT ID FROM {$db->posts} WHERE post_status = 'publish' AND post_type IN ( {$in} ) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( array() === $types ? array( 'post' ) : $types, array( $last, $limit ) ) ) ) );
		// phpcs:enable
		$host  = (string) wp_parse_url( (string) $this->plugin->settings()->general( 'partner_site_url' ), PHP_URL_HOST );
		$fails = 0;

		foreach ( $posts as $post_id ) {
			$keyword   = trim( (string) get_post_meta( $post_id, MetaKeys::FOCUS_KEYWORD, true ) );
			$terms     = taxonomy_exists( GameModule::ARCHETYPE ) ? get_the_terms( $post_id, GameModule::ARCHETYPE ) : false;
			$archetype = is_array( $terms ) && isset( $terms[0] ) ? (string) $terms[0]->name : '';
			$last      = $post_id;

			if ( '' === $keyword && '' === $archetype ) {
				delete_post_meta( $post_id, Bridge::META );
				continue;
			}

			$answer = $client->bridge( $keyword, $archetype );
			++$result['checked'];

			if ( null === $answer ) {
				++$result['failed'];
				++$fails;
				update_post_meta(
					$post_id,
					Bridge::META,
					array(
						'failed'  => true,
						'items'   => array(),
						'checked' => time(),
					)
				);

				// Второй сайт не отвечает: остальные записи спросим в следующий раз, а не будем ждать каждую.
				if ( $fails >= self::FAILURES ) {
					break;
				}

				continue;
			}

			$fails = 0;
			update_post_meta(
				$post_id,
				Bridge::META,
				array(
					'failed'  => false,
					'items'   => Bridge::items( $answer, $host ),
					'checked' => time(),
				)
			);
		}

		$result['done'] = count( $posts ) < $limit;
		update_option( self::CURSOR, $result['done'] ? 0 : $last, false );

		return $result;
	}
}
