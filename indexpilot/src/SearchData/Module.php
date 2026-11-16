<?php
/**
 * Данные поиска: расписание, загрузка, проверка индексации и экраны админки.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

use IndexPilot\Admin\QueriesPanel;
use IndexPilot\Admin\Reports\QueriesReport as QueriesReportPage;
use IndexPilot\Admin\Reports\StaleReport;
use IndexPilot\Admin\Reports\TopicsReport;
use IndexPilot\Admin\SearchDataPage;
use IndexPilot\CrossSite\PartnerClient;
use IndexPilot\Game\Hub;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;

/**
 * Связывает чистые классы данных поиска с WordPress. Сеть и обход записей — только по расписанию и по кнопке
 * в админке; на страницах сайта модуль ничего не читает и не пишет.
 */
final class Module {

	public const DAILY   = 'indexpilot_search_daily';
	public const BATCH   = 'indexpilot_search_batch';
	public const INSPECT = 'indexpilot_search_inspect';

	/**
	 * Состояние загрузки (без автозагрузки).
	 */
	public const STATE = 'indexpilot_search_sync';

	/**
	 * Результаты проверки индексации: адрес => проверено, в индексе ли, причина (без автозагрузки).
	 */
	public const INSPECTIONS = 'indexpilot_search_inspection';

	/**
	 * Сколько важных страниц проверяется в индексе Google за сутки.
	 */
	public const INSPECT_LIMIT = 40;

	/**
	 * Сколько шагов загрузки за один запуск по расписанию.
	 */
	private const STEPS_PER_RUN = 2;

	/**
	 * Сколько запросов отчёта сверяется со вторым сайтом после загрузки.
	 */
	public const PARTNER_QUERIES = 15;

	/**
	 * Хост сайта в Яндекс Вебмастере, найденный в этом запуске.
	 *
	 * @var string|null
	 */
	private ?string $yandex_host = null;

	/**
	 * Адреса рубрик и авторов => ID, собранные один раз за запуск.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $links = array();

	public function __construct( private Plugin $plugin, private ?Http $http = null ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( self::DAILY, array( $this, 'start_sync' ) );
		add_action( self::BATCH, array( $this, 'sync_batch' ) );
		add_action( self::INSPECT, array( $this, 'inspect_batch' ) );

		// Таблица и расписание — из админки: на страницах сайта плагин не делает лишних запросов.
		add_action( 'admin_init', array( $this, 'schedule' ) );

		if ( is_admin() ) {
			add_action(
				'init',
				function (): void {
					( new SearchDataPage( $this ) )->register();
					( new QueriesReportPage( $this ) )->register();
					( new TopicsReport( $this ) )->register();
					( new StaleReport( $this ) )->register();
					( new QueriesPanel( $this ) )->register();
				},
				100
			);
		}
	}

	/**
	 * Плагин.
	 */
	public function plugin(): Plugin {
		return $this->plugin;
	}

	/**
	 * Доступы к поисковикам.
	 */
	public function credentials(): Credentials {
		return new Credentials( $this->http );
	}

	/**
	 * Таблица запросов.
	 */
	public function store(): WpQueryStore {
		return new WpQueryStore( $this->plugin->db() );
	}

	/**
	 * Записи и рубрики сайта для отчётов.
	 */
	public function site(): WpSite {
		return new WpSite( $this->plugin->db(), $this->plugin->analyzer()->matcher() );
	}

	/**
	 * Название и ссылка на правку объекта строки отчёта.
	 *
	 * @param string $type Тип: post, term, author, home.
	 * @param int    $id   ID.
	 * @param string $page Адрес страницы.
	 * @return string HTML.
	 */
	public static function object_link( string $type, int $id, string $page ): string {
		if ( 'post' === $type && $id > 0 ) {
			return '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		}

		if ( 'term' === $type && $id > 0 ) {
			$term = get_term( $id );

			if ( $term instanceof \WP_Term ) {
				return '<a href="' . esc_url( (string) get_edit_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>';
			}
		}

		if ( 'home' === $type ) {
			return esc_html__( 'Главная', 'indexpilot' );
		}

		return '' === $page ? esc_html__( 'весь сайт', 'indexpilot' ) : '<a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( $page ) . '</a>';
	}

	/**
	 * Сравнение фраз через словарь игры.
	 *
	 * @return callable(string): string
	 */
	public function normalizer(): callable {
		return array( $this->plugin->lexicon(), 'normalize' );
	}

	/**
	 * Создаёт таблицу и ставит задачи в расписание.
	 */
	public function schedule(): void {
		$this->store()->maybe_install();

		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', self::DAILY );
		}

		if ( ! wp_next_scheduled( self::INSPECT ) ) {
			wp_schedule_event( time() + 4 * HOUR_IN_SECONDS, 'daily', self::INSPECT );
		}
	}

	/**
	 * Загрузка по шагам: Google за три периода и Яндекс за два.
	 */
	public function job(): SyncJob {
		$credentials = $this->credentials();
		$sources     = array();
		$google      = $credentials->google();
		$yandex      = $credentials->yandex();
		$site        = $credentials->google_site();

		if ( null !== $google ) {
			$sources['google'] = array(
				'current'  => static fn( string $start, string $end ): array => $google->query( $site, $start, $end ),
				'previous' => static fn( string $start, string $end ): array => $google->query( $site, $start, $end ),
				'p90'      => static fn( string $start, string $end ): array => $google->query( $site, $start, $end, array( 'page' ) ),
			);
		}

		if ( null !== $yandex ) {
			$host              = function () use ( $yandex ): string {
				$this->yandex_host ??= $yandex->host_id( home_url( '/' ) );

				return $this->yandex_host;
			};
			$sources['yandex'] = array(
				'current'  => static fn( string $start, string $end ): array => $yandex->popular_queries( $host(), $start, $end ),
				'previous' => static fn( string $start, string $end ): array => $yandex->popular_queries( $host(), $start, $end ),
			);
		}

		return new SyncJob( $sources, $this->store(), $this->matcher() );
	}

	/**
	 * Адрес из поиска => запись, рубрика или автор.
	 */
	public function matcher(): UrlMatcher {
		return new UrlMatcher(
			static fn( string $url ): int => (int) url_to_postid( $url ),
			fn( string $url ): int => $this->links( 'term' )[ self::bare( $url ) ] ?? 0,
			fn( string $url ): int => $this->links( 'author' )[ self::bare( $url ) ] ?? 0
		);
	}

	/**
	 * Адрес без схемы, www и слэша в конце — для сравнения.
	 *
	 * @param string $url Адрес.
	 */
	public static function bare( string $url ): string {
		return untrailingslashit( (string) preg_replace( '#^https?://(www\.)?#i', '', strtolower( trim( $url ) ) ) );
	}

	/**
	 * Адреса рубрик или авторов сайта.
	 *
	 * @param string $kind term или author.
	 * @return array<string, int>
	 */
	private function links( string $kind ): array {
		if ( isset( $this->links[ $kind ] ) ) {
			return $this->links[ $kind ];
		}

		$links = array();

		if ( 'term' === $kind ) {
			$terms = get_terms(
				array(
					'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
					'hide_empty' => false,
					'number'     => 5000,
				)
			);

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );

				if ( is_string( $link ) ) {
					$links[ self::bare( $link ) ] = (int) $term->term_id;
				}
			}
		} else {
			foreach ( get_users( array( 'has_published_posts' => true, 'fields' => array( 'ID' ), 'number' => 500 ) ) as $user ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$links[ self::bare( get_author_posts_url( (int) $user->ID ) ) ] = (int) $user->ID;
			}
		}

		$this->links[ $kind ] = $links;

		return $links;
	}

	/**
	 * Состояние загрузки.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$state = get_option( self::STATE, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Начинает загрузку и делает первые шаги.
	 *
	 * @param int $steps Сколько шагов сделать сразу.
	 * @return array<string, mixed> Состояние.
	 */
	public function start_sync( int $steps = self::STEPS_PER_RUN ): array {
		$this->store()->maybe_install();

		$job   = $this->job();
		$state = $job->start( gmdate( 'Y-m-d' ), time() );

		if ( array() === $this->credentials()->engines() ) {
			$this->store()->clear();
			delete_option( self::STATE );

			return array();
		}

		return $this->advance( $job, $state, max( 1, (int) $steps ) );
	}

	/**
	 * Очередная пачка загрузки по расписанию.
	 */
	public function sync_batch(): void {
		$state = $this->state();

		if ( array() === $state || ! empty( $state['done'] ) ) {
			return;
		}

		$this->advance( $this->job(), $state, self::STEPS_PER_RUN );
	}

	/**
	 * Выполняет шаги, сохраняет состояние, ставит следующую пачку или подводит итог.
	 *
	 * @param SyncJob              $job   Загрузка.
	 * @param array<string, mixed> $state Состояние.
	 * @param int                  $steps Сколько шагов.
	 * @return array<string, mixed>
	 */
	private function advance( SyncJob $job, array $state, int $steps ): array {
		$state = $job->run( $state, time(), $steps );
		update_option( self::STATE, $state, false );

		if ( empty( $state['done'] ) ) {
			if ( ! wp_next_scheduled( self::BATCH ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BATCH );
			}

			return $state;
		}

		$this->summarize();

		return $state;
	}

	/**
	 * Пересчитывает цифры для «Обзора» и сверяет самые частые запросы со вторым сайтом (ответ запоминается).
	 */
	public function summarize(): void {
		$rows   = $this->store()->report_rows();
		$report = new QueriesReport( $this->normalizer() );

		update_option(
			WpQueryStore::SUMMARY,
			array(
				'near_top' => count( $report->near_top( $rows, 1000 ) ),
				'cannibal' => count( $report->cannibal( $rows, 1000 ) ),
			),
			true
		);

		$partner = new PartnerClient( $this->plugin->settings() );

		if ( ! $partner->configured() ) {
			return;
		}

		$seen = array();

		foreach ( $rows as $row ) {
			if ( 'current' !== $row['period'] || isset( $seen[ $row['query'] ] ) ) {
				continue;
			}

			$seen[ $row['query'] ] = true;
			$partner->lookup( (string) $row['query'], true );

			if ( count( $seen ) >= self::PARTNER_QUERIES ) {
				break;
			}
		}
	}

	/**
	 * Важные страницы для проверки индексации: опорные материалы, страницы классов, записи за последний месяц.
	 *
	 * @return array<string, int> Адрес => важность.
	 */
	public function inspection_candidates(): array {
		$candidates = array();
		$types      = $this->plugin->audit()->types();

		foreach (
			get_posts(
				array(
					'post_type'   => $types,
					'post_status' => 'publish',
					'numberposts' => 100,
					'fields'      => 'ids',
					'date_query'  => array( array( 'after' => '30 days ago' ) ),
				)
			) as $post_id
		) {
			$candidates[ (string) get_permalink( (int) $post_id ) ] = 1;
		}

		if ( taxonomy_exists( Hub::CLASS_TAXONOMY ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => Hub::CLASS_TAXONOMY,
					'hide_empty' => true,
					'number'     => 50,
				)
			);

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );

				if ( is_string( $link ) ) {
					$candidates[ $link ] = 2;
				}
			}
		}

		foreach (
			get_posts(
				array(
					'post_type'   => $types,
					'post_status' => 'publish',
					'numberposts' => 100,
					'fields'      => 'ids',
					'meta_key'    => MetaKeys::CORNERSTONE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- раз в сутки по расписанию.
					'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- раз в сутки по расписанию.
				)
			) as $post_id
		) {
			$candidates[ (string) get_permalink( (int) $post_id ) ] = 3;
		}

		unset( $candidates[''] );

		return $candidates;
	}

	/**
	 * Результаты проверки индексации.
	 *
	 * @return array<string, array{checked: int, indexed: bool, coverage: string, reason: string}>
	 */
	public function inspections(): array {
		$saved = get_option( self::INSPECTIONS, array() );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Проверяет очередные важные страницы в индексе Google.
	 *
	 * @param int $limit Сколько адресов.
	 * @return array{checked: int, error: string}
	 */
	public function inspect_batch( int $limit = self::INSPECT_LIMIT ): array {
		$google = $this->credentials()->google();

		if ( null === $google ) {
			return array(
				'checked' => 0,
				'error'   => '',
			);
		}

		$candidates = $this->inspection_candidates();
		$saved      = array_intersect_key( $this->inspections(), $candidates );
		$site       = $this->credentials()->google_site();
		$checked    = 0;
		$error      = '';

		foreach ( Inspection::pick( $candidates, $saved, time(), max( 1, $limit ) ) as $url ) {
			try {
				$saved[ $url ] = array( 'checked' => time() ) + Inspection::interpret( $google->inspect( $site, $url ) );
				++$checked;
			} catch ( SearchDataException $exception ) {
				$error = $exception->getMessage();
				break;
			}
		}

		update_option( self::INSPECTIONS, $saved, false );

		return array(
			'checked' => $checked,
			'error'   => $error,
		);
	}

	/**
	 * Проверка подключения: понятное сообщение по каждому поисковику.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function check(): array {
		$credentials = $this->credentials();
		$result      = array();
		$google      = $credentials->google();
		$yandex      = $credentials->yandex();
		$day         = static fn( int $days ): string => gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

		if ( null !== $google ) {
			try {
				$google->query( $credentials->google_site(), $day( 10 ), $day( 3 ), array( 'page' ), 1 );
				$result['google'] = array(
					'ok'      => true,
					/* translators: 1: ресурс Search Console, 2: адрес служебной учётной записи. */
					'message' => sprintf( __( 'Google Search Console: подключено. Ресурс %1$s доступен учётной записи %2$s.', 'indexpilot' ), $credentials->google_site(), $google->email() ),
				);
			} catch ( SearchDataException $error ) {
				$result['google'] = array(
					'ok'      => false,
					'message' => __( 'Google Search Console:', 'indexpilot' ) . ' ' . $error->getMessage(),
				);
			}
		}

		if ( null !== $yandex ) {
			try {
				$yandex->host_id( home_url( '/' ) );
				$result['yandex'] = array(
					'ok'      => true,
					'message' => __( 'Яндекс Вебмастер: подключено, сайт найден.', 'indexpilot' ),
				);
			} catch ( SearchDataException $error ) {
				$result['yandex'] = array(
					'ok'      => false,
					'message' => __( 'Яндекс Вебмастер:', 'indexpilot' ) . ' ' . $error->getMessage(),
				);
			}
		}

		return $result;
	}

	/**
	 * Есть ли загруженные данные.
	 */
	public function has_data(): bool {
		return WpQueryStore::installed() && ! empty( $this->state()['finished'] );
	}
}
