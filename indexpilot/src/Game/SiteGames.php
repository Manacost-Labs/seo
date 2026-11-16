<?php
/**
 * Игры сайта в WordPress: раздел игры, игра записи и рубрики, разметка и шаблоны раздела.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\Language\EnglishStemmer;
use IndexPilot\Analysis\Language\RussianStemmer;
use IndexPilot\Resolver\Context;
use IndexPilot\Schema\Graph;
use IndexPilot\Schema\IdFactory;
use IndexPilot\Settings\Settings;
use IndexPilot\Variables\VariableRegistry;

/**
 * Игра записи определяется по рубрикам один раз — при сохранении — и хранится в поле записи; у записей игры сайта
 * по умолчанию поля нет. Игра рубрики считается по дереву рубрик из опции WordPress, которая загружается вместе
 * с остальными: на страницах сайта это не стоит ни одного запроса к базе.
 */
final class SiteGames {

	/**
	 * Поле записи с игрой; нет поля — игра сайта по умолчанию.
	 */
	public const META = '_indexpilot_game';

	/**
	 * Задача расписания: пересчитать игру всех записей после смены раздела игры или дерева рубрик.
	 */
	public const REBUILD_HOOK = 'indexpilot_games_rebuild';

	/**
	 * Игра сайта по умолчанию.
	 */
	public const DEFAULT_GAME = 'hearthstone';

	/**
	 * Реестр игр.
	 *
	 * @var Games|null
	 */
	private ?Games $games = null;

	/**
	 * Для какого объекта настроек построен реестр.
	 *
	 * @var int
	 */
	private int $games_for = 0;

	/**
	 * Карта «рубрика => родитель» на время запроса.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $parents = null;

	/**
	 * Поиск ключа по играм.
	 *
	 * @var array<string, KeywordMatcher>
	 */
	private array $matchers = array();

	/**
	 * Конструктор.
	 *
	 * @param \Closure $settings Настройки: fn(): Settings — берутся каждый раз, чтобы перечитанные настройки сразу действовали.
	 * @param \Closure $lexicon  Словарь игры сайта по умолчанию со своими синонимами владельца: fn(): GameLexicon.
	 */
	public function __construct( private \Closure $settings, private \Closure $lexicon ) {
	}

	/**
	 * Текущие настройки.
	 */
	private function settings(): Settings {
		return ( $this->settings )();
	}

	/**
	 * Подключает пересчёт игры записи, разметку, переменные и шаблоны раздела.
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 5, 2 );
		add_action( self::REBUILD_HOOK, array( $this, 'rebuild' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings' ), 10, 2 );

		foreach ( array( 'created_category', 'edited_category', 'delete_category' ) as $hook ) {
			add_action( $hook, array( $this, 'schedule' ) );
		}

		add_action( 'indexpilot_schema_graph', array( $this, 'schema' ), 9, 2 );
		add_filter( 'indexpilot_term_config', array( $this, 'term_config' ), 10, 2 );
		add_action(
			'indexpilot_register_variables',
			function ( VariableRegistry $registry ): void {
				$registry->register( 'game', array( $this, 'game_variable' ) );
				$registry->register( 'parent_term', array( $this, 'parent_term_variable' ) );
			}
		);
	}

	/**
	 * Реестр игр: встроенный набор, раздел и название из настроек. Третью игру добавляет фильтр indexpilot_games.
	 */
	public function registry(): Games {
		// Реестр строится заново, если настройки перечитаны.
		if ( null !== $this->games && spl_object_id( $this->settings() ) === $this->games_for ) {
			return $this->games;
		}

		$this->games_for = spl_object_id( $this->settings() );
		$this->matchers  = array();

		$games = array();

		foreach ( Games::defaults() as $game ) {
			if ( self::DEFAULT_GAME !== $game['slug'] ) {
				$root                  = (int) $this->settings()->general( $game['slug'] . '_root_term' );
				$name                  = trim( (string) $this->settings()->general( $game['slug'] . '_schema_name' ) );
				$game['root_term_ids'] = $root > 0 ? array( $root ) : array();
				$game['schema_name']   = '' !== $name ? $name : $game['schema_name'];
			}

			$games[] = $game;
		}

		/**
		 * Игры сайта: slug, name, root_term_ids, schema_name, aliases, lexicon, material.
		 *
		 * @param list<array<string, mixed>> $games Игры.
		 */
		$this->games = new Games( (array) apply_filters( 'indexpilot_games', $games ), self::DEFAULT_GAME );

		return $this->games;
	}

	/**
	 * Есть ли на сайте раздел другой игры.
	 */
	public function active(): bool {
		return array() !== $this->roots();
	}

	/**
	 * Корни разделов других игр: игра => ID рубрик.
	 *
	 * @return array<string, list<int>>
	 */
	public function roots(): array {
		$roots = array();

		foreach ( $this->registry()->all() as $game ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) ( $game['root_term_ids'] ?? array() ) ) ) );

			if ( self::DEFAULT_GAME !== $game['slug'] && array() !== $ids ) {
				$roots[ (string) $game['slug'] ] = $ids;
			}
		}

		return $roots;
	}

	/**
	 * Игра записи из её поля. Поле читается из кэша полей записи: на странице записи запросов нет.
	 *
	 * @param \WP_Post|int $post Запись или её ID.
	 */
	public function post_game( $post ): string {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		$game    = $post_id > 0 && $this->active() ? (string) get_post_meta( $post_id, self::META, true ) : '';

		return '' !== $game && $this->known( $game ) ? $game : self::DEFAULT_GAME;
	}

	/**
	 * Игра рубрики по дереву рубрик. Другие таксономии относятся к игре сайта по умолчанию.
	 *
	 * @param int    $term_id  ID термина.
	 * @param string $taxonomy Таксономия.
	 */
	public function term_game( int $term_id, string $taxonomy = 'category' ): string {
		if ( 'category' !== $taxonomy || $term_id <= 0 || ! $this->active() ) {
			return self::DEFAULT_GAME;
		}

		return $this->registry()->for_terms( GameScope::ancestry( array( $term_id ), $this->parents() ) );
	}

	/**
	 * Игра записи по её текущим рубрикам — то, что записывается в поле при сохранении.
	 *
	 * @param int $post_id ID записи.
	 */
	public function detect( int $post_id ): string {
		if ( ! $this->active() || ! is_object_in_taxonomy( (string) get_post_type( $post_id ), 'category' ) ) {
			return self::DEFAULT_GAME;
		}

		$ids = array_map( 'intval', wp_get_post_categories( $post_id ) );

		return $this->registry()->for_terms( GameScope::ancestry( $ids, $this->parents() ) );
	}

	/**
	 * ID рубрик раздела игры: корень и все вложенные.
	 *
	 * @param string $game Игра.
	 * @return list<int>
	 */
	public function section_terms( string $game ): array {
		$roots = $this->roots()[ $game ] ?? array();

		return array() === $roots ? array() : GameScope::descendants( $roots, $this->parents() );
	}

	/**
	 * Название игры для людей.
	 *
	 * @param string $game Игра.
	 */
	public function name( string $game ): string {
		return $this->known( $game ) ? (string) $this->registry()->get( $game )['name'] : '';
	}

	/**
	 * Словарь игры: у игры сайта по умолчанию — со своими синонимами владельца.
	 *
	 * @param string $game Игра.
	 */
	public function lexicon( string $game ): GameLexicon {
		return self::DEFAULT_GAME === $game || ! $this->known( $game ) ? ( $this->lexicon )() : $this->registry()->lexicon( $game );
	}

	/**
	 * Поиск ключа со словарём игры.
	 *
	 * @param string $game Игра.
	 */
	public function matcher( string $game ): KeywordMatcher {
		$game = $this->known( $game ) ? $game : self::DEFAULT_GAME;

		if ( ! isset( $this->matchers[ $game ] ) ) {
			$this->matchers[ $game ] = new KeywordMatcher( new RussianStemmer(), new EnglishStemmer(), $this->lexicon( $game ) );
		}

		return $this->matchers[ $game ];
	}

	/**
	 * Класс типов материалов игры: MaterialType или WowMaterialType.
	 *
	 * @param string $game Игра.
	 */
	public function material_types( string $game ): string {
		$class = $this->known( $game ) ? (string) ( $this->registry()->get( $game )['material'] ?? '' ) : '';

		return '' !== $class && class_exists( $class ) ? $class : MaterialType::class;
	}

	/**
	 * Текущий патч игры из настроек.
	 *
	 * @param string $game Игра.
	 */
	public function patch( string $game ): Patch {
		return self::patch_for( $this->settings(), $game );
	}

	/**
	 * Текущий патч игры: у игры по умолчанию — patch_version и patch_date, у другой — с приставкой её слага.
	 *
	 * @param Settings $settings Настройки.
	 * @param string   $game     Игра.
	 */
	public static function patch_for( Settings $settings, string $game ): Patch {
		if ( self::DEFAULT_GAME === $game || '' === $game ) {
			return new Patch( (string) $settings->general( 'patch_version' ), (string) $settings->general( 'patch_date' ) );
		}

		return new Patch( (string) $settings->general( $game . '_patch_version' ), (string) $settings->general( $game . '_patch_date' ), 'wow' === $game ? Patch::TRACKED_WOW : Patch::TRACKED );
	}

	/**
	 * Запоминает игру сохранённой записи. Запись игры по умолчанию поля не получает.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function on_save( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) ) {
			return;
		}

		$this->store( (int) $post->ID, $this->detect( (int) $post->ID ) );
	}

	/**
	 * Раздел игры в настройках сменился: игра записей пересчитывается задачей расписания.
	 *
	 * @param mixed $old   Прежнее значение настроек.
	 * @param mixed $value Новое значение настроек.
	 */
	public function on_settings( $old, $value ): void {
		$changed = false;

		foreach ( $this->registry()->all() as $game ) {
			$key     = $game['slug'] . '_root_term';
			$changed = $changed || (int) ( $old['general'][ $key ] ?? 0 ) !== (int) ( $value['general'][ $key ] ?? 0 );
		}

		if ( $changed ) {
			$this->games   = null;
			$this->parents = null;
			$this->force_schedule();
		}
	}

	/**
	 * Рубрика создана, изменена или удалена: игра записей могла смениться.
	 */
	public function schedule(): void {
		$this->parents = null;

		if ( $this->active() ) {
			$this->force_schedule();
		}
	}

	/**
	 * Пересчитывает игру всех записей: поле остаётся только у записей в разделах других игр.
	 *
	 * @return array<string, int> Игра => число записей.
	 */
	public function rebuild(): array {
		$this->games   = null;
		$this->parents = null;

		delete_post_meta_by_key( self::META );

		$counts = array();

		foreach ( array_keys( $this->roots() ) as $game ) {
			$ids = get_posts(
				array(
					'post_type'        => 'any',
					'post_status'      => 'any',
					'fields'           => 'ids',
					'posts_per_page'   => -1,
					'no_found_rows'    => true,
					'suppress_filters' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- по расписанию, раз после смены раздела.
					'tax_query'        => array(
						array(
							'taxonomy'         => 'category',
							'terms'            => $this->section_terms( $game ),
							'include_children' => false,
						),
					),
				)
			);

			$counts[ $game ] = 0;

			foreach ( $ids as $post_id ) {
				$found = $this->detect( (int) $post_id );
				$this->store( (int) $post_id, $found );
				$counts[ $game ] += $found === $game ? 1 : 0;
			}
		}

		// Страницы классов раздела и упоминания записей пересобираются вслед за игрой записей.
		if ( ! wp_next_scheduled( MentionIndex::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, MentionIndex::REBUILD_HOOK );
		}

		return $counts;
	}

	/**
	 * Разметка: о какой игре запись или рубрика раздела игры.
	 *
	 * @param Graph   $graph   Граф страницы.
	 * @param Context $context Контекст страницы.
	 */
	public function schema( $graph, $context ): void {
		if ( ! $graph instanceof Graph || ! $context instanceof Context ) {
			return;
		}

		if ( Context::POST === $context->type && $context->object_id > 0 ) {
			$page = $graph->first( 'WebPage' );
			$node = null !== $page ? $graph->node( IdFactory::part( (string) $page['url'], 'article' ) ) : null;
			$game = $this->post_game( $context->object_id );
		} elseif ( Context::TERM === $context->type && $this->active() ) {
			$node = $graph->first( 'CollectionPage' );
			$game = $this->term_game( $context->object_id, $context->subtype );

			// У рубрик игры сайта по умолчанию разметка не меняется.
			$node = self::DEFAULT_GAME !== $game ? $node : null;
		} else {
			return;
		}

		if ( null === $node || ! empty( $node['about'] ) || ! $this->known( $game ) ) {
			return;
		}

		$node['about'] = array(
			'@type' => 'VideoGame',
			'name'  => (string) $this->registry()->get( $game )['schema_name'],
		);

		$graph->add( $node );
	}

	/**
	 * Шаблоны заголовка и описания рубрик раздела другой игры — из её настроек.
	 *
	 * @param array<string, mixed> $config  Настройки таксономии.
	 * @param Context              $context Контекст страницы.
	 * @return array<string, mixed>
	 */
	public function term_config( $config, $context ): array {
		$config = (array) $config;

		if ( ! $context instanceof Context || Context::TERM !== $context->type ) {
			return $config;
		}

		$game = $this->term_game( $context->object_id, $context->subtype );

		// Корень раздела («World of Warcraft») сам называет игру: ему хватает обычного шаблона рубрик.
		if ( self::DEFAULT_GAME === $game || in_array( $context->object_id, $this->roots()[ $game ] ?? array(), true ) ) {
			return $config;
		}

		$title       = trim( (string) $this->settings()->general( $game . '_term_title' ) );
		$description = trim( (string) $this->settings()->general( $game . '_term_description' ) );

		if ( '' !== $title ) {
			$config['title'] = $title;
		}

		if ( '' !== $description ) {
			$config['fallback_description'] = $description;
		}

		return $config;
	}

	/**
	 * Переменная %game%: название игры записи или рубрики.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function game_variable( Context $context ): string {
		switch ( $context->type ) {
			case Context::POST:
				return $this->name( $this->post_game( $context->object_id ) );
			case Context::TERM:
				return $this->name( $this->term_game( $context->object_id, $context->subtype ) );
			default:
				return $this->name( self::DEFAULT_GAME );
		}
	}

	/**
	 * Переменная %parent_term%: родительская рубрика открытой рубрики («Жрец» у «Тьмы»). Корень раздела игры
	 * не подставляется: его название уже даёт %game%.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function parent_term_variable( Context $context ): string {
		if ( Context::TERM !== $context->type ) {
			return '';
		}

		$term = get_term( $context->object_id, $context->subtype );

		if ( ! $term instanceof \WP_Term || $term->parent <= 0 ) {
			return '';
		}

		foreach ( $this->roots() as $ids ) {
			if ( in_array( (int) $term->parent, $ids, true ) ) {
				return '';
			}
		}

		$parent = get_term( (int) $term->parent, $context->subtype );

		return $parent instanceof \WP_Term ? wp_strip_all_tags( html_entity_decode( $parent->name, ENT_QUOTES, 'UTF-8' ) ) : '';
	}

	/**
	 * Карта «рубрика => родитель» из дерева рубрик WordPress (опция category_children).
	 *
	 * @return array<int, int>
	 */
	public function parents(): array {
		if ( null !== $this->parents ) {
			return $this->parents;
		}

		$this->parents = array();

		foreach ( (array) _get_term_hierarchy( 'category' ) as $parent => $children ) {
			foreach ( (array) $children as $child ) {
				$this->parents[ (int) $child ] = (int) $parent;
			}
		}

		return $this->parents;
	}

	/**
	 * Есть ли такая игра в реестре.
	 *
	 * @param string $game Игра.
	 */
	private function known( string $game ): bool {
		foreach ( $this->registry()->all() as $entry ) {
			if ( $entry['slug'] === $game ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Записывает игру в поле записи; у игры по умолчанию поле удаляется.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $game    Игра.
	 */
	private function store( int $post_id, string $game ): void {
		if ( self::DEFAULT_GAME === $game ) {
			if ( '' !== (string) get_post_meta( $post_id, self::META, true ) ) {
				delete_post_meta( $post_id, self::META );
			}

			return;
		}

		update_post_meta( $post_id, self::META, $game );
	}

	/**
	 * Ставит пересчёт в расписание, если он ещё не стоит.
	 */
	private function force_schedule(): void {
		if ( ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::REBUILD_HOOK );
		}
	}
}
