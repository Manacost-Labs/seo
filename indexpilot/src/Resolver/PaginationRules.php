<?php
/**
 * Правила для второй и следующих страниц.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

/**
 * Страницы пагинации всегда остаются открытыми для перехода по ссылкам (follow) и получают собственные заголовок и каноникал.
 */
final class PaginationRules {

	/**
	 * Виды страниц, которые являются списками записей.
	 */
	private const ARCHIVES = array(
		Context::TERM,
		Context::AUTHOR,
		Context::POST_TYPE_ARCHIVE,
		Context::HOME,
		Context::DATE,
	);

	/**
	 * Это вторая или следующая страница.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public static function is_paged( Context $context ): bool {
		return $context->page > 1;
	}

	/**
	 * Подпись «страница N».
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $format  Формат подписи с %d.
	 */
	public static function label( Context $context, string $format ): string {
		return self::is_paged( $context ) ? sprintf( $format, $context->page ) : '';
	}

	/**
	 * Нужно ли закрыть страницу пагинации архива от индексации.
	 *
	 * @param Context $context     Контекст страницы.
	 * @param bool    $index_paged Настройка «индексировать страницы 2 и далее».
	 */
	public static function should_noindex( Context $context, bool $index_paged ): bool {
		return ! $index_paged && self::is_paged( $context ) && in_array( $context->type, self::ARCHIVES, true );
	}

	/**
	 * Добавляет номер страницы к заголовку, если его там ещё нет.
	 *
	 * @param string  $title     Заголовок.
	 * @param Context $context   Контекст страницы.
	 * @param string  $format    Формат подписи с %d.
	 * @param string  $separator Разделитель.
	 */
	public static function title( string $title, Context $context, string $format, string $separator ): string {
		$label = self::label( $context, $format );

		if ( '' === $label || '' === $title || false !== mb_stripos( $title, $label ) ) {
			return $title;
		}

		return $title . ' ' . $separator . ' ' . $label;
	}

	/**
	 * Добавляет «Страница N.» к описанию, чтобы описания страниц пагинации не совпадали.
	 *
	 * @param string  $description Описание.
	 * @param Context $context     Контекст страницы.
	 * @param string  $format      Формат подписи с %d.
	 */
	public static function description( string $description, Context $context, string $format ): string {
		$label = self::label( $context, $format );

		if ( '' === $label || '' === $description ) {
			return $description;
		}

		$label = mb_strtoupper( mb_substr( $label, 0, 1 ) ) . mb_substr( $label, 1 );

		$description = rtrim( $description );

		// Описание без знака в конце получает точку: «…о Hearthstone. Страница 2.»
		if ( 1 !== preg_match( '/[.!?…]$/u', $description ) ) {
			$description .= '.';
		}

		return $description . ' ' . $label . '.';
	}
}
