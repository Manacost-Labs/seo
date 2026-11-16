<?php
/**
 * Тип разметки статьи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

use IndexPilot\Game\MaterialType;

/**
 * Какой тип schema.org получает узел статьи. Редактор выбирает его в панели записи; «Авто» — тип из настроек типа записи,
 * а для новостей и патчей (их плагин определяет сам, см. MaterialType) — NewsArticle.
 *
 * Гайд размечается как Article с жанром «Гайд»: в справке Google по разметке статьи названы Article, NewsArticle
 * и BlogPosting, а TechArticle — нет. Значение выбора «Гайд» в мете записи осталось прежним (TechArticle),
 * чтобы записи, сохранённые раньше, не потеряли свой выбор. HowTo не подходит: он требует списка шагов,
 * а расширенные результаты для него отключены.
 */
final class ArticleType {

	public const AUTO    = '';
	public const ARTICLE = 'Article';
	public const NEWS    = 'NewsArticle';
	public const GUIDE   = 'TechArticle';
	public const NONE    = 'none';

	/**
	 * Значения, которые можно выбрать у записи.
	 */
	public const CHOICES = array( self::AUTO, self::ARTICLE, self::NEWS, self::GUIDE, self::NONE );

	/**
	 * Типы из настроек типа записи, при которых у записи есть узел статьи.
	 */
	public const TYPES = array( 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle' );

	/**
	 * Выбор редактора; неизвестное значение считается «Авто».
	 *
	 * @param string $value Значение из формы или REST.
	 */
	public static function sanitize( string $value ): string {
		$value = trim( $value );

		return in_array( $value, self::CHOICES, true ) ? $value : self::AUTO;
	}

	/**
	 * Тип узла статьи для записи; пустая строка — разметки статьи нет.
	 *
	 * @param string $choice   Выбор редактора у записи.
	 * @param string $fallback Тип из настроек типа записи.
	 * @param string $material Определённый тип материала — константа MaterialType; пусто — не определялся.
	 */
	public static function resolve( string $choice, string $fallback, string $material = '' ): string {
		$type = self::chosen( $choice, $fallback, $material );

		return self::GUIDE === $type ? self::ARTICLE : $type;
	}

	/**
	 * Выбран ли для записи «Гайд» — у записи или, при выборе «Авто», в настройках её типа. Такой статье в разметку
	 * добавляется жанр «Гайд».
	 *
	 * @param string $choice   Выбор редактора у записи.
	 * @param string $fallback Тип из настроек типа записи.
	 * @param string $material Определённый тип материала — константа MaterialType; пусто — не определялся.
	 */
	public static function is_guide( string $choice, string $fallback, string $material = '' ): bool {
		return self::GUIDE === self::chosen( $choice, $fallback, $material );
	}

	/**
	 * Выбранный тип до замены гайда на Article; пустая строка — разметки статьи нет.
	 *
	 * @param string $choice   Выбор редактора у записи.
	 * @param string $fallback Тип из настроек типа записи.
	 * @param string $material Определённый тип материала.
	 */
	private static function chosen( string $choice, string $fallback, string $material ): string {
		$choice = self::sanitize( $choice );

		if ( self::NONE === $choice ) {
			return '';
		}

		if ( self::AUTO !== $choice ) {
			return $choice;
		}

		if ( ! in_array( $fallback, self::TYPES, true ) ) {
			return '';
		}

		// «Авто»: событие с датой — новость или патч — получает NewsArticle, остальное — тип из настроек.
		return in_array( $material, array( MaterialType::NEWS, MaterialType::PATCH ), true ) ? self::NEWS : $fallback;
	}
}
