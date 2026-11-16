<?php
/**
 * Самопроверка и проверка внешних адресов по расписанию.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Health;

use IndexPilot\Links\Extractor;
use IndexPilot\Plugin;
use IndexPilot\Schema\VideoData;
use IndexPilot\ShortLinks\WpStore as ShortLinkStore;
use IndexPilot\Sitemap\AuthorProvider;
use IndexPilot\Sitemap\ImageExtractor;
use IndexPilot\Sitemap\PostTypeProvider;
use IndexPilot\Sitemap\TaxonomyProvider;

/**
 * Связывает чистые классы проверки с WordPress: расписание, запросы, список адресов из карты сайта.
 * Всё работает только в фоновых задачах и по кнопке в админке — на страницах сайта ничего не происходит.
 */
final class Module {

	public const WEEKLY         = 'indexpilot_health_weekly';
	public const BATCH          = 'indexpilot_health_batch';
	public const OUTBOUND_DAILY = 'indexpilot_outbound_daily';
	public const OUTBOUND_BATCH = 'indexpilot_outbound_batch';

	/**
	 * Состояние сбора внешних адресов из текстов.
	 */
	public const COLLECT_STATE = 'outbound_collect';

	private const SELF_BATCH     = 15;
	private const OUTBOUND_LIMIT = 20;
	private const COLLECT_POSTS  = 200;
	private const MAX_URLS       = 5000;

	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Подключает хуки расписания.
	 */
	public function register(): void {
		add_action( self::WEEKLY, array( $this, 'start_self_check' ) );
		add_action( self::BATCH, array( $this, 'self_check_batch' ) );
		add_action( self::OUTBOUND_DAILY, array( $this, 'outbound_batch' ) );
		add_action( self::OUTBOUND_BATCH, array( $this, 'outbound_batch' ) );

		// Расписание ставится из админки: на страницах сайта плагин не делает ни одной лишней записи в базу.
		add_action( 'admin_init', array( $this, 'schedule' ) );
	}

	/**
	 * Ставит задачи в расписание. Первый запуск — через час: сразу после установки сайт не нагружается.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::WEEKLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::WEEKLY );
		}

		if ( ! wp_next_scheduled( self::OUTBOUND_DAILY ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::OUTBOUND_DAILY );
		}
	}

	/**
	 * Хранилище результатов.
	 */
	public function store(): WpStore {
		return new WpStore( $this->plugin->db() );
	}

	/**
	 * Самопроверка страниц.
	 */
	public function self_check(): SelfCheck {
		return new SelfCheck(
			$this->store(),
			array( $this, 'fetch_page' ),
			array( $this, 'archive_post_texts' ),
			static function ( float $seconds ): void {
				usleep( (int) ( $seconds * 1000000 ) );
			},
			static fn(): float => microtime( true ),
			static fn(): int => time()
		);
	}

	/**
	 * Проверка внешних адресов.
	 */
	public function outbound(): OutboundCheck {
		return new OutboundCheck(
			$this->store(),
			array( $this, 'fetch_status' ),
			static function ( float $seconds ): void {
				usleep( (int) ( $seconds * 1000000 ) );
			},
			static fn(): float => microtime( true )
		);
	}

	/**
	 * Начинает новый обход страниц из карты сайта и ставит первую пачку.
	 */
	public function start_self_check(): void {
		$this->self_check()->start( $this->sitemap_urls() );
		$this->self_check_batch();
	}

	/**
	 * Очередная пачка самопроверки; если обход не закончен — следующая через полминуты.
	 */
	public function self_check_batch(): void {
		$result = $this->self_check()->run_batch( self::SELF_BATCH );

		if ( ! $result['done'] && ! wp_next_scheduled( self::BATCH ) ) {
			wp_schedule_single_event( time() + 30, self::BATCH );
		}
	}

	/**
	 * Сбор внешних адресов из текстов и проверка тех, кому подошёл срок.
	 */
	public function outbound_batch(): void {
		$collected = $this->collect_batch( self::COLLECT_POSTS );
		$checked   = $this->outbound()->check_due( self::OUTBOUND_LIMIT, time() );

		if ( ( ! $collected['done'] || $checked >= self::OUTBOUND_LIMIT ) && ! wp_next_scheduled( self::OUTBOUND_BATCH ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::OUTBOUND_BATCH );
		}
	}

	/**
	 * Собирает внешние ссылки, картинки и ролики очередной пачки записей. Полный проход — раз в неделю.
	 *
	 * @param int $limit Сколько записей обойти.
	 * @return array{done: bool, processed: int}
	 */
	public function collect_batch( int $limit ): array {
		$store = $this->store();
		$state = $store->state( self::COLLECT_STATE ) + array(
			'last_id'   => 0,
			'pass_done' => 0,
		);

		if ( 0 === (int) $state['last_id'] && time() - (int) $state['pass_done'] < WEEK_IN_SECONDS ) {
			return array(
				'done'      => true,
				'processed' => 0,
			);
		}

		if ( 0 === (int) $state['last_id'] ) {
			$this->collect_shortlinks( $store );
		}

		$db    = $this->plugin->db();
		$types = array_values( array_filter( get_post_types( array( 'public' => true ) ), static fn( string $type ): bool => 'attachment' !== $type && is_post_type_viewable( $type ) ) );

		$placeholders = implode( ', ', array_fill( 0, max( 1, count( $types ) ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу типов.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- фоновый обход записей пачками.
		$posts = $db->get_results( $db->prepare( "SELECT ID, post_content FROM {$db->posts} WHERE post_status = 'publish' AND ID > %d AND post_type IN ( {$placeholders} ) ORDER BY ID ASC LIMIT %d", array_merge( array( (int) $state['last_id'] ), array() === $types ? array( 'post' ) : $types, array( $limit ) ) ), ARRAY_A );
		// phpcs:enable

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$base = untrailingslashit( home_url() );

		foreach ( (array) $posts as $post ) {
			$post_id = (int) $post['ID'];
			$content = (string) $post['post_content'];

			foreach ( Extractor::extract( $content, $host ) as $link ) {
				if ( 'external' === $link['type'] ) {
					$this->remember( $store, 'link', $link['url'], $post_id );
				}
			}

			foreach ( ImageExtractor::from_html( $content, $base, 50 ) as $image ) {
				$this->remember( $store, 'image', $image, $post_id );
			}

			foreach ( VideoData::stored( get_post_meta( $post_id, VideoData::META, true ) ) as $video ) {
				if ( 'youtube' === ( $video['provider'] ?? '' ) && '' !== (string) ( $video['url'] ?? '' ) ) {
					$this->remember( $store, 'video', (string) $video['url'], $post_id );
				}
			}

			$state['last_id'] = $post_id;
		}

		$done = count( (array) $posts ) < $limit;

		if ( $done ) {
			$state = array(
				'last_id'   => 0,
				'pass_done' => time(),
			);
		}

		$store->save_state( self::COLLECT_STATE, $state );

		return array(
			'done'      => $done,
			'processed' => count( (array) $posts ),
		);
	}

	/**
	 * Цели своих коротких ссылок — тоже внешние адреса: их проверка показывает, жива ли цель.
	 *
	 * @param WpStore $store Хранилище результатов.
	 */
	public function collect_shortlinks( WpStore $store ): void {
		if ( ! $this->plugin->settings()->general( 'shortlinks_enabled' ) ) {
			return;
		}

		$links = new ShortLinkStore( $this->plugin->db() );

		foreach ( $links->all( 1000, 0 ) as $link ) {
			$this->remember( $store, 'link', (string) $link['target'], 0 );
		}
	}

	/**
	 * Запоминает адрес для проверки, если его ещё нет.
	 *
	 * @param WpStore $store   Хранилище.
	 * @param string  $kind    Вид.
	 * @param string  $url     Адрес.
	 * @param int     $post_id Запись, где он стоит.
	 */
	private function remember( WpStore $store, string $kind, string $url, int $post_id ): void {
		if ( strlen( $url ) > 2000 || null !== $store->get( $kind, $url ) ) {
			return;
		}

		$store->save( $kind, $url, array( 'post_id' => $post_id ) );
	}

	/**
	 * Запрашивает страницу своего сайта.
	 *
	 * @param string $url Адрес.
	 * @return array{status: int, location: string, ms: int, body: string}
	 */
	public function fetch_page( string $url ): array {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'limit_response_size' => 3 * MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; IndexPilot self-check)',
				// Свой сайт: проверка сертификата, как и в «Здоровье сайта» WordPress, по фильтру для локальных запросов.
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- фильтр ядра WordPress.
			)
		);
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 0,
				'location' => '',
				'ms'       => $ms,
				'body'     => '',
			);
		}

		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			'ms'       => $ms,
			'body'     => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Код ответа внешнего адреса. Картинки своего сайта проверяются по файлу, без запроса.
	 *
	 * @param string $url  Адрес.
	 * @param string $kind Вид.
	 */
	public function fetch_status( string $url, string $kind ): int {
		$uploads = wp_get_upload_dir();
		$base    = (string) ( $uploads['baseurl'] ?? '' );

		if ( 'image' === $kind && '' !== $base && str_starts_with( set_url_scheme( $url, 'https' ), set_url_scheme( $base, 'https' ) . '/' ) ) {
			$path = (string) $uploads['basedir'] . substr( set_url_scheme( $url, 'https' ), strlen( set_url_scheme( $base, 'https' ) ) );

			return file_exists( rawurldecode( (string) strtok( $path, '?' ) ) ) ? 200 : 404;
		}

		$target = 'video' === $kind ? VideoData::oembed_url(
			array(
				'provider' => 'youtube',
				'url'      => $url,
			)
		) : $url;

		$args = array(
			'timeout'     => 8,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; IndexPilot link check)',
		);

		// wp_safe_remote_* не обращается к внутренним адресам сети.
		$response = wp_safe_remote_head( $target, $args );

		if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 403, 405, 501 ), true ) ) {
			$response = wp_safe_remote_get( $target, $args + array( 'limit_response_size' => 2048 ) );
		}

		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Тексты записей, на которые ссылается архив (первые шесть).
	 *
	 * @param string $html Разметка архива.
	 * @param string $url  Адрес архива.
	 * @return list<string>
	 */
	public function archive_post_texts( string $html, string $url ): array {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$texts = array();
		$seen  = array();

		foreach ( Extractor::extract( SelfCheck::main_html( $html ), $host ) as $link ) {
			if ( 'internal' !== $link['type'] || $link['url'] === $url ) {
				continue;
			}

			$post_id = url_to_postid( home_url( (string) wp_parse_url( $link['url'], PHP_URL_PATH ) ) );

			if ( $post_id <= 0 || isset( $seen[ $post_id ] ) || 'publish' !== get_post_status( $post_id ) ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$texts[]          = strip_shortcodes( (string) get_post_field( 'post_content', $post_id ) );

			if ( count( $texts ) >= 6 ) {
				break;
			}
		}

		return $texts;
	}

	/**
	 * Адреса из карты сайта: записи, архивы терминов и авторов.
	 *
	 * @return list<array{url: string, archive: bool}>
	 */
	public function sitemap_urls(): array {
		$plugin  = $this->plugin;
		$db      = $plugin->db();
		$urls    = array();
		$posts   = new PostTypeProvider( $db, $plugin->resolver(), $plugin->settings(), $plugin->redirected_posts() );
		$terms   = new TaxonomyProvider( $plugin->resolver(), $plugin->settings() );
		$authors = new AuthorProvider( $plugin->resolver() );
		$add     = static function ( array $entries, bool $archive ) use ( &$urls ): void {
			foreach ( $entries as $entry ) {
				$urls[ (string) $entry['loc'] ] = $archive;
			}
		};

		foreach ( $posts->types() as $type ) {
			foreach ( $posts->pages( $type ) as $page ) {
				$add( (array) $posts->entries( $type, (int) $page['page'] ), false );

				if ( count( $urls ) >= self::MAX_URLS ) {
					break 2;
				}
			}
		}

		foreach ( $terms->types() as $taxonomy ) {
			foreach ( $terms->pages( $taxonomy ) as $page ) {
				$add( (array) $terms->entries( $taxonomy, (int) $page['page'] ), true );
			}
		}

		$add( $authors->entries(), true );

		$list = array();
		foreach ( array_slice( $urls, 0, self::MAX_URLS, true ) as $url => $archive ) {
			$list[] = array(
				'url'     => (string) $url,
				'archive' => $archive,
			);
		}

		return $list;
	}
}
