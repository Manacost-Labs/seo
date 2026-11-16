<?php
/**
 * Архивы терминов в карте сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;

/**
 * Термины, у которых есть записи. Закрытые от индексации и «тонкие» архивы в карту не попадают.
 * Дата изменения архива — дата последней изменённой опубликованной записи термина и его дочерних терминов.
 */
final class TaxonomyProvider {

	public const PAGE_SIZE = 1000;

	/**
	 * ID терминов с записями по таксономиям, по возрастанию.
	 *
	 * @var array<string, list<int>>
	 */
	private array $ids = array();

	/**
	 * Время последней записи терминов по таксономиям: ID термина => время MySQL по Гринвичу.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $modified = array();

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер: решает, индексируется ли архив.
	 * @param Settings     $settings Настройки.
	 * @param \wpdb|null   $db       База WordPress; без неё берётся общая.
	 */
	public function __construct( private MetaResolver $resolver, private Settings $settings, private ?\wpdb $db = null ) {
	}

	/**
	 * Таксономии, которые входят в карту.
	 *
	 * @return list<string>
	 */
	public function types(): array {
		$types = array();

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$config = $this->settings->taxonomy( $taxonomy );

			if ( is_taxonomy_viewable( $taxonomy ) && ! empty( $config['sitemap'] ) && empty( $config['noindex'] ) ) {
				$types[] = (string) $taxonomy;
			}
		}

		return $types;
	}

	/**
	 * Страницы карты для таксономии: номер и время последнего изменения архивов страницы.
	 * Страница может оказаться пустой, если все её термины закрыты: это проверяет has_entries().
	 *
	 * @param string $taxonomy Таксономия.
	 * @return list<array{page: int, lastmod: string}>
	 */
	public function pages( string $taxonomy ): array {
		$pages    = array();
		$modified = $this->modified( $taxonomy );

		foreach ( array_chunk( $this->ids( $taxonomy ), self::PAGE_SIZE ) as $index => $chunk ) {
			$pages[] = array(
				'page'    => $index + 1,
				'lastmod' => Lastmod::w3c( Lastmod::latest( array_intersect_key( $modified, array_flip( $chunk ) ) ) ),
			);
		}

		return $pages;
	}

	/**
	 * Архивы терминов страницы карты; null — такой страницы нет.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param int    $page     Номер страницы, начиная с 1.
	 * @return list<array{loc: string, lastmod: string}>|null
	 */
	public function entries( string $taxonomy, int $page ): ?array {
		return $this->collect( $taxonomy, $page, 0 );
	}

	/**
	 * Есть ли на странице карты хотя бы один архив. Проверка останавливается на первом открытом термине.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param int    $page     Номер страницы, начиная с 1.
	 */
	public function has_entries( string $taxonomy, int $page ): bool {
		return array() !== (array) $this->collect( $taxonomy, $page, 1 );
	}

	/**
	 * Открытые для индексации архивы страницы карты.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param int    $page     Номер страницы, начиная с 1.
	 * @param int    $limit    Сколько архивов достаточно найти; 0 — все.
	 * @return list<array{loc: string, lastmod: string}>|null
	 */
	private function collect( string $taxonomy, int $page, int $limit ): ?array {
		if ( ! in_array( $taxonomy, $this->types(), true ) ) {
			return null;
		}

		$ids   = $this->ids( $taxonomy );
		$range = Chunker::range( Chunker::boundaries( $ids, self::PAGE_SIZE ), $page );

		if ( null === $range ) {
			return null;
		}

		$entries  = array();
		$modified = $this->modified( $taxonomy );

		foreach ( $ids as $id ) {
			if ( $id <= $range['after'] || $id > $range['upto'] ) {
				continue;
			}

			$url = get_term_link( $id, $taxonomy );

			if ( is_string( $url ) && EntryPolicy::allows( $url, $this->resolver->indexing( new Context( Context::TERM, $id, $taxonomy ) ), false ) ) {
				$entries[] = array(
					'loc'     => $url,
					'lastmod' => Lastmod::w3c( $modified[ $id ] ?? '' ),
				);

				if ( $limit > 0 && count( $entries ) >= $limit ) {
					break;
				}
			}
		}

		return $entries;
	}

	/**
	 * ID терминов таксономии, у которых есть записи.
	 *
	 * @param string $taxonomy Таксономия.
	 * @return list<int>
	 */
	private function ids( string $taxonomy ): array {
		if ( ! isset( $this->ids[ $taxonomy ] ) ) {
			$ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'fields'     => 'ids',
					'orderby'    => 'term_id',
					'order'      => 'ASC',
				)
			);

			$this->ids[ $taxonomy ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
			sort( $this->ids[ $taxonomy ] );
		}

		return $this->ids[ $taxonomy ];
	}

	/**
	 * Время последней изменённой опубликованной записи каждого термина таксономии.
	 * Один групповой запрос на всю таксономию вместо запроса на каждый термин; результат попадает
	 * в готовый XML файла и хранится вместе с ним в кэше карты.
	 *
	 * @param string $taxonomy Таксономия.
	 * @return array<int, string> ID термина => время MySQL по Гринвичу.
	 */
	private function modified( string $taxonomy ): array {
		if ( isset( $this->modified[ $taxonomy ] ) ) {
			return $this->modified[ $taxonomy ];
		}

		$db = $this->db ?? $GLOBALS['wpdb'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- один групповой запрос на файл карты; готовый XML кэшируется целиком.
		$rows = $db->get_results(
			$db->prepare(
				"SELECT tt.term_id, tt.parent, MAX( p.post_modified_gmt ) AS modified
				FROM {$db->term_taxonomy} tt
				LEFT JOIN {$db->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				LEFT JOIN {$db->posts} p ON p.ID = tr.object_id AND p.post_status = 'publish'
				WHERE tt.taxonomy = %s
				GROUP BY tt.term_id, tt.parent",
				$taxonomy
			),
			ARRAY_A
		);

		$own     = array();
		$parents = array();

		foreach ( (array) $rows as $row ) {
			$own[ (int) $row['term_id'] ]     = (string) ( $row['modified'] ?? '' );
			$parents[ (int) $row['term_id'] ] = (int) $row['parent'];
		}

		$this->modified[ $taxonomy ] = Lastmod::with_descendants( $own, $parents );

		return $this->modified[ $taxonomy ];
	}
}
