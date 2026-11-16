<?php
/**
 * Основная рубрика записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Meta;

/**
 * Когда у записи несколько рубрик, редактор выбирает в панели основную: она идёт в хлебные крошки,
 * в переменную %category% и в раздел статьи (article:section, articleSection).
 */
final class PrimaryCategory {

	/**
	 * Выбранная рубрика, если запись всё ещё в ней состоит; иначе 0 — выбор не действует.
	 *
	 * @param list<int> $term_ids Рубрики записи.
	 * @param int       $primary  Рубрика, выбранная в панели.
	 */
	public static function choose( array $term_ids, int $primary ): int {
		return $primary > 0 && in_array( $primary, array_map( 'intval', $term_ids ), true ) ? $primary : 0;
	}

	/**
	 * Рубрики записи: основная — первой.
	 *
	 * @param list<object> $terms   Рубрики записи: объекты со свойством term_id.
	 * @param int          $primary Рубрика, выбранная в панели.
	 * @return list<object>
	 */
	public static function first( array $terms, int $primary ): array {
		$terms = array_values( $terms );

		usort(
			$terms,
			static fn( object $a, object $b ): int => (int) ( (int) $b->term_id === $primary ) <=> (int) ( (int) $a->term_id === $primary )
		);

		return $terms;
	}

	/**
	 * Рубрики записи из WordPress: основная — первой. Порядок остальных не меняется.
	 *
	 * @param int $post_id ID записи.
	 * @return list<\WP_Term>
	 */
	public static function terms( int $post_id ): array {
		// У типов записей без рубрик их и не ищем: иначе WordPress пошёл бы за ними в базу.
		$terms = is_object_in_taxonomy( (string) get_post_type( $post_id ), 'category' ) ? get_the_terms( $post_id, 'category' ) : false;

		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}

		return self::first( $terms, (int) get_post_meta( $post_id, MetaKeys::PRIMARY_CATEGORY, true ) );
	}

	/**
	 * ID основной рубрики записи; 0, если она не выбрана или запись в ней больше не состоит.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function id( int $post_id ): int {
		return self::choose(
			array_map( static fn( \WP_Term $term ): int => (int) $term->term_id, self::terms( $post_id ) ),
			(int) get_post_meta( $post_id, MetaKeys::PRIMARY_CATEGORY, true )
		);
	}
}
