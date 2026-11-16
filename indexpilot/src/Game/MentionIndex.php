<?php
/**
 * Список страниц классов и архетипов для поиска упоминаний.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

/**
 * Хранит адреса страниц классов и архетипов сайта и найденные в записях упоминания.
 *
 * Список терминов (на hs-manacost.ru около 800 архетипов) собирается один раз и лежит в отдельной опции, которая
 * не загружается вместе с остальными настройками. После изменения терминов он пересобирается отдельной задачей
 * расписания через минуту — при массовой загрузке колод один раз, а не на каждый новый архетип.
 *
 * Упоминания записи считаются при её сохранении и хранятся в её поле: на странице сайта их читают из уже
 * загруженных полей записи, без запросов к базе. Запись, сохранённая до появления этой функции, получает поле
 * при первом показе.
 */
final class MentionIndex {

	public const OPTION       = 'indexpilot_mention_index';
	public const META         = '_indexpilot_mentions';
	public const REBUILD_HOOK = 'indexpilot_mention_index_rebuild';

	/**
	 * Таксономия классов колод.
	 */
	public const CLASS_TAXONOMY = 'deck_class';

	/**
	 * Таксономии, при изменении которых список собирается заново.
	 */
	private const WATCHED = array( self::CLASS_TAXONOMY, Module::ARCHETYPE, 'category' );

	/**
	 * Упоминания по готовому списку.
	 *
	 * @var Mentions|null
	 */
	private ?Mentions $mentions = null;

	/**
	 * Упоминания других игр.
	 *
	 * @var array<string, Mentions>
	 */
	private array $others = array();

	/**
	 * Конструктор.
	 *
	 * @param \Closure(): Lexicon        $lexicon Игровой словарь; создаётся только при надобности.
	 * @param (\Closure(): SiteGames)|null $games   Игры сайта: у записи другой игры свои классы и их страницы.
	 */
	public function __construct( private \Closure $lexicon, private ?\Closure $games = null ) {
	}

	/**
	 * Упоминания для записи игры: у игры сайта по умолчанию — классы и архетипы, у другой игры — её классы
	 * со страницами из её раздела.
	 *
	 * @param string $game Игра записи.
	 */
	public function mentions_for( string $game ): Mentions {
		$games = null !== $this->games ? ( $this->games )() : null;

		if ( null === $games || SiteGames::DEFAULT_GAME === $game ) {
			return $this->mentions();
		}

		if ( ! isset( $this->others[ $game ] ) ) {
			$this->others[ $game ] = new Mentions( $games->lexicon( $game ), (array) ( $this->data()['games'][ $game ] ?? array() ) );
		}

		return $this->others[ $game ];
	}

	/**
	 * Подключает пересчёт.
	 */
	public function register(): void {
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( $this, 'on_term_change' ), 10, 3 );
		}

		add_action( self::REBUILD_HOOK, array( $this, 'rebuild' ) );
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 20, 2 );
	}

	/**
	 * Термин класса, архетипа или рубрики изменился: список пересоберётся задачей расписания.
	 *
	 * @param int    $term_id  ID термина.
	 * @param int    $tt_id    ID термина в таксономии.
	 * @param string $taxonomy Таксономия.
	 */
	public function on_term_change( $term_id, $tt_id, $taxonomy ): void {
		if ( in_array( (string) $taxonomy, self::WATCHED, true ) && ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REBUILD_HOOK );
		}
	}

	/**
	 * Пересобирает список терминов.
	 *
	 * @return array{built: int, classes: array<string, string>, archetypes: list<array{name: string, url: string}>}
	 */
	public function rebuild(): array {
		$data = array(
			'built'      => time(),
			'classes'    => $this->classes(),
			'archetypes' => $this->archetypes(),
			'games'      => $this->game_classes(),
		);

		update_option( self::OPTION, $data, false );
		$this->mentions = null;
		$this->others   = array();

		return $data;
	}

	/**
	 * Считает упоминания сохранённой записи и кладёт их в её поле.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function on_save( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$this->store( $post );
	}

	/**
	 * Упоминания записи: из её поля, а если поля ещё нет — посчитанные и сохранённые.
	 *
	 * @param \WP_Post $post Запись.
	 * @return list<array{type: string, name: string, url: string}>
	 */
	public function for_post( \WP_Post $post ): array {
		$saved = get_post_meta( $post->ID, self::META, true );

		if ( is_array( $saved ) && is_array( $saved['items'] ?? null ) ) {
			return $saved['items'];
		}

		return 'publish' === $post->post_status ? $this->store( $post ) : $this->mentions()->find( self::text( $post ) );
	}

	/**
	 * Упоминания по текущему списку терминов.
	 */
	public function mentions(): Mentions {
		if ( null === $this->mentions ) {
			$data           = $this->data();
			$this->mentions = new Mentions( ( $this->lexicon )(), $data['classes'], $data['archetypes'] );
		}

		return $this->mentions;
	}

	/**
	 * Текст записи для поиска упоминаний: название и текст без разметки и шорткодов.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public static function text( \WP_Post $post ): string {
		return $post->post_title . "\n" . wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
	}

	/**
	 * Адреса страниц классов и архетипов: из сохранённой опции, а при первом обращении — собранные.
	 *
	 * @return array{built: int, classes: array<string, string>, archetypes: list<array{name: string, url: string}>}
	 */
	public function data(): array {
		$saved = get_option( self::OPTION, null );

		if ( is_array( $saved ) && isset( $saved['built'], $saved['classes'], $saved['archetypes'] ) ) {
			return $saved;
		}

		return $this->rebuild();
	}

	/**
	 * Считает упоминания записи и сохраняет их в её поле.
	 *
	 * @param \WP_Post $post Запись.
	 * @return list<array{type: string, name: string, url: string}>
	 */
	private function store( \WP_Post $post ): array {
		$game  = null !== $this->games ? ( $this->games )()->post_game( $post ) : SiteGames::DEFAULT_GAME;
		$items = $this->mentions_for( $game )->find( self::text( $post ) );

		update_post_meta(
			$post->ID,
			self::META,
			array(
				'built' => (int) $this->data()['built'],
				'items' => $items,
			)
		);

		return $items;
	}

	/**
	 * Страницы классов: термины таксономии классов колод, а если её нет — рубрики с названием класса
	 * («Жрец», «Колоды жреца»).
	 *
	 * @return array<string, string> Слаг класса из словаря => адрес.
	 */
	private function classes(): array {
		$taxonomy = taxonomy_exists( self::CLASS_TAXONOMY ) ? self::CLASS_TAXONOMY : 'category';
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 200,
			)
		);
		$lexicon  = ( $this->lexicon )();
		$result   = array();
		$games    = null !== $this->games ? ( $this->games )() : null;
		$skip     = array();

		// Рубрики раздела другой игры («World of Warcraft → Жрец») — не страницы классов этой игры.
		if ( null !== $games && 'category' === $taxonomy ) {
			foreach ( array_keys( $games->roots() ) as $game ) {
				$skip += array_fill_keys( $games->section_terms( $game ), true );
			}
		}

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( isset( $skip[ (int) $term->term_id ] ) ) {
				continue;
			}

			$classes = $lexicon->classes( $term->name );
			$words   = preg_split( '/\s+/u', trim( $term->name ) );

			// Рубрика «Гайды по жрецу и магу» — не страница класса.
			if ( 1 !== count( $classes ) || ( 'category' === $taxonomy && count( (array) $words ) > 3 ) || isset( $result[ $classes[0] ] ) ) {
				continue;
			}

			$url = get_term_link( $term );

			if ( is_string( $url ) ) {
				$result[ $classes[0] ] = $url;
			}
		}

		return $result;
	}

	/**
	 * Страницы классов других игр: рубрики их разделов с названием класса или специализации («Жрец», «Тьма»).
	 * Берётся самая общая рубрика: «Жрец» важнее вложенного «Жрец → Гайды».
	 *
	 * @return array<string, array<string, string>> Игра => слаг класса из словаря => адрес.
	 */
	private function game_classes(): array {
		$games  = null !== $this->games ? ( $this->games )() : null;
		$result = array();

		foreach ( null !== $games ? array_keys( $games->roots() ) : array() as $game ) {
			$ids = $games->section_terms( $game );

			if ( array() === $ids ) {
				continue;
			}

			$terms   = get_terms(
				array(
					'taxonomy'   => 'category',
					'include'    => $ids,
					'hide_empty' => false,
					'orderby'    => 'term_id',
				)
			);
			$lexicon = $games->lexicon( $game );
			$parents = $games->parents();
			$found   = array();

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$classes = $lexicon->classes( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
				$depth   = count( GameScope::ancestry( array( (int) $term->term_id ), $parents )[ (int) $term->term_id ] );
				$url     = get_term_link( $term );

				if ( 1 !== count( $classes ) || ! is_string( $url ) || ( isset( $found[ $classes[0] ] ) && $found[ $classes[0] ]['depth'] <= $depth ) ) {
					continue;
				}

				$found[ $classes[0] ] = array(
					'depth' => $depth,
					'url'   => $url,
				);
			}

			$result[ $game ] = array_map( static fn( array $item ): string => $item['url'], $found );
		}

		return $result;
	}

	/**
	 * Страницы архетипов.
	 *
	 * @return list<array{name: string, url: string}>
	 */
	private function archetypes(): array {
		if ( ! taxonomy_exists( Module::ARCHETYPE ) ) {
			return array();
		}

		$terms  = get_terms(
			array(
				'taxonomy'   => Module::ARCHETYPE,
				'hide_empty' => true,
				'number'     => 3000,
			)
		);
		$result = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$url = get_term_link( $term );

			if ( is_string( $url ) && '' !== trim( $term->name ) ) {
				$result[] = array(
					'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'url'  => $url,
				);
			}
		}

		return $result;
	}
}
