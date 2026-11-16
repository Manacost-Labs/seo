<?php
/**
 * Точка сборки плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot;

use IndexPilot\Admin\AuthorPanel;
use IndexPilot\Admin\BulkEditor;
use IndexPilot\Admin\ClassicMetabox;
use IndexPilot\Admin\ConflictNotice;
use IndexPilot\Admin\ImportPage;
use IndexPilot\Admin\Menu;
use IndexPilot\Admin\OverviewPage;
use IndexPilot\Admin\PostListColumns;
use IndexPilot\Admin\Reports\AuditReport;
use IndexPilot\Admin\Reports\LinksReport;
use IndexPilot\Admin\Reports\RedirectsReport;
use IndexPilot\Admin\SearchEnginesPage;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Admin\ShortLinksPage;
use IndexPilot\Admin\TermPanel;
use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\KeywordUsage;
use IndexPilot\Analysis\Language\EnglishStemmer;
use IndexPilot\Analysis\Language\RussianStemmer;
use IndexPilot\Analysis\PaperFactory;
use IndexPilot\Analysis\ScoreUpdater;
use IndexPilot\Audit\Runner as AuditRunner;
use IndexPilot\Authors\AuthorBox;
use IndexPilot\Authors\AuthorProfile;
use IndexPilot\Breadcrumbs\Output as BreadcrumbsOutput;
use IndexPilot\Breadcrumbs\Trail;
use IndexPilot\Breadcrumbs\WpBreadcrumbData;
use IndexPilot\Cli\Commands as CliCommands;
use IndexPilot\Compat\ConflictDetector;
use IndexPilot\CrossSite\PartnerClient;
use IndexPilot\Doctor\Probe as DoctorProbe;
use IndexPilot\Frontend\AnswerBlock;
use IndexPilot\Frontend\ArchiveText;
use IndexPilot\Frontend\FactBlocks;
use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Frontend\Head;
use IndexPilot\Frontend\SchemaOutput;
use IndexPilot\Frontend\Verification;
use IndexPilot\Game\Lexicon;
use IndexPilot\Game\Module as GameModule;
use IndexPilot\Health\Module as HealthModule;
use IndexPilot\Health\WpStore as HealthStore;
use IndexPilot\IndexNow\Key as IndexNowKey;
use IndexPilot\IndexNow\Notifier;
use IndexPilot\IndexNow\Queue as IndexNowQueue;
use IndexPilot\IndexNow\Sender as IndexNowSender;
use IndexPilot\Import\AioseoImporter;
use IndexPilot\Import\Importer;
use IndexPilot\Import\ImportState;
use IndexPilot\Import\RankMathImporter;
use IndexPilot\Import\Source;
use IndexPilot\Import\SvlSeoImporter;
use IndexPilot\Import\WpMetaStore;
use IndexPilot\Links\Indexer as LinkIndexer;
use IndexPilot\Links\Reports as LinkReports;
use IndexPilot\Links\Scanner as LinkScanner;
use IndexPilot\Links\Schema as LinkSchema;
use IndexPilot\Links\SuggestionIndex;
use IndexPilot\Links\Suggester;
use IndexPilot\Links\WpLinkStore;
use IndexPilot\Llms\Output as LlmsOutput;
use IndexPilot\Meta\MetaRegistrar;
use IndexPilot\Overview\Facts as OverviewFacts;
use IndexPilot\Promo\Module as PromoModule;
use IndexPilot\Redirects\AutoRedirect;
use IndexPilot\Redirects\Handler as RedirectHandler;
use IndexPilot\Redirects\Manager as RedirectManager;
use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Redirects\Schema as RedirectSchema;
use IndexPilot\Redirects\WpNotFoundStore;
use IndexPilot\Redirects\WpStore as RedirectStore;
use IndexPilot\Redirects\RedirectionAdapter;
use IndexPilot\Redirects\RedirectService;
use IndexPilot\Redirects\TargetChecker;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Resolver\WpDataSource;
use IndexPilot\Rest\AnalyzeController;
use IndexPilot\Rest\AuditController;
use IndexPilot\Rest\BulkController;
use IndexPilot\Rest\ImportController;
use IndexPilot\Rest\IndexNowController;
use IndexPilot\Rest\KeywordsController;
use IndexPilot\Rest\LinksController;
use IndexPilot\Rest\RedirectsController;
use IndexPilot\Rest\RestField;
use IndexPilot\Robots\RobotsTxt;
use IndexPilot\Schema\GraphBuilder;
use IndexPilot\Schema\PageData;
use IndexPilot\Settings\Settings;
use IndexPilot\ShortLinks\Router as ShortLinkRouter;
use IndexPilot\ShortLinks\Service as ShortLinkService;
use IndexPilot\ShortLinks\Slug as ShortLinkSlug;
use IndexPilot\ShortLinks\WpClickLog as ShortLinkClickLog;
use IndexPilot\ShortLinks\WpStore as ShortLinkStore;
use IndexPilot\Site\SiteDefaults;
use IndexPilot\Sitemap\AuthorProvider;
use IndexPilot\Sitemap\Cache as SitemapCache;
use IndexPilot\Sitemap\PostTypeProvider;
use IndexPilot\Sitemap\Router as SitemapRouter;
use IndexPilot\Sitemap\TaxonomyProvider;
use IndexPilot\Site\SiteProfile;
use IndexPilot\Variables\CoreVariables;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;

/**
 * Создаёт общие сервисы и подключает модули к хукам WordPress.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	/**
	 * Единственный экземпляр.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Настройки плагина.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Реестр переменных шаблонов.
	 *
	 * @var VariableRegistry|null
	 */
	private ?VariableRegistry $variables = null;

	/**
	 * Резолвер мета-данных.
	 *
	 * @var MetaResolver|null
	 */
	private ?MetaResolver $resolver = null;

	/**
	 * Анализатор страниц.
	 *
	 * @var Analyzer|null
	 */
	private ?Analyzer $analyzer = null;

	/**
	 * Пересчёт оценки.
	 *
	 * @var ScoreUpdater|null
	 */
	private ?ScoreUpdater $score_updater = null;

	/**
	 * Редиректы сайта.
	 *
	 * @var RedirectService|null
	 */
	private ?RedirectService $redirects = null;

	/**
	 * Записи под редиректом.
	 *
	 * @var RedirectedPosts|null
	 */
	private ?RedirectedPosts $redirected_posts = null;

	/**
	 * Игровой модуль.
	 *
	 * @var GameModule|null
	 */
	private ?GameModule $game = null;

	/**
	 * Аудит записей.
	 *
	 * @var AuditRunner|null
	 */
	private ?AuditRunner $audit = null;

	/**
	 * Обход записей для графа ссылок.
	 *
	 * @var LinkScanner|null
	 */
	private ?LinkScanner $link_scanner = null;

	/**
	 * Вывод текстов рубрики.
	 *
	 * @var ArchiveText|null
	 */
	private ?ArchiveText $archive_text = null;

	/**
	 * Модуль «Переходы».
	 *
	 * @var PromoModule|null
	 */
	private ?PromoModule $promo = null;

	/**
	 * Самопроверка сайта.
	 *
	 * @var HealthModule|null
	 */
	private ?HealthModule $health = null;

	/**
	 * Запускает плагин один раз.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register_hooks();
	}

	/**
	 * Возвращает запущенный экземпляр.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::boot();
		}

		return self::$instance;
	}

	/**
	 * Подключает модули.
	 */
	private function register_hooks(): void {
		// Замер нагрузки (wp indexpilot doctor): работает только на запросе с подписанным одноразовым параметром.
		DoctorProbe::maybe_start();

		// Игровой модуль подключается первым: он добавляет переменные шаблонов, а реестр переменных
		// создаётся при первом же обращении к нему ниже.
		$this->game()->register();

		// Поздний приоритет: к этому моменту темы и плагины уже зарегистрировали свои типы записей.
		add_action( 'init', array( new MetaRegistrar(), 'register' ), 100 );

		if ( is_admin() ) {
			add_action(
				'init',
				function (): void {
					( new TermPanel( $this->resolver() ) )->register();
					( new PostListColumns( $this->resolver(), $this->redirected_posts() ) )->register();
					( new RedirectsReport( $this->redirects(), $this->redirected_posts() ) )->register();
					( new \IndexPilot\Admin\RedirectsPage( $this->redirect_store(), array( $this, 'redirect_manager' ), $this->redirects(), $this->not_found_store() ) )->register();
					( new \IndexPilot\Admin\NotFoundPage( $this->not_found_store(), $this->db() ) )->register();
					( new \IndexPilot\Admin\HealthPage( $this->health() ) )->register();
					( new LinksReport( $this->link_scanner(), $this->link_reports() ) )->register();
					( new \IndexPilot\Admin\Reports\ImagesReport() )->register();
					( new SettingsPage( $this->settings(), $this->site_defaults(), $this->variables() ) )->register();
					( new SearchEnginesPage( $this->settings(), $this->indexnow() ) )->register();
					( new ImportPage( $this->import_sources(), new ImportState() ) )->register();
					( new ConflictNotice( new ConflictDetector() ) )->register();
					( new AuthorPanel() )->register();
					( new BulkEditor() )->register();

					if ( $this->settings()->general( 'shortlinks_enabled' ) ) {
						global $wpdb;

						( new ShortLinksPage( $this->shortlinks(), new ShortLinkStore( $wpdb ), $this->shortlink_router(), new ShortLinkClickLog( $wpdb ), $this->health()->store() ) )->register();
						( new \IndexPilot\Admin\ShortLinkActions( $this->shortlink_router() ) )->register();
					}
					( new AuditReport( $this->audit() ) )->register();

					global $wpdb;

					( new OverviewPage( new OverviewFacts( $wpdb, $this->audit(), $this->link_scanner(), $this->redirects() ) ) )->register();
					$this->promo()->register_admin();

					// Меню: экраны сводятся к шести пунктам с вкладками.
					( new Menu() )->register();
				},
				101
			);

			( new ClassicMetabox( $this->resolver(), $this->settings(), $this->variables() ) )->register();
		}

		// Свои редиректы: правило ищется на самом раннем хуке по индексу из опции с автозагрузкой —
		// на обычной странице запросов к базе нет. Журнал 404 пишется только на ответе 404.
		( new RedirectHandler( $this->db() ) )->register();
		( new AutoRedirect( array( $this, 'redirect_manager' ) ) )->register();

		// Самопроверка сайта и проверка внешних адресов: только по расписанию и по кнопке в админке.
		$this->health()->register();

		// Граф ссылок: таблица создаётся при первом запуске, ссылки записи пересчитываются при её сохранении.
		add_action(
			'init',
			function (): void {
				global $wpdb;

				LinkSchema::maybe_install( $wpdb );
				RedirectSchema::maybe_install( $wpdb );
				HealthStore::maybe_install( $wpdb );
				$this->link_scanner()->register();
				$this->audit()->register();
			},
			100
		);

		// Команды WP-CLI: wp indexpilot analyze, import, links scan, audit, sitemap flush, indexnow send.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'indexpilot', new CliCommands( $this ) );
		}

		// Шорткод хлебных крошек.
		add_action(
			'init',
			function (): void {
				$this->breadcrumbs()->register();
				$this->author_box()->register();

				// Блоки из панели записи: краткий ответ, вопросы и ответы, факты и цитаты.
				( new AnswerBlock( $this->settings() ) )->register();
				$this->faq_block()->register();
				( new FactBlocks() )->register();
			},
			100
		);

		// Оценка пересчитывается на сервере при каждом сохранении записи или термина.
		$this->score_updater()->register();

		// Ролики записи: название с YouTube запрашивается один раз при сохранении и хранится в мете.
		( new \IndexPilot\Schema\VideoMeta() )->register();

		add_action(
			'rest_api_init',
			function (): void {
				( new AnalyzeController( $this->resolver(), $this->papers(), $this->analyzer() ) )->register();
				( new ImportController( $this->import_sources(), $this->importer(), new ImportState() ) )->register();
				( new RedirectsController( $this->redirects() ) )->register();

				if ( $this->settings()->general( 'shortlinks_enabled' ) ) {
					( new \IndexPilot\Rest\ShortLinksController( $this->shortlinks(), new ShortLinkStore( $this->db() ), $this->shortlink_router() ) )->register();
				}
				( new AuditController( $this->audit() ) )->register();
				( new IndexNowController( $this->indexnow(), $this->sitemap() ) )->register();
				( new RestField( $this->resolver() ) )->register();
				( new KeywordsController( $this->settings(), $this->analyzer()->matcher() ) )->register();
				( new BulkController( $this->resolver(), $this->score_updater(), $this->settings() ) )->register();
				( new LinksController( $this->link_scanner(), $this->link_reports(), $this->link_suggestions() ) )->register();
			}
		);

		// Карта сайта: наша отдаётся вместо штатной, пока её не отдаёт другой SEO-плагин.
		( new SitemapCache() )->register();
		add_action(
			'parse_request',
			function (): void {
				if ( ( new ConflictDetector() )->output_allowed() ) {
					$this->sitemap()->handle();
				}
			},
			1
		);
		add_filter(
			'wp_sitemaps_enabled',
			static fn( $enabled ) => ( new ConflictDetector() )->output_allowed() ? false : $enabled
		);

		// Короткие ссылки: таблица своих ссылок, перенаправление по адресам /go/КОД и короткий адрес каждой записи.
		add_action(
			'init',
			function (): void {
				global $wpdb;

				if ( $this->settings()->general( 'shortlinks_enabled' ) ) {
					( new ShortLinkStore( $wpdb ) )->maybe_install();
				}
			},
			100
		);
		add_action(
			'parse_request',
			function (): void {
				if ( $this->settings()->general( 'shortlinks_enabled' ) ) {
					$this->shortlink_router()->handle();
				}
			},
			1
		);
		add_filter(
			'pre_get_shortlink',
			function ( $shortlink, $id, $context ) {
				if ( ! $this->settings()->general( 'shortlinks_enabled' ) ) {
					return $shortlink;
				}

				// Проверка идёт до загрузки записи: на странице рубрики ID объекта — это ID термина, а не записи.
				if ( 'query' === $context && ! is_singular() ) {
					return $shortlink;
				}

				$post = get_post( 'query' === $context && 0 === (int) $id ? get_queried_object_id() : (int) $id );

				return $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type )
					? $this->shortlink_router()->url( ShortLinkSlug::for_post( (int) $post->ID ) )
					: $shortlink;
			},
			10,
			3
		);

		// Переходы: адреса /to/КОД со счётчиком, блоки перехода в тексте записи, блок «Мы в соцсетях».
		$this->promo()->register();

		// llms.txt — путеводитель по сайту для AI-ассистентов.
		add_action(
			'parse_request',
			function (): void {
				( new LlmsOutput( $this->resolver(), $this->settings(), new SitemapCache(), $this->game()->games() ) )->handle();
				// Любая открытая запись в Markdown по адресу с «.md» на конце.
				( new \IndexPilot\Llms\MarkdownOutput( $this->resolver(), $this->settings() ) )->handle();
			},
			1
		);
		add_action(
			'template_redirect',
			function (): void {
				( new \IndexPilot\Llms\MarkdownOutput( $this->resolver(), $this->settings() ) )->register_head();
			}
		);

		// robots.txt: адрес карты сайта и, если включено, запрет роботов, собирающих тексты для обучения.
		add_filter(
			'robots_txt',
			function ( $output, $is_public ) {
				if ( ! $is_public || ! ( new ConflictDetector() )->output_allowed() ) {
					return $output;
				}

				/**
				 * Роботы, которым запрещается обход при включённой настройке.
				 *
				 * @param list<string> $bots Имена роботов.
				 */
				$bots = (array) apply_filters( 'indexpilot_ai_training_bots', RobotsTxt::TRAINING_BOTS );

				return RobotsTxt::build(
					(string) $output,
					home_url( '/' . SitemapRouter::INDEX ),
					(bool) $this->settings()->general( 'block_ai_training' ),
					array_map( 'strval', $bots ),
					$this->settings()->general( 'yandex_clean_param' ) ? RobotsTxt::CLEAN_PARAM : '',
					(bool) $this->settings()->general( 'block_google_extended' )
				);
			},
			20,
			2
		);

		// IndexNow: очередь адресов и отправка по расписанию. Хуки подключаются после загрузки тем: к этому времени известны настройки.
		add_action(
			'init',
			function (): void {
				$this->indexnow()->register();
			},
			100
		);

		// Тексты рубрики выводятся независимо от того, кто печатает мета-теги.
		add_action( 'template_redirect', array( $this->archive_text(), 'register' ) );

		// Теги печатаются только на страницах сайта и только если их не выводит другой SEO-плагин.
		add_action(
			'template_redirect',
			function (): void {
				if ( ( new ConflictDetector() )->output_allowed() ) {
					( new Head( $this->resolver(), $this->settings() ) )->register();
					( new SchemaOutput( new PageData( $this->resolver(), $this->settings(), $this->trail(), new AuthorProfile(), $this->faq_block() ), new GraphBuilder() ) )->register();
					( new Verification( $this->settings() ) )->register();
				}
			}
		);
	}

	/**
	 * Анализатор страниц.
	 */
	public function analyzer(): Analyzer {
		if ( null === $this->analyzer ) {
			$this->analyzer = new Analyzer( null, new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), $this->lexicon() ), fn( string $game ): KeywordMatcher => $this->game()->games()->matcher( $game ) );
		}

		return $this->analyzer;
	}

	/**
	 * Игровой словарь: встроенные синонимы и разговорные имена классов плюс «Свои синонимы» из настроек.
	 * Правила словаря строятся при первом сравнении, поэтому на страницах сайта он ничего не стоит.
	 */
	public function lexicon(): Lexicon {
		return new Lexicon( Lexicon::parse_synonyms( (string) $this->settings()->general( 'custom_synonyms' ) ) );
	}

	/**
	 * Пересчёт оценки при сохранении и после импорта.
	 */
	public function score_updater(): ScoreUpdater {
		if ( null === $this->score_updater ) {
			$this->score_updater = new ScoreUpdater( $this->papers(), $this->analyzer() );
		}

		return $this->score_updater;
	}

	/**
	 * Источники импорта в порядке запуска: основные, затем запасной.
	 *
	 * @return array<string, Source>
	 */
	public function import_sources(): array {
		global $wpdb;

		$sources = array();
		foreach ( array( new AioseoImporter( $wpdb ), new SvlSeoImporter( $wpdb ), new RankMathImporter( $wpdb ) ) as $source ) {
			$sources[ $source->id() ] = $source;
		}

		return $sources;
	}

	/**
	 * Импортёр: пишет в мету записей и пересчитывает оценку записи после переноса.
	 */
	public function importer(): Importer {
		$updater = $this->score_updater();

		return new Importer(
			new WpMetaStore(),
			static fn( string $url ): int => (int) attachment_url_to_postid( $url ),
			static function ( int $post_id ) use ( $updater ): void {
				$updater->update_post( $post_id, get_post( $post_id ) );
			}
		);
	}

	/**
	 * Редиректы сайта из плагина Redirection и результаты проверки их целей.
	 */
	public function redirects(): RedirectService {
		if ( null === $this->redirects ) {
			global $wpdb;

			$this->redirects = new RedirectService(
				new RedirectionAdapter( $wpdb ),
				new TargetChecker(),
				(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				$this->redirect_store()
			);
		}

		return $this->redirects;
	}

	/**
	 * База данных WordPress.
	 */
	public function db(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Свои правила редиректов.
	 */
	public function redirect_store(): RedirectStore {
		return new RedirectStore( $this->db() );
	}

	/**
	 * Самопроверка сайта и проверка внешних адресов.
	 */
	public function health(): HealthModule {
		if ( null === $this->health ) {
			$this->health = new HealthModule( $this );
		}

		return $this->health;
	}

	/**
	 * Журнал 404.
	 */
	public function not_found_store(): WpNotFoundStore {
		return new WpNotFoundStore( $this->db() );
	}

	/**
	 * Менеджер своих редиректов. Действующие правила Redirection участвуют в схлопывании цепочек.
	 */
	public function redirect_manager(): RedirectManager {
		return new RedirectManager( $this->redirect_store(), (string) wp_parse_url( home_url(), PHP_URL_HOST ), $this->redirects()->foreign() );
	}

	/**
	 * Опубликованные записи, с адреса которых стоит редирект.
	 */
	public function redirected_posts(): RedirectedPosts {
		if ( null === $this->redirected_posts ) {
			$this->redirected_posts = new RedirectedPosts( $this->redirects() );
		}

		return $this->redirected_posts;
	}

	/**
	 * Уведомление поисковиков об изменённых страницах.
	 */
	public function indexnow(): Notifier {
		return new Notifier( new IndexNowQueue(), new IndexNowSender(), new IndexNowKey(), $this->resolver(), $this->settings() );
	}

	/**
	 * Карта сайта.
	 */
	public function sitemap(): SitemapRouter {
		global $wpdb;

		return new SitemapRouter(
			new PostTypeProvider( $wpdb, $this->resolver(), $this->settings(), $this->redirected_posts() ),
			new TaxonomyProvider( $this->resolver(), $this->settings() ),
			new AuthorProvider( $this->resolver() ),
			new SitemapCache()
		);
	}

	/**
	 * Обход записей для графа ссылок.
	 */
	public function link_scanner(): LinkScanner {
		if ( null === $this->link_scanner ) {
			global $wpdb;

			$cache = array();

			$this->link_scanner = new LinkScanner(
				$wpdb,
				new LinkIndexer(
					new WpLinkStore( $wpdb ),
					(string) wp_parse_url( home_url(), PHP_URL_HOST ),
					// Адрес ссылки приведён к виду без порта и параметров, поэтому запись ищется по пути.
					static function ( string $url ) use ( &$cache ): int {
						$path = (string) wp_parse_url( $url, PHP_URL_PATH );

						if ( ! isset( $cache[ $path ] ) ) {
							$cache[ $path ] = (int) url_to_postid( home_url( $path ) );
						}

						return $cache[ $path ];
					}
				)
			);
		}

		return $this->link_scanner;
	}

	/**
	 * Аудит записей сайта.
	 */
	public function audit(): AuditRunner {
		if ( null === $this->audit ) {
			global $wpdb;

			$this->audit = new AuditRunner( $wpdb, $this->resolver(), $this->settings() );
		}

		return $this->audit;
	}

	/**
	 * Подсказки внутренних ссылок.
	 */
	public function link_suggestions(): SuggestionIndex {
		global $wpdb;

		// Отпечаток словаря: после правки своих синонимов (и после смены устройства словаря) индекс заголовков строится заново.
		return new SuggestionIndex( $wpdb, new Suggester( $this->analyzer()->matcher() ), $this->redirected_posts(), md5( 'lexicon-2|' . (string) $this->settings()->general( 'custom_synonyms' ) . '|' . (int) $this->settings()->general( 'wow_root_term' ) ), $this->game()->games() );
	}

	/**
	 * Отчёты по графу ссылок.
	 */
	public function link_reports(): LinkReports {
		global $wpdb;

		return new LinkReports( $wpdb, $this->resolver(), $this->redirects(), $this->redirected_posts() );
	}

	/**
	 * Правила коротких ссылок.
	 */
	public function shortlinks(): ShortLinkService {
		global $wpdb;

		return new ShortLinkService(
			new ShortLinkStore( $wpdb ),
			static function ( int $post_id ): string {
				$post = get_post( $post_id );

				return $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type )
					? (string) get_permalink( $post )
					: '';
			},
			new ShortLinkClickLog( $wpdb )
		);
	}

	/**
	 * Адреса и перенаправление коротких ссылок.
	 */
	public function shortlink_router(): ShortLinkRouter {
		$prefix = sanitize_title( (string) $this->settings()->general( 'shortlink_prefix' ) );

		return new ShortLinkRouter( $this->shortlinks(), '' !== $prefix ? $prefix : 'go' );
	}

	/**
	 * Модуль «Переходы»: площадки, блоки перехода, подписка и отчёт.
	 */
	public function promo(): PromoModule {
		if ( null === $this->promo ) {
			$this->promo = new PromoModule( $this );
		}

		return $this->promo;
	}

	/**
	 * Блок «Об авторе».
	 */
	public function author_box(): AuthorBox {
		return new AuthorBox( new AuthorProfile(), $this->settings() );
	}

	/**
	 * Блок «Вопросы и ответы»: им же разметка проверяет, выведен ли блок на странице.
	 */
	public function faq_block(): FaqBlock {
		return new FaqBlock( $this->settings() );
	}

	/**
	 * Построитель цепочки хлебных крошек.
	 */
	public function trail(): Trail {
		return new Trail( new WpBreadcrumbData( $this->settings() ) );
	}

	/**
	 * Вывод хлебных крошек на странице.
	 */
	public function breadcrumbs(): BreadcrumbsOutput {
		return new BreadcrumbsOutput( $this->trail() );
	}

	/**
	 * Сборка данных страницы для анализа.
	 */
	public function papers(): PaperFactory {
		global $wpdb;

		return new PaperFactory( $this->resolver(), $this->settings(), $this->redirects(), $this->redirected_posts(), new AuthorProfile(), new PartnerClient( $this->settings() ), new KeywordUsage( $wpdb, $this->analyzer()->matcher() ), $this->game()->games() );
	}

	/**
	 * Вывод текстов рубрики над и под списком записей.
	 */
	public function archive_text(): ArchiveText {
		if ( null === $this->archive_text ) {
			$this->archive_text = new ArchiveText();
		}

		return $this->archive_text;
	}

	/**
	 * Резолвер мета-данных, общий для вывода на сайте, предпросмотра и анализа.
	 */
	public function resolver(): MetaResolver {
		if ( null === $this->resolver ) {
			$this->resolver = new MetaResolver(
				$this->settings(),
				$this->renderer(),
				new WpDataSource(),
				/* translators: %d: номер страницы. */
				__( 'страница %d', 'indexpilot' ),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- имена фильтров задаёт резолвер, все начинаются с indexpilot_.
				static fn( string $hook, $value, Context $context ) => apply_filters( $hook, $value, $context )
			);
		}

		return $this->resolver;
	}

	/**
	 * Настройки, прочитанные из базы и дополненные значениями по умолчанию.
	 */
	public function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = Settings::from_option( $this->site_defaults() );
		}

		return $this->settings;
	}

	/**
	 * Значения по умолчанию для этого сайта: шаблоны колод и архетипов у сайтов разные.
	 *
	 * @return array<string, mixed>
	 */
	public function site_defaults(): array {
		return SiteDefaults::for_profile( $this->site_profile() );
	}

	/**
	 * Профиль сайта: от него зависят шаблоны по умолчанию и способ чтения данных колод.
	 */
	public function site_profile(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		/**
		 * Профиль сайта.
		 *
		 * @param string $profile Одна из констант SiteProfile.
		 */
		return (string) apply_filters( 'indexpilot_site_profile', SiteProfile::from_host( $host ) );
	}

	/**
	 * Игровой модуль: колоды и архетипы.
	 */
	public function game(): GameModule {
		if ( null === $this->game ) {
			$this->game = new GameModule( $this );
		}

		return $this->game;
	}

	/**
	 * Реестр переменных; сторонний код добавляет свои через действие indexpilot_register_variables.
	 */
	public function variables(): VariableRegistry {
		if ( null === $this->variables ) {
			$this->variables = new VariableRegistry();
			( new CoreVariables() )->register( $this->variables );

			/**
			 * Регистрация дополнительных переменных шаблонов.
			 *
			 * @param VariableRegistry $registry Реестр переменных.
			 */
			do_action( 'indexpilot_register_variables', $this->variables );

			// Значения переменных запоминаются на время запроса. Если запись, термин, пользователь или их мета
			// изменились, запомненное устарело: без сброса повторный расчёт в том же запросе вернул бы старые данные.
			$flush = array( $this->variables, 'flush' );

			foreach ( array( 'clean_post_cache', 'clean_term_cache', 'clean_user_cache' ) as $hook ) {
				add_action( $hook, $flush, 10, 0 );
			}

			foreach ( array( 'post', 'term', 'user' ) as $object ) {
				foreach ( array( 'added', 'updated', 'deleted' ) as $event ) {
					add_action( "{$event}_{$object}_meta", $flush, 10, 0 );
				}
			}
		}

		return $this->variables;
	}

	/**
	 * Рендерер шаблонов с разделителем из настроек.
	 */
	public function renderer(): Renderer {
		return new Renderer( $this->variables(), (string) $this->settings()->general( 'separator' ) );
	}
}
