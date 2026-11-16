<?php
/**
 * Блок страницы-ответа на странице класса и архетипа.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Settings\Settings;

/**
 * На странице класса («Колоды жреца») и архетипа выводит лучшие актуальные колоды с процентом побед, числом игр
 * и датой, фразу-ответ и ссылки на гайды.
 *
 * Данные считаются один раз и хранятся в поле термина: страница архива их только читает. Поле очищается, когда
 * меняется колода этого класса или архетипа (её данные, рубрики или статус), а раз в сутки собирается заново,
 * чтобы подтянуть новые гайды.
 */
final class Hub {

	public const SHORTCODE      = 'indexpilot_hub';
	public const META           = '_indexpilot_hub';
	public const CLASS_TAXONOMY = 'deck_class';

	/**
	 * Сколько живут сохранённые данные, секунд.
	 */
	private const LIFETIME = 86400;

	/**
	 * Сколько последних колод раздела просматривается, сколько показывается и сколько гайдов выводится.
	 */
	private const SCAN   = 100;
	private const SHOW   = 5;
	private const GUIDES = 5;

	/**
	 * Поля колоды, от которых зависит блок.
	 */
	private const FIELDS = array( '_deck_wins', '_deck_losses', '_deck_peak', '_hs_deck_archived' );

	/**
	 * Конструктор.
	 *
	 * @param Module              $module   Игровой модуль: колоды и их актуальные версии.
	 * @param Settings            $settings Настройки.
	 * @param \Closure(): Lexicon $lexicon  Игровой словарь: класс по названию термина.
	 */
	public function __construct( private Module $module, private Settings $settings, private \Closure $lexicon ) {
	}

	/**
	 * Подключает шорткод, вывод над списком и очистку сохранённых данных.
	 */
	public function register(): void {
		add_action(
			'init',
			function (): void {
				add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
			},
			100
		);

		add_filter( 'indexpilot_archive_top', array( $this, 'archive_top' ), 10, 2 );
		add_action( 'set_object_terms', array( $this, 'on_terms_set' ), 10, 6 );
		add_action( 'wp_after_insert_post', array( $this, 'on_deck_change' ), 30 );
		add_action( 'before_delete_post', array( $this, 'on_deck_change' ) );
		add_action( 'edited_term', array( $this, 'on_term_edit' ), 10, 3 );

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Таксономии страниц-ответов, которые есть на сайте.
	 *
	 * @return list<string>
	 */
	public static function taxonomies(): array {
		return array_values( array_filter( array( self::CLASS_TAXONOMY, Module::ARCHETYPE ), 'taxonomy_exists' ) );
	}

	/**
	 * Является ли термин страницей класса или архетипа.
	 *
	 * @param \WP_Term $term Термин.
	 */
	public static function is_hub( \WP_Term $term ): bool {
		return in_array( $term->taxonomy, self::taxonomies(), true );
	}

	/**
	 * Данные блока: сохранённые или посчитанные заново.
	 *
	 * @param \WP_Term $term Термин класса или архетипа.
	 * @return array{built: int, date: string, decks: list<array<string, mixed>>, guides: list<array{title: string, url: string}>}
	 */
	public function data( \WP_Term $term ): array {
		$saved = get_term_meta( $term->term_id, self::META, true );

		if ( is_array( $saved ) && isset( $saved['decks'], $saved['guides'], $saved['date'] ) && (int) ( $saved['built'] ?? 0 ) > time() - self::LIFETIME ) {
			return $saved;
		}

		$data = $this->build( $term );
		update_term_meta( $term->term_id, self::META, $data );

		return $data;
	}

	/**
	 * HTML блока; пустая строка, если показывать нечего.
	 *
	 * @param \WP_Term $term Термин класса или архетипа.
	 */
	public function html( \WP_Term $term ): string {
		$data = $this->data( $term );

		if ( array() === $data['decks'] && array() === $data['guides'] ) {
			return '';
		}

		$format = (string) get_option( 'date_format' );
		$answer = HubSummary::answer(
			$data['decks'],
			$this->subject( $term ),
			mysql2date( $format, $data['date'] ),
			array(
				/* translators: 1: класс или архетип в родительном падеже, 2: дата, 3: название колоды, 4: процент побед, 5: число игр с предлогом-падежом («312 играм»). */
				'best'      => __( 'Лучшая колода %1$s на %2$s — %3$s, %4$d %% побед по %5$s.', 'indexpilot' ),
				/* translators: 1: класс или архетип в родительном падеже, 2: дата, 3: название колоды. */
				'fresh'     => __( 'Свежая колода %1$s на %2$s — %3$s.', 'indexpilot' ),
				/* translators: %d: число игр, оканчивается на 1 (кроме 11): «по 21 игре». */
				'game_one'  => __( '%d игре', 'indexpilot' ),
				/* translators: %d: число игр: «по 312 играм». */
				'game_many' => __( '%d играм', 'indexpilot' ),
			)
		);

		$html = '' !== $answer ? '<p class="indexpilot-hub-answer" style="margin:0 0 .6em"><strong>' . esc_html( $answer ) . '</strong></p>' : '';

		if ( array() !== $data['decks'] ) {
			$items = '';

			foreach ( $data['decks'] as $deck ) {
				$facts = array();

				if ( (int) $deck['games'] > 0 ) {
					/* translators: %d: процент побед. */
					$facts[] = sprintf( __( '%d %% побед', 'indexpilot' ), (int) $deck['winrate'] );
					/* translators: %d: число сыгранных игр. */
					$facts[] = sprintf( __( 'игр: %d', 'indexpilot' ), (int) $deck['games'] );
				}

				if ( '' !== (string) ( $deck['peak'] ?? '' ) ) {
					/* translators: %s: лучший ранг колоды. */
					$facts[] = sprintf( __( 'пик: %s', 'indexpilot' ), (string) $deck['peak'] );
				}

				if ( '' !== (string) $deck['date'] ) {
					/* translators: %s: дата обновления колоды. */
					$facts[] = sprintf( __( 'обновлена %s', 'indexpilot' ), mysql2date( $format, (string) $deck['date'] ) );
				}

				$items .= sprintf(
					'<li><a href="%s">%s</a>%s</li>',
					esc_url( (string) $deck['url'] ),
					esc_html( (string) $deck['title'] ),
					array() !== $facts ? ' — ' . esc_html( implode( ', ', $facts ) ) : ''
				);
			}

			$html .= '<ol class="indexpilot-hub-decks">' . $items . '</ol>';
		}

		if ( array() !== $data['guides'] ) {
			$items = '';

			foreach ( $data['guides'] as $guide ) {
				$items .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( $guide['url'] ), esc_html( $guide['title'] ) );
			}

			$html .= '<p style="margin:.6em 0 .3em"><strong>' . esc_html__( 'Гайды', 'indexpilot' ) . '</strong></p><ul class="indexpilot-hub-guides">' . $items . '</ul>';
		}

		return '<section class="indexpilot-hub" style="margin:0 0 1.5em">' . $html . '</section>';
	}

	/**
	 * Добавляет блок к тексту над списком записей на первой странице класса или архетипа.
	 *
	 * @param string   $text Текст над списком.
	 * @param \WP_Term $term Открытый термин.
	 */
	public function archive_top( $text, $term ): string {
		$text = (string) $text;

		if ( ! $term instanceof \WP_Term || ! self::is_hub( $term ) || ! $this->settings->general( 'hub_auto' ) || is_paged() || str_contains( $text, 'indexpilot-hub' ) ) {
			return $text;
		}

		// Редактор поставил блок шорткодом во вступление или нижний текст: второй раз он не нужен.
		foreach ( array( MetaKeys::INTRO, MetaKeys::OUTRO ) as $key ) {
			if ( str_contains( (string) get_term_meta( $term->term_id, $key, true ), '[' . self::SHORTCODE ) ) {
				return $text;
			}
		}

		return $text . $this->html( $term );
	}

	/**
	 * Шорткод [indexpilot_hub]: блок открытой страницы класса или архетипа, либо термина из атрибута term (ID).
	 *
	 * @param array<string, string>|string $atts Атрибуты.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'term' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$term = '' !== $atts['term'] ? get_term( (int) $atts['term'] ) : get_queried_object();

		return $term instanceof \WP_Term && self::is_hub( $term ) ? $this->html( $term ) : '';
	}

	/**
	 * Раздел в родительном падеже: «жреца», «архетипа «Blood DK»».
	 *
	 * @param \WP_Term $term Термин.
	 */
	public function subject( \WP_Term $term ): string {
		$name = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );

		if ( self::CLASS_TAXONOMY === $term->taxonomy ) {
			$classes  = ( $this->lexicon )()->classes( $name );
			$genitive = 1 === count( $classes ) ? HubSummary::genitive( $classes[0] ) : '';

			if ( '' !== $genitive ) {
				return $genitive;
			}

			/* translators: %s: название класса. */
			return sprintf( __( 'класса «%s»', 'indexpilot' ), $name );
		}

		/* translators: %s: название архетипа. */
		return sprintf( __( 'архетипа «%s»', 'indexpilot' ), $name );
	}

	/**
	 * Рубрики колоды сменились: очищаются данные и старых, и новых разделов.
	 *
	 * @param int    $object_id  ID записи.
	 * @param array  $terms      Термины.
	 * @param array  $tt_ids     Новые ID терминов в таксономии.
	 * @param string $taxonomy   Таксономия.
	 * @param bool   $append     Добавлены ли термины к прежним.
	 * @param array  $old_tt_ids Прежние ID терминов в таксономии.
	 */
	public function on_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( ! in_array( (string) $taxonomy, self::taxonomies(), true ) || DeckVariables::POST_TYPE !== get_post_type( (int) $object_id ) ) {
			return;
		}

		foreach ( array_unique( array_merge( (array) $tt_ids, (array) $old_tt_ids ) ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, (string) $taxonomy );

			if ( $term instanceof \WP_Term ) {
				delete_term_meta( $term->term_id, self::META );
			}
		}
	}

	/**
	 * Колода сохранена, сменила статус или удаляется: данные её разделов очищаются.
	 *
	 * @param int $post_id ID записи.
	 */
	public function on_deck_change( $post_id ): void {
		if ( DeckVariables::POST_TYPE !== get_post_type( (int) $post_id ) || wp_is_post_revision( (int) $post_id ) ) {
			return;
		}

		$this->clear( (int) $post_id );
	}

	/**
	 * Изменились победы, поражения, пик или отметка архива колоды.
	 *
	 * @param int|array $meta_id   ID поля.
	 * @param int       $object_id ID записи.
	 * @param string    $meta_key  Имя поля.
	 */
	public function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( (string) $meta_key, self::FIELDS, true ) && DeckVariables::POST_TYPE === get_post_type( (int) $object_id ) ) {
			$this->clear( (int) $object_id );
		}
	}

	/**
	 * Термин переименован: фраза-ответ называет его по-новому.
	 *
	 * @param int    $term_id  ID термина.
	 * @param int    $tt_id    ID термина в таксономии.
	 * @param string $taxonomy Таксономия.
	 */
	public function on_term_edit( $term_id, $tt_id, $taxonomy ): void {
		if ( in_array( (string) $taxonomy, self::taxonomies(), true ) ) {
			delete_term_meta( (int) $term_id, self::META );
		}
	}

	/**
	 * Очищает данные разделов колоды.
	 *
	 * @param int $post_id ID колоды.
	 */
	private function clear( int $post_id ): void {
		foreach ( self::taxonomies() as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				delete_term_meta( $term->term_id, self::META );
			}
		}
	}

	/**
	 * Считает данные блока.
	 *
	 * @param \WP_Term $term Термин.
	 * @return array{built: int, date: string, decks: list<array<string, mixed>>, guides: list<array{title: string, url: string}>}
	 */
	private function build( \WP_Term $term ): array {
		$ids = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => DeckVariables::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => self::SCAN,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- выборка колод одного раздела; результат хранится в поле термина.
					'tax_query'      => array(
						array(
							'taxonomy' => $term->taxonomy,
							'terms'    => (int) $term->term_id,
						),
					),
				)
			)
		);

		// Поля и рубрики колод читаются одной пачкой, а не по запросу на колоду.
		_prime_post_caches( $ids, true, true );

		$decks = array();

		foreach ( $ids as $id ) {
			// Старая версия колоды не показывается: её каноникал ведёт на актуальную.
			if ( $this->module->current_version( $id ) !== $id ) {
				continue;
			}

			$deck    = $this->module->decks()->deck( $id );
			$decks[] = array(
				'id'       => $id,
				'title'    => wp_strip_all_tags( get_the_title( $id ) ),
				'url'      => (string) get_permalink( $id ),
				'wins'     => (int) get_post_meta( $id, '_deck_wins', true ),
				'losses'   => (int) get_post_meta( $id, '_deck_losses', true ),
				'peak'     => sanitize_text_field( (string) get_post_meta( $id, '_deck_peak', true ) ),
				'date'     => PostDates::modified( 'Y-m-d', false, $id ),
				'archived' => $deck->archived,
			);
		}

		$ranked = array();
		foreach ( HubSummary::rank( $decks, self::SHOW ) as $deck ) {
			unset( $deck['archived'], $deck['wins'], $deck['losses'] );
			$ranked[] = $deck;
		}

		return array(
			'built'  => time(),
			'date'   => current_time( 'Y-m-d' ),
			'decks'  => $ranked,
			'guides' => $this->guides( $term ),
		);
	}

	/**
	 * Гайды по разделу: свежие записи, где упоминается его название, с типом материала «гайд», «муллиган» или «матчапы».
	 *
	 * @param \WP_Term $term Термин.
	 * @return list<array{title: string, url: string}>
	 */
	private function guides( \WP_Term $term ): array {
		$posts  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				's'              => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'has_password'   => false,
			)
		);
		$guides = array();

		foreach ( $posts as $post ) {
			if ( ! in_array( PostMaterial::detect( $post ), array( MaterialType::GUIDE, MaterialType::MULLIGAN, MaterialType::MATCHUPS ), true ) ) {
				continue;
			}

			$guides[] = array(
				'title' => wp_strip_all_tags( get_the_title( $post ) ),
				'url'   => (string) get_permalink( $post ),
			);

			if ( count( $guides ) >= self::GUIDES ) {
				break;
			}
		}

		return $guides;
	}
}
