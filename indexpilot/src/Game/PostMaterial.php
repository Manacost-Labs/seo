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
 */
final class PostMaterial {

	/**
	 * Тип материала записи — константа MaterialType.
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

		return MaterialType::detect( $title ?? (string) $post->post_title, $categories, $content ?? (string) $post->post_content, (string) $post->post_type );
	}
}
