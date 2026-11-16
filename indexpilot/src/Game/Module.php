<?php
/**
 * Игровой модуль: колоды и архетипы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Schema\Graph;
use IndexPilot\Schema\IdFactory;
use IndexPilot\Settings\Settings;
use IndexPilot\Site\SiteProfile;
use IndexPilot\Sitemap\EntryPolicy;
use IndexPilot\Variables\VariableRegistry;

/**
 * Подключает к ядру плагина всё, что относится к колодам: переменные шаблонов, текст для анализа,
 * правило версий, разметку и блок связанных ссылок. Ядро о колодах ничего не знает —
 * модуль работает через его фильтры и действия.
 */
final class Module {

	public const VERSIONS_OPTION = 'indexpilot_deck_versions';
	public const REBUILD_HOOK    = 'indexpilot_deck_versions_rebuild';
	public const ARCHETYPE       = 'deck_archetype';

	private const LIST_SIZE = 20;

	/**
	 * Данные колод.
	 *
	 * @var DeckData|null
	 */
	private ?DeckData $decks = null;

	/**
	 * Актуальные версии: ID колоды => ID актуальной версии.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $versions = null;

	/**
	 * Страницы-ответы классов и архетипов.
	 *
	 * @var Hub|null
	 */
	private ?Hub $hub = null;

	/**
	 * Игры сайта: раздел World of Warcraft и игра записей.
	 *
	 * @var SiteGames|null
	 */
	private ?SiteGames $games = null;

	/**
	 * Упоминания классов и архетипов.
	 *
	 * @var MentionIndex|null
	 */
	private ?MentionIndex $mention_index = null;

	/**
	 * Конструктор.
	 *
	 * @param Plugin $plugin Плагин: настройки и профиль сайта читаются по мере надобности.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Подключает модуль. Вызывается до первого обращения к переменным шаблонов.
	 */
	public function register(): void {
		// Игры сайта подключаются первыми: им нужна своя переменная %game% и игра записи до остальных обработчиков.
		$this->games()->register();
		( new GuideHub( $this->games(), $this->plugin->settings() ) )->register();

		add_action(
			'indexpilot_register_variables',
			function ( VariableRegistry $registry ): void {
				( new DeckVariables( new LazyDeckData( fn(): DeckData => $this->decks() ) ) )->register( $registry );
			}
		);

		add_filter( 'indexpilot_analysis_content', array( $this, 'analysis_content' ), 10, 2 );
		add_filter( 'indexpilot_canonical', array( $this, 'canonical' ), 10, 2 );
		add_action( 'indexpilot_schema_graph', array( $this, 'schema' ), 10, 2 );
		add_action( self::REBUILD_HOOK, array( $this, 'rebuild_versions' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_deck_change' ), 30 );
		add_action( 'deleted_post', array( $this, 'on_deck_change' ) );

		add_action(
			'init',
			function (): void {
				add_shortcode( 'indexpilot_deck_links', fn(): string => $this->links_html() );
			},
			100
		);

		$this->hub()->register();
		$this->mention_index()->register();

		// Панель записи и список записей: подсказки ссылок на классы и архетипы, отметка «устарело после патча».
		add_action( 'indexpilot_metabox_analysis', array( $this, 'mention_hints' ) );
		add_action( 'indexpilot_post_list_flags', array( $this, 'patch_flag' ) );

		if ( is_admin() ) {
			( new \IndexPilot\Admin\PatchReport( $this ) )->register();
		}
	}

	/**
	 * Страницы-ответы классов и архетипов.
	 */
	public function hub(): Hub {
		if ( null === $this->hub ) {
			$this->hub = new Hub( $this, $this->plugin->settings(), fn(): Lexicon => $this->plugin->lexicon() );
		}

		return $this->hub;
	}

	/**
	 * Игры сайта: игра записи и рубрики, словари и патчи игр.
	 */
	public function games(): SiteGames {
		if ( null === $this->games ) {
			$this->games = new SiteGames( fn(): Settings => $this->plugin->settings(), fn(): Lexicon => $this->plugin->lexicon() );
		}

		return $this->games;
	}

	/**
	 * Упоминания классов и архетипов в записях.
	 */
	public function mention_index(): MentionIndex {
		if ( null === $this->mention_index ) {
			$this->mention_index = new MentionIndex( fn(): Lexicon => $this->plugin->lexicon(), fn(): SiteGames => $this->games() );
		}

		return $this->mention_index;
	}

	/**
	 * Текущий патч из настроек.
	 */
	public function patch(): Patch {
		return new Patch( (string) $this->plugin->settings()->general( 'patch_version' ), (string) $this->plugin->settings()->general( 'patch_date' ) );
	}

	/**
	 * Устарела ли запись после патча. Архивная колода и старая версия колоды не устаревают: их уже заменила актуальная.
	 *
	 * @param \WP_Post   $post  Запись.
	 * @param Patch|null $patch Патч; null — из настроек.
	 */
	public function outdated( \WP_Post $post, ?Patch $patch = null ): bool {
		$game = $this->games()->post_game( $post );

		// Запись другой игры сверяется с патчем своей игры.
		$patch = SiteGames::DEFAULT_GAME !== $game ? $this->games()->patch( $game ) : ( $patch ?? $this->patch() );

		if ( ! $patch->active() || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( DeckVariables::POST_TYPE === $post->post_type && ( $this->decks()->deck( (int) $post->ID )->archived || $this->current_version( (int) $post->ID ) !== (int) $post->ID ) ) {
			return false;
		}

		return $patch->outdated( PostMaterial::detect( $post ), PostDates::modified( 'Y-m-d H:i:s', false, $post ) );
	}

	/**
	 * Отметка «устарело после патча» в колонке SEO списка записей.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function patch_flag( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$patch = $this->games()->patch( $this->games()->post_game( $post ) );

		if ( ! $this->outdated( $post, $patch ) ) {
			return;
		}

		printf(
			'<span class="indexpilot-flag" data-flag="patch" title="%1$s">%2$s</span>',
			/* translators: 1: номер патча, 2: дата выхода патча. */
			esc_attr( sprintf( __( 'Запись не обновлялась с выхода патча %1$s (%2$s): проверьте, что состав и советы ещё верны', 'indexpilot' ), $patch->version, mysql2date( (string) get_option( 'date_format' ), $patch->date ) ) ),
			/* translators: %s: номер патча. */
			esc_html( sprintf( __( 'устарело после патча %s', 'indexpilot' ), $patch->version ) )
		);
	}

	/**
	 * Подсказки на вкладке «Анализ» панели записи: упомянутые классы и архетипы, на страницы которых текст не ссылается.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function mention_hints( $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->active() || '' === trim( (string) $post->post_content ) ) {
			return;
		}

		$own = 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
		// Классы ищутся словарём игры записи и ведут на страницы классов той же игры.
		$missing = Mentions::unlinked( $this->mention_index()->mentions_for( $this->games()->post_game( $post ) )->find( MentionIndex::text( $post ) ), (string) $post->post_content, $own );

		if ( array() === $missing ) {
			return;
		}

		echo '<div class="indexpilot-mention-hints"><h4>' . esc_html__( 'Классы и архетипы без ссылки', 'indexpilot' ) . ' ';
		\IndexPilot\Admin\HelpTip::render(
			__( 'ничего: плагин сам нашёл в сохранённом тексте классы и архетипы, у которых на сайте есть своя страница. Поставьте ссылку на неё на первое упоминание.', 'indexpilot' ),
			__( 'страница класса или архетипа собирает все колоды и гайды по нему: читатель переходит к ним, а поисковик видит, какая страница главная по этой теме.', 'indexpilot' )
		);
		echo '</h4><ul>';

		foreach ( $missing as $mention ) {
			$text = Mentions::ARCHETYPE === $mention['type']
				/* translators: %s: название архетипа. */
				? __( 'В тексте упомянут архетип %s — сошлитесь на его страницу:', 'indexpilot' )
				/* translators: %s: название класса. */
				: __( 'В тексте упомянут класс %s — сошлитесь на его страницу:', 'indexpilot' );

			printf(
				'<li>%1$s <a href="%2$s" target="_blank" rel="noopener"><code>%3$s</code></a></li>',
				esc_html( sprintf( $text, $mention['name'] ) ),
				esc_url( $mention['url'] ),
				esc_html( $mention['url'] )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Данные колод для этого сайта.
	 */
	public function decks(): DeckData {
		if ( null === $this->decks ) {
			$reader      = new WpPostReader();
			$this->decks = SiteProfile::KOLODA === $this->plugin->site_profile()
				? new KolodaDeckAdapter( $reader )
				: new ManacostDeckAdapter( $reader );
		}

		return $this->decks;
	}

	/**
	 * Есть ли на сайте колоды.
	 */
	public function active(): bool {
		return post_type_exists( DeckVariables::POST_TYPE );
	}

	/**
	 * У колоды без текста анализ оценивает описание, собранное из её полей.
	 *
	 * @param string  $content Текст страницы.
	 * @param Context $context Контекст страницы.
	 */
	public function analysis_content( $content, $context ): string {
		$content = (string) $content;

		if ( ! $context instanceof Context || ! $this->is_deck( $context ) || '' !== trim( wp_strip_all_tags( $content ) ) ) {
			return $content;
		}

		return '<p>' . esc_html( DeckDescription::text( $this->decks()->deck( $context->object_id ) ) ) . '</p>';
	}

	/**
	 * Старая версия колоды указывает каноническим адресом на актуальную.
	 *
	 * @param string  $canonical Канонический адрес страницы.
	 * @param Context $context   Контекст страницы.
	 */
	public function canonical( $canonical, $context ): string {
		$canonical = (string) $canonical;

		if ( ! $context instanceof Context || ! $this->is_deck( $context ) || $context->page > 1 || ! $this->plugin->settings()->general( 'deck_version_canonical' ) ) {
			return $canonical;
		}

		// Каноникал, заданный редактором вручную, важнее правила.
		if ( '' !== trim( (string) get_post_meta( $context->object_id, MetaKeys::CANONICAL, true ) ) ) {
			return $canonical;
		}

		$current = $this->current_version( $context->object_id );
		$url     = $current !== $context->object_id ? get_permalink( $current ) : false;

		return is_string( $url ) && 'publish' === get_post_status( $current ) ? $url : $canonical;
	}

	/**
	 * Добавляет в разметку узел колоды или список колод архетипа.
	 *
	 * @param Graph   $graph   Граф страницы.
	 * @param Context $context Контекст страницы.
	 */
	public function schema( $graph, $context ): void {
		if ( ! $graph instanceof Graph || ! $context instanceof Context || ! $this->active() ) {
			return;
		}

		if ( Context::POST === $context->type && $context->object_id > 0 ) {
			$this->add_mentions( $graph, $context->object_id );
		}

		if ( Context::TERM === $context->type && Hub::CLASS_TAXONOMY === $context->subtype && 1 === $context->page ) {
			$page = $graph->first( 'CollectionPage' );
			$term = get_term( $context->object_id, Hub::CLASS_TAXONOMY );

			if ( null !== $page && $term instanceof \WP_Term ) {
				$decks = array();

				foreach ( $this->hub()->data( $term )['decks'] as $deck ) {
					$decks[] = array(
						'name' => (string) $deck['title'],
						'url'  => (string) $deck['url'],
					);
				}

				DeckSchema::add_deck_list( $graph, (string) $page['url'], $decks );
			}

			return;
		}

		if ( $this->is_deck( $context ) ) {
			$page = $graph->first( 'WebPage' );

			// Старая версия колоды (каноникал ведёт на актуальную) отдельной колодой не описывается: у неё остаётся
			// только узел страницы под собственным адресом.
			$canonical = null !== $page ? (string) $this->plugin->resolver()->indexing( $context )['canonical'] : '';

			if ( null !== $page && ( '' === $canonical || EntryPolicy::same_address( $canonical, (string) $page['url'] ) ) ) {
				DeckSchema::add_deck( $graph, home_url( '/' ), (string) $page['url'], $this->decks()->deck( $context->object_id ), (string) get_post_time( 'P', false, $context->object_id ) );
			}

			return;
		}

		if ( Context::TERM === $context->type && self::ARCHETYPE === $context->subtype && 1 === $context->page ) {
			$page  = $graph->first( 'CollectionPage' );
			$decks = array();

			foreach ( $this->archetype_decks( $context->object_id, 0 ) as $deck_id ) {
				$decks[] = array(
					'name' => wp_strip_all_tags( get_the_title( $deck_id ) ),
					'url'  => (string) get_permalink( $deck_id ),
				);
			}

			if ( null !== $page ) {
				DeckSchema::add_deck_list( $graph, (string) $page['url'], $decks );
			}
		}
	}

	/**
	 * Дополняет узел статьи: о чём она (игра Hearthstone) и какие классы и архетипы в ней упомянуты —
	 * со ссылками на их страницы на сайте. Упоминание без своей страницы в разметку не идёт.
	 *
	 * @param Graph $graph   Граф страницы.
	 * @param int   $post_id ID записи.
	 */
	private function add_mentions( Graph $graph, int $post_id ): void {
		$page    = $graph->first( 'WebPage' );
		$article = null !== $page ? $graph->node( IdFactory::part( (string) $page['url'], 'article' ) ) : null;
		$post    = get_post( $post_id );

		if ( null === $article || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( empty( $article['about'] ) ) {
			$article['about'] = array(
				'@type' => 'VideoGame',
				'name'  => 'Hearthstone',
			);
		}

		$mentions = array();
		$seen     = array();

		foreach ( $this->mention_index()->for_post( $post ) as $mention ) {
			if ( '' !== $mention['url'] && ! isset( $seen[ $mention['url'] ] ) ) {
				$seen[ $mention['url'] ] = true;
				$mentions[]              = array(
					'@type' => 'Thing',
					'name'  => $mention['name'],
					'url'   => $mention['url'],
				);
			}
		}

		if ( array() !== $mentions ) {
			$article['mentions'] = array_merge( (array) ( $article['mentions'] ?? array() ), $mentions );
		}

		$graph->add( $article );
	}

	/**
	 * ID актуальной версии колоды. Пока соответствие не построено, каждая колода считается актуальной.
	 *
	 * @param int $deck_id ID колоды.
	 */
	public function current_version( int $deck_id ): int {
		return $this->versions()[ $deck_id ] ?? $deck_id;
	}

	/**
	 * Соответствие «колода → актуальная версия» из сохранённого. На страницах сайта оно не пересчитывается:
	 * пересчёт идёт отдельной задачей расписания после изменения колод или командой.
	 *
	 * @return array<int, int>
	 */
	public function versions(): array {
		if ( null === $this->versions ) {
			$saved = get_option( self::VERSIONS_OPTION, null );

			if ( is_array( $saved ) && is_array( $saved['map'] ?? null ) ) {
				$this->versions = array_map( 'intval', $saved['map'] );
			} elseif ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				$this->versions = $this->rebuild_versions();
			} else {
				return array();
			}
		}

		return $this->versions;
	}

	/**
	 * Пересчитывает актуальные версии всех опубликованных колод.
	 *
	 * @return array<int, int>
	 */
	public function rebuild_versions(): array {
		global $wpdb;

		$this->versions = array();

		if ( ! $this->active() ) {
			return $this->versions;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один столбец по индексу; результат сохраняется в опции.
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", DeckVariables::POST_TYPE ) ) );
		$rows = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// Поля и термины пачки читаются двумя запросами, а не по запросу на колоду.
			_prime_post_caches( $chunk, true, true );

			foreach ( $chunk as $id ) {
				$deck   = $this->decks()->deck( $id );
				$rows[] = array(
					'id'       => $id,
					'group'    => $deck->group,
					'date'     => $deck->date,
					'archived' => $deck->archived,
				);
			}

			if ( function_exists( 'wp_cache_flush_runtime' ) && count( $ids ) > 500 ) {
				wp_cache_flush_runtime();
			}
		}

		$this->versions = DeckVersionRule::current( $rows );

		update_option(
			self::VERSIONS_OPTION,
			array(
				'built' => time(),
				'map'   => $this->versions,
			),
			false
		);

		return $this->versions;
	}

	/**
	 * После изменения колоды назначает пересчёт версий. Пересчёт не идёт в том же запросе:
	 * при массовой загрузке колод он выполнится один раз, а не на каждую колоду.
	 *
	 * @param int $post_id ID записи.
	 */
	public function on_deck_change( $post_id ): void {
		if ( DeckVariables::POST_TYPE !== get_post_type( (int) $post_id ) || wp_is_post_revision( (int) $post_id ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REBUILD_HOOK );
		}
	}

	/**
	 * Блок связанных ссылок для страницы колоды: архетип, гайды по нему и другие колоды архетипа.
	 *
	 * @param int $post_id ID колоды; 0 — текущая запись.
	 */
	public function links_html( int $post_id = 0 ): string {
		$post = get_post( $post_id > 0 ? $post_id : null );

		if ( ! $post instanceof \WP_Post || DeckVariables::POST_TYPE !== $post->post_type ) {
			return '';
		}

		$deck  = $this->decks()->deck( (int) $post->ID );
		$terms = taxonomy_exists( self::ARCHETYPE ) ? get_the_terms( $post, self::ARCHETYPE ) : false;
		$term  = is_array( $terms ) ? $terms[0] : null;

		$guides = array();
		if ( '' !== $deck->archetype ) {
			$found = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					's'              => $deck->archetype,
					'posts_per_page' => 20,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			foreach ( $found as $guide ) {
				$guides[ (int) $guide->ID ] = wp_strip_all_tags( $guide->post_title );
			}
		}

		$picked = RelatedLinks::pick( $deck, $guides, $term instanceof \WP_Term ? $this->archetype_decks( (int) $term->term_id, (int) $post->ID ) : array(), 5, $this->plugin->analyzer()->matcher() );
		$html   = '';
		$link   = $term instanceof \WP_Term ? get_term_link( $term ) : '';

		if ( $term instanceof \WP_Term && is_string( $link ) ) {
			/* translators: %s: название архетипа со ссылкой. */
			$html .= '<p class="indexpilot-deck-archetype">' . sprintf( esc_html__( 'Архетип: %s', 'indexpilot' ), '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>' ) . '</p>';
		}

		$html .= $this->list_html( __( 'Гайды по этой колоде', 'indexpilot' ), $picked['guides'] );
		$html .= $this->list_html( __( 'Другие колоды архетипа', 'indexpilot' ), $picked['decks'] );

		return '' === $html ? '' : '<nav class="indexpilot-deck-links" aria-label="' . esc_attr__( 'Связанные материалы', 'indexpilot' ) . '">' . $html . '</nav>';
	}

	/**
	 * Актуальные колоды архетипа, новые первыми.
	 *
	 * @param int $term_id ID термина архетипа.
	 * @param int $exclude ID колоды, которую не включать.
	 * @return list<int>
	 */
	private function archetype_decks( int $term_id, int $exclude ): array {
		$ids = get_posts(
			array(
				'post_type'      => DeckVariables::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::LIST_SIZE * 3,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- выборка колод одного архетипа.
				'tax_query'      => array(
					array(
						'taxonomy' => self::ARCHETYPE,
						'terms'    => $term_id,
					),
				),
			)
		);

		$decks = array();
		$ids   = array_map( 'intval', $ids );

		// Заголовки и адреса колод списка читаются дальше по одной: записи загружаются заранее одним запросом.
		_prime_post_caches( $ids, false, false );

		foreach ( $ids as $id ) {
			// В список идут только актуальные версии: у старых канонический адрес ведёт на них же.
			if ( $id !== $exclude && $this->current_version( $id ) === $id ) {
				$decks[] = $id;
			}

			if ( count( $decks ) >= self::LIST_SIZE ) {
				break;
			}
		}

		return $decks;
	}

	/**
	 * Список ссылок на записи с заголовком.
	 *
	 * @param string    $heading Заголовок списка.
	 * @param list<int> $ids     ID записей.
	 */
	private function list_html( string $heading, array $ids ): string {
		if ( array() === $ids ) {
			return '';
		}

		$items = '';
		foreach ( $ids as $id ) {
			$items .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( (string) get_permalink( $id ) ), esc_html( wp_strip_all_tags( get_the_title( $id ) ) ) );
		}

		return '<h2>' . esc_html( $heading ) . '</h2><ul>' . $items . '</ul>';
	}

	/**
	 * Является ли страница колодой.
	 *
	 * @param Context $context Контекст страницы.
	 */
	private function is_deck( Context $context ): bool {
		return Context::POST === $context->type && DeckVariables::POST_TYPE === $context->subtype && $context->object_id > 0;
	}
}
