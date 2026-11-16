<?php
/**
 * Глубина записей от главной страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

use IndexPilot\Meta\MetaKeys;

/**
 * Считает, за сколько переходов по обычным ссылкам читатель и робот доходят от главной до каждой записи
 * (книга, с. 236–244, 523). Начальные точки — записи из меню и с главной страницы: ссылки статической главной
 * из графа или последние записи ленты. Расчёт идёт по кнопке и после полного обхода ссылок, результат хранится
 * в настройке без автозагрузки — экран отчёта его только читает.
 */
final class DepthMap {

	public const OPTION = 'indexpilot_link_depth';

	/**
	 * Глубже этого записи считаются недостижимыми.
	 */
	private const MAX_DEPTH = 20;

	/**
	 * Опорный материал глубже этого — проблема.
	 */
	public const DEEP = 3;

	/**
	 * Конструктор.
	 *
	 * @param \wpdb   $db      База WordPress.
	 * @param Scanner $scanner Обход записей: типы записей графа.
	 */
	public function __construct( private \wpdb $db, private Scanner $scanner ) {
	}

	/**
	 * Пересчитывает глубину и сохраняет результат.
	 *
	 * @return array{processed: int, total: int, done: bool}
	 */
	public function compute(): array {
		$posts  = $this->published();
		$depths = Depth::compute( $this->edges(), $this->starts(), self::MAX_DEPTH );
		$known  = array_flip( $posts );

		// Только опубликованные записи графа: главная-лента и удалённые записи в отчёт не попадают.
		$depths = array_intersect_key( $depths, $known );

		update_option(
			self::OPTION,
			array(
				'updated'     => time(),
				'depths'      => $depths,
				'unreachable' => Depth::unreachable( $depths, $posts ),
				'cornerstone' => $this->cornerstone(),
			),
			false
		);

		return array(
			'processed' => count( $posts ),
			'total'     => count( $posts ),
			'done'      => true,
		);
	}

	/**
	 * Сохранённый результат.
	 *
	 * @return array{updated: int, depths: array<int, int>, unreachable: list<int>, cornerstone: list<int>}
	 */
	public function stored(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$depths = array();
		foreach ( (array) ( $saved['depths'] ?? array() ) as $id => $depth ) {
			$depths[ (int) $id ] = (int) $depth;
		}

		return array(
			'updated'     => (int) ( $saved['updated'] ?? 0 ),
			'depths'      => $depths,
			'unreachable' => array_map( 'intval', (array) ( $saved['unreachable'] ?? array() ) ),
			'cornerstone' => array_map( 'intval', (array) ( $saved['cornerstone'] ?? array() ) ),
		);
	}

	/**
	 * Записи глубже трёх переходов: сначала опорные материалы, затем остальные; внутри — от самых глубоких.
	 *
	 * @param array<int, int> $depths      Глубина записей.
	 * @param list<int>       $cornerstone Опорные материалы.
	 * @return list<array{post_id: int, depth: int, cornerstone: bool}>
	 */
	public static function deep( array $depths, array $cornerstone ): array {
		$main = array_flip( $cornerstone );
		$rows = array();

		foreach ( $depths as $id => $depth ) {
			if ( $depth > self::DEEP ) {
				$rows[] = array(
					'post_id'     => (int) $id,
					'depth'       => (int) $depth,
					'cornerstone' => isset( $main[ $id ] ),
				);
			}
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => array( ! $a['cornerstone'], -$a['depth'], $a['post_id'] ) <=> array( ! $b['cornerstone'], -$b['depth'], $b['post_id'] )
		);

		return $rows;
	}

	/**
	 * Сколько записей на каждой глубине; сама главная (глубина 0) не считается.
	 *
	 * @param array<int, int> $depths Глубина записей.
	 * @return array<int, int>
	 */
	public static function distribution( array $depths ): array {
		$counts = array();

		foreach ( $depths as $depth ) {
			if ( $depth > 0 ) {
				$counts[ $depth ] = ( $counts[ $depth ] ?? 0 ) + 1;
			}
		}

		ksort( $counts );

		return $counts;
	}

	/**
	 * Добавляет записи первого уровня к начальным точкам; у записи остаётся наименьшая глубина.
	 *
	 * @param array<int, int> $starts Начальные точки.
	 * @param list<int>       $ids    Записи, на которые ссылаются главная или меню.
	 * @return array<int, int>
	 */
	public static function merge_starts( array $starts, array $ids ): array {
		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! isset( $starts[ $id ] ) ) {
				$starts[ $id ] = 1;
			}
		}

		ksort( $starts );

		return $starts;
	}

	/**
	 * Начальные точки: статическая главная (глубина 0) или записи ленты главной, а также записи из меню (глубина 1).
	 *
	 * @return array<int, int>
	 */
	private function starts(): array {
		$starts = array();
		$front  = (int) get_option( 'page_on_front' );

		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			// Ссылки самой главной берутся из графа: обход начнётся с неё.
			$starts[ $front ] = 0;
		} else {
			$starts = self::merge_starts( $starts, $this->latest_posts() );
		}

		return self::merge_starts( $starts, $this->menu_posts() );
	}

	/**
	 * Записи на главной-ленте: закреплённые и последние по числу записей на странице.
	 *
	 * @return list<int>
	 */
	private function latest_posts(): array {
		$ids = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, (int) get_option( 'posts_per_page' ) ),
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
				'no_found_rows'       => true,
			)
		);

		return array_merge( array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ), array_map( 'intval', $ids ) );
	}

	/**
	 * Записи из всех назначенных меню. Свои ссылки в меню переводятся в записи по адресу.
	 *
	 * @return list<int>
	 */
	private function menu_posts(): array {
		$ids  = array();
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( array_unique( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) ) as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );

			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( 'post_type' === $item->type ) {
					$ids[] = (int) $item->object_id;
				} elseif ( 'custom' === $item->type && (string) wp_parse_url( (string) $item->url, PHP_URL_HOST ) === $home ) {
					$ids[] = url_to_postid( (string) $item->url );
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Граф обычных внутренних ссылок: запись => записи, на которые она ссылается.
	 *
	 * @return array<int, list<int>>
	 */
	private function edges(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- расчёт по кнопке по собственной таблице.
		$rows = $this->db->get_results(
			$this->db->prepare( "SELECT DISTINCT post_id, target_post_id FROM %i WHERE type = 'internal' AND nofollow = 0 AND target_post_id > %d", Schema::table( $this->db ), 0 ),
			ARRAY_A
		);

		$edges = array();
		foreach ( (array) $rows as $row ) {
			$edges[ (int) $row['post_id'] ][] = (int) $row['target_post_id'];
		}

		return $edges;
	}

	/**
	 * Опубликованные записи типов графа без пароля.
	 *
	 * @return list<int>
	 */
	private function published(): array {
		$types = $this->scanner->types();

		if ( array() === $types ) {
			return array();
		}

		$sql = "SELECT ID FROM {$this->db->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ( " . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ' ) ORDER BY ID ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- в запрос подставляется только список заполнителей %s по числу типов записей.
		return array_map( 'intval', (array) $this->db->get_col( $this->db->prepare( $sql, $types ) ) );
	}

	/**
	 * Опорные материалы.
	 *
	 * @return list<int>
	 */
	private function cornerstone(): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- расчёт по кнопке.
		$ids = $this->db->get_col( $this->db->prepare( "SELECT post_id FROM {$this->db->postmeta} WHERE meta_key = %s AND meta_value = %s", MetaKeys::CORNERSTONE, '1' ) );

		return array_map( 'intval', (array) $ids );
	}
}
