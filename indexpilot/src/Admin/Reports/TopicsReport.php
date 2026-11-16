<?php
/**
 * Отчёт «Карта тем».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\HelpTip;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Game\Hub;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\Module as GameModule;
use IndexPilot\Game\TopicMap;
use IndexPilot\SearchData\Module;

/**
 * Для каждого класса и архетипа — на какие вопросы читателя есть материал, а на какие нет
 * («нет материала о муллигане»). С данными поиска пробелы с большим спросом стоят первыми.
 */
final class TopicsReport {

	public const SLUG = 'indexpilot-topics';

	private const CAP        = 'manage_options';
	private const ARCHETYPES = 60;

	public function __construct( private Module $module ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 12 );
	}

	/**
	 * Добавляет страницу.
	 */
	public function add(): void {
		add_submenu_page( SettingsPage::SLUG, __( 'IndexPilot — карта тем', 'indexpilot' ), __( 'Карта тем', 'indexpilot' ), self::CAP, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Темы: классы игры и самые крупные архетипы.
	 *
	 * @return list<array{key: string, name: string, kind: string, slug: string}>
	 */
	private function topics(): array {
		$lexicon = $this->module->plugin()->lexicon();
		$topics  = array();

		foreach ( $lexicon->class_slugs() as $slug ) {
			$topics[] = array(
				'key'  => 'class:' . $slug,
				'name' => $lexicon->class_name( $slug ),
				'kind' => 'class',
				'slug' => $slug,
			);
		}

		if ( taxonomy_exists( GameModule::ARCHETYPE ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => GameModule::ARCHETYPE,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => self::ARCHETYPES,
				)
			);

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$topics[] = array(
					'key'  => 'archetype:' . $term->term_id,
					'name' => $term->name,
					'kind' => 'archetype',
					'slug' => '',
				);
			}
		}

		return $topics;
	}

	/**
	 * Материалы сайта с типом, классами и архетипами из рубрик.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function materials(): array {
		$site      = $this->module->site();
		$lexicon   = $this->module->plugin()->lexicon();
		$terms     = $site->terms( array( 'category', Hub::CLASS_TAXONOMY, GameModule::ARCHETYPE ) );
		$materials = array();

		foreach ( $site->posts( $this->module->plugin()->audit()->types() ) as $post ) {
			$own     = $terms[ $post['id'] ] ?? array();
			$classes = array();

			foreach ( array_merge( $own['category'] ?? array(), $own[ Hub::CLASS_TAXONOMY ] ?? array() ) as $name ) {
				$classes = array_merge( $classes, $lexicon->classes( $name ) );
			}

			$materials[] = array(
				'id'         => $post['id'],
				'title'      => $post['title'],
				'type'       => MaterialType::detect( $post['title'], $own['category'] ?? array(), '', $post['type'] ),
				'classes'    => array_values( array_unique( $classes ) ),
				'archetypes' => $own[ GameModule::ARCHETYPE ] ?? array(),
			);
		}

		return $materials;
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$queries = $this->module->has_data() ? $this->module->store()->top_queries() : array();
		$map     = ( new TopicMap( $this->module->plugin()->lexicon() ) )->build( $this->topics(), $this->materials(), $queries );

		echo '<div class="wrap" data-indexpilot-topics>';
		echo '<h1>' . esc_html__( 'IndexPilot — карта тем', 'indexpilot' ) . ' ';
		HelpTip::render(
			__( 'выберите пробел и напишите материал: «муллиган жреца», «бюджетная колода мага». После публикации пробел исчезнет сам.', 'indexpilot' ),
			__( 'ассистент и поисковик, отвечая на один вопрос о классе или архетипе, ищут ответы и на соседние: выигрывает сайт, закрывший тему целиком.', 'indexpilot' )
		);
		echo '</h1>';
		echo '<p>' . esc_html(
			array() === $queries
				? __( 'Данных поиска нет: карта построена по типам и названиям материалов.', 'indexpilot' )
				: __( 'Карта построена по типам и названиям материалов; спрос — показы запросов о теме с этим вопросом за 28 дней. Пробелы с большим спросом стоят первыми.', 'indexpilot' )
		) . '</p>';

		$lines = array();

		foreach ( $map as $topic ) {
			if ( array() === $topic['materials'] && 0 === $topic['impressions'] ) {
				continue;
			}

			$gaps = array();

			foreach ( $topic['gaps'] as $question ) {
				$demand = (int) ( $topic['demand'][ $question ] ?? 0 );
				/* translators: %s: вопрос, например «Муллиган». */
				$text = sprintf( __( 'нет материала: %s', 'indexpilot' ), mb_strtolower( TopicMap::question( $question ), 'UTF-8' ) );
				/* translators: %s: число показов. */
				$gaps[] = '<span data-indexpilot-gap="' . esc_attr( $question ) . '">' . esc_html( $text . ( $demand > 0 ? ' — ' . sprintf( __( 'спрос %s показов', 'indexpilot' ), number_format_i18n( $demand ) ) : '' ) ) . '</span>';
			}

			$covered = array_map( static fn( string $question ): string => esc_html( TopicMap::question( $question ) ), array_keys( $topic['covered'] ) );

			$lines[] = array(
				'<strong>' . esc_html( $topic['name'] ) . '</strong><br><span class="description">' . esc_html( 'class' === $topic['kind'] ? __( 'класс', 'indexpilot' ) : __( 'архетип', 'indexpilot' ) ) . '</span>',
				esc_html( number_format_i18n( count( $topic['materials'] ) ) ),
				implode( ', ', $covered ),
				implode( '<br>', $gaps ),
			);
		}

		QueriesReport::table( 'topic-map', array( __( 'Тема', 'indexpilot' ), __( 'Материалов', 'indexpilot' ), __( 'Есть', 'indexpilot' ), __( 'Пробелы', 'indexpilot' ) ), $lines );
		echo '</div>';
	}
}
