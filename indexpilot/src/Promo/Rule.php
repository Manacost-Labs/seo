<?php
/**
 * Чистые правила промоблоков.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Promo;

/** Подбор и очистка правил без WordPress. */
final class Rule {
	public const OPTION = 'indexpilot_promo_rules';
	public const PLACES = array( 'after_first', 'middle', 'end' );
	/**
	 * Очищает правила.
	 * @param array $raw Входные правила.
	 * @param array $destination_codes Допустимые площадки.
	 * @return array
	 */
	public static function sanitize_all( array $raw, array $destination_codes ): array {
		$result = array();
		$used   = array();
		$max    = 0;
		foreach ( $raw as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && false !== filter_var( $row['id'], FILTER_VALIDATE_INT ) && 0 < (int) $row['id'] ) {
				$max = max( $max, (int) $row['id'] );}
		}
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || ! in_array( $row['destination'] ?? '', $destination_codes, true ) ) {
				continue;}
			$variants = array();
			foreach ( (array) ( $row['variants'] ?? array() ) as $v ) {
				if ( ! is_array( $v ) ) {
					continue;}
				$text = self::text( $v['text'] ?? '', 300 );
				if ( '' === $text ) {
					continue;}
				$variants[] = array(
					'text'   => $text,
					'button' => self::text( $v['button'] ?? '', 60 ),
				);
				if ( 5 === count( $variants ) ) {
					break;}
			}
			if ( array() === $variants ) {
				continue;}
			$id = filter_var( $row['id'] ?? 0, FILTER_VALIDATE_INT );
			if ( false === $id || 0 >= $id || isset( $used[ $id ] ) ) {
				$id = ++$max;}
			$used[ $id ] = true;
			$types       = array();
			foreach ( (array) ( $row['post_types'] ?? array() ) as $type ) {
				$type = self::key( $type );
				if ( '' !== $type ) {
					$types[] = $type;}
			}
			$terms = array();
			foreach ( (array) ( $row['terms'] ?? array() ) as $taxonomy => $ids ) {
				$clean = array();
				foreach ( (array) $ids as $term ) {
					$term = filter_var( $term, FILTER_VALIDATE_INT );
					if ( false !== $term && 0 < $term ) {
						$clean[] = $term;
					}
				} if ( array() !== $clean ) {
					$terms[ $taxonomy ] = array_values( array_unique( $clean ) );}
			}
			$result[] = array(
				'id'          => $id,
				'enabled'     => ! empty( $row['enabled'] ),
				'destination' => $row['destination'],
				'place'       => in_array( $row['place'] ?? '', self::PLACES, true ) ? $row['place'] : 'end',
				'post_types'  => array_values( array_unique( $types ) ),
				'terms'       => $terms,
				'game'        => self::key( $row['game'] ?? '' ),
				'material'    => self::key( $row['material'] ?? '' ),
				'variants'    => $variants,
			);
		}
		return $result;
	}
	/**
	 * Очищает строковый ключ.
	 * @param mixed $value Значение.
	 * @return string
	 */
	private static function key( $value ): string {
		return is_string( $value ) ? (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ) : ''; }
	/**
	 * Очищает текст варианта.
	 * @param mixed $value Значение.
	 * @param int $limit Длина.
	 * @return string
	 */
	private static function text( $value, int $limit ): string {
	 // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Чистая функция без WordPress.
		$text = is_string( $value ) ? trim( strip_tags( $value ) ) : '';
		preg_match_all( '/./us', $text, $chars );
		return implode( '', array_slice( $chars[0], 0, $limit ) );
	}
	/**
	 * Проверяет применимость.
	 * @param array $rule Правило.
	 * @param array $facts Факты записи.
	 * @return bool
	 */
	public static function matches( array $rule, array $facts ): bool {
		if ( empty( $rule['enabled'] ) ) {
			return false;}
		if ( ! empty( $rule['post_types'] ) && ! in_array( $facts['post_type'] ?? '', $rule['post_types'], true ) ) {
			return false;}
		foreach ( array( 'game', 'material' ) as $key ) {
			if ( ! empty( $rule[ $key ] ) && ( $facts[ $key ] ?? '' ) !== $rule[ $key ] ) {
				return false;}
		}
		if ( ! empty( $rule['terms'] ) ) {
			foreach ( $rule['terms'] as $taxonomy => $ids ) {
				if ( array_intersect( $ids, $facts['terms'][ $taxonomy ] ?? array() ) ) {
					return true;
				}
			} return false;}
		return true;
	}
	/**
	 * Выбирает первые подходящие правила.
	 * @param array $rules Правила.
	 * @param array $facts Факты записи.
	 * @param int $limit Лимит.
	 * @return array
	 */
	public static function select( array $rules, array $facts, int $limit = Placement::MAX_BLOCKS ): array {
		$result = array();
		if ( 0 >= $limit ) {
			return $result;
		} foreach ( $rules as $rule ) {
			if ( self::matches( $rule, $facts ) ) {
				$result[] = $rule;
				if ( count( $result ) >= $limit ) {
					break;
				}
			}
		} return $result;
	}
	/**
	 * Возвращает индекс варианта на день.
	 * @param array $rule Правило.
	 * @param int $post_id Запись.
	 * @param int $day День эпохи.
	 * @return int
	 */
	public static function variant( array $rule, int $post_id, int $day ): int {
		$count = count( $rule['variants'] ?? array() );
		return 1 >= $count ? 0 : ( ( $post_id % $count + $day % $count ) % $count + $count ) % $count; }
}
