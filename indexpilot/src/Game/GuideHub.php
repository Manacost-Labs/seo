<?php
/**
 * Страницы-ответы классов другой игры: блок из гайдов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Settings\Settings;

/**
 * В World of Warcraft колод нет: над списком записей рубрики класса или специализации («Жрец», «Тьма»)
 * выводится фраза-ответ «Свежий гайд для жреца на … — …» и список свежих гайдов раздела.
 * Данные считаются один раз и хранятся в поле рубрики (как у Hub), очищаются при сохранении записи раздела
 * и раз в сутки собираются заново.
 */
final class GuideHub {

	/**
	 * Сколько живут сохранённые данные, секунд.
	 */
	private const LIFETIME = 86400;

	/**
	 * Сколько последних записей раздела просматривается и сколько гайдов выводится.
	 */
	private const SCAN   = 40;
	private const GUIDES = 5;

	/**
	 * Конструктор.
	 *
	 * @param SiteGames $games    Игры сайта.
	 * @param Settings  $settings Настройки.
	 */
	public function __construct( private SiteGames $games, private Settings $settings ) {
	}

	/**
	 * Подключает вывод над списком и очистку сохранённых данных.
	 */
	public function register(): void {
		add_filter( 'indexpilot_archive_top', array( $this, 'archive_top' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 30, 2 );
	}

	/**
	 * Класс или специализация, о которых рубрика; пусто — рубрика не страница класса другой игры.
	 *
	 * @param \WP_Term $term Рубрика.
	 */
	public function class_of( \WP_Term $term ): string {
		if ( 'category' !== $term->taxonomy ) {
			return '';
		}

		$game = $this->games->term_game( (int) $term->term_id );

		if ( SiteGames::DEFAULT_GAME === $game ) {
			return '';
		}

		$classes = $this->games->lexicon( $game )->classes( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );

		return 1 === count( $classes ) ? $classes[0] : '';
	}

	/**
	 * Гайды рубрики: сохранённые или собранные заново.
	 *
	 * @param \WP_Term $term Рубрика.
	 * @return array{built: int, date: string, decks: list<mixed>, guides: list<array{title: string, url: string}>}
	 */
	public function data( \WP_Term $term ): array {
		$saved = get_term_meta( $term->term_id, Hub::META, true );

		if ( is_array( $saved ) && isset( $saved['guides'], $saved['date'] ) && (int) ( $saved['built'] ?? 0 ) > time() - self::LIFETIME ) {
			return $saved;
		}

		$posts  = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'has_password'   => false,
				'cat'            => (int) $term->term_id,
				'posts_per_page' => self::SCAN,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$guides = array();

		foreach ( $posts as $post ) {
			if ( WowMaterialType::GUIDE !== PostMaterial::detect( $post ) ) {
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

		$data = array(
			'built'  => time(),
			'date'   => current_time( 'Y-m-d' ),
			'decks'  => array(),
			'guides' => $guides,
		);

		update_term_meta( $term->term_id, Hub::META, $data );

		return $data;
	}

	/**
	 * HTML блока; пустая строка, если гайдов нет.
	 *
	 * @param \WP_Term $term Рубрика класса.
	 */
	public function html( \WP_Term $term ): string {
		$slug = $this->class_of( $term );
		$data = '' !== $slug ? $this->data( $term ) : array( 'guides' => array() );

		if ( array() === $data['guides'] ) {
			return '';
		}

		$subject = GameScope::subject( $slug, $this->games->lexicon( $this->games->term_game( (int) $term->term_id ) ) );

		if ( '' === $subject ) {
			/* translators: %s: название рубрики. */
			$subject = sprintf( __( 'раздела «%s»', 'indexpilot' ), html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
		}

		$answer = HubSummary::guide_answer(
			$data['guides'],
			$subject,
			mysql2date( (string) get_option( 'date_format' ), (string) $data['date'] ),
			/* translators: 1: класс в родительном падеже, 2: дата, 3: название гайда. */
			__( 'Свежий гайд для %1$s на %2$s — %3$s.', 'indexpilot' )
		);
		$items = '';

		foreach ( $data['guides'] as $guide ) {
			$items .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( $guide['url'] ), esc_html( $guide['title'] ) );
		}

		return '<section class="indexpilot-hub" style="margin:0 0 1.5em"><p class="indexpilot-hub-answer" style="margin:0 0 .6em"><strong>' . esc_html( $answer ) . '</strong></p>'
			. '<p style="margin:.6em 0 .3em"><strong>' . esc_html__( 'Гайды', 'indexpilot' ) . '</strong></p><ul class="indexpilot-hub-guides">' . $items . '</ul></section>';
	}

	/**
	 * Добавляет блок к тексту над списком записей на первой странице рубрики класса.
	 *
	 * @param string   $text Текст над списком.
	 * @param \WP_Term $term Открытый термин.
	 */
	public function archive_top( $text, $term ): string {
		$text = (string) $text;

		if ( ! $term instanceof \WP_Term || ! $this->settings->general( 'hub_auto' ) || is_paged() || str_contains( $text, 'indexpilot-hub' ) || '' === $this->class_of( $term ) ) {
			return $text;
		}

		foreach ( array( MetaKeys::INTRO, MetaKeys::OUTRO ) as $key ) {
			if ( str_contains( (string) get_term_meta( $term->term_id, $key, true ), '[' . Hub::SHORTCODE ) ) {
				return $text;
			}
		}

		return $text . $this->html( $term );
	}

	/**
	 * Запись раздела другой игры сохранена: данные её рубрик и их предков очищаются.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function on_save( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || SiteGames::DEFAULT_GAME === $this->games->post_game( $post ) ) {
			return;
		}

		$ids = array_map( 'intval', wp_get_post_categories( (int) $post->ID ) );

		foreach ( GameScope::ancestry( $ids, $this->games->parents() ) as $term_id => $ancestors ) {
			foreach ( array_merge( array( $term_id ), $ancestors ) as $id ) {
				delete_term_meta( (int) $id, Hub::META );
			}
		}
	}
}
