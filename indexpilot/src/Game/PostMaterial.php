<?php
/**
 * Тип материала записи WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Game;

use IndexPilot\Meta\PrimaryCategory;

/**
 * Собирает из WordPress то, по чему MaterialType определяет тип материала: название, рубрики, текст и тип записи.
 * Рубрики берутся из кэша терминов записи, поэтому на странице сайта это не добавляет запросов к базе.
 * У записи из раздела другой игры (поле SiteGames::META) типы материалов свои — например, WowMaterialType.
 */
final class PostMaterial {

	/**
	 * Тип материала записи — константа MaterialType или типов материалов её игры.
	 *
	 * @param \WP_Post    $post    Запись.
	 * @param string|null $title   Несохранённое название из редактора; null — название записи.
	 * @param string|null $content Несохранённый текст из редактора; null — текст записи.
	 */
	public static function detect( \WP_Post $post, ?string $title = null, ?string $content = null ): string {
		$categories = array();

		foreach ( PrimaryCategory::terms( (int) $post->ID ) as $term ) {
			$categories[] = (string) $term->name;
		}

		$types = self::types( $post );

		return $types::detect( $title ?? (string) $post->post_title, $categories, $content ?? (string) $post->post_content, (string) $post->post_type );
	}

	/**
	 * Название типа материала записи для людей.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public static function label( \WP_Post $post ): string {
		$types = self::types( $post );

		return $types::label( self::detect( $post ) );
	}

	/**
	 * Класс типов материалов игры записи. Поле игры читается из кэша полей записи.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public static function types( \WP_Post $post ): string {
		$game = (string) get_post_meta( (int) $post->ID, SiteGames::META, true );

		if ( '' !== $game ) {
			foreach ( Games::defaults() as $entry ) {
				if ( $entry['slug'] === $game && class_exists( (string) $entry['material'] ) ) {
					return (string) $entry['material'];
				}
			}
		}

		return MaterialType::class;
	}
}
