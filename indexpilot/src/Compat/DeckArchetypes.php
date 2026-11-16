<?php
/**
 * SEO страниц архетипов из плагина колод hs-manacost.ru.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Плагин колод hs-manacost.ru сам собирает заголовок и описание страниц архетипов: режим, число колод, винрейт.
 * Отдаёт он их через фильтры AIOSEO — без AIOSEO их никто не вызывает. Класс находит эти обработчики и пропускает
 * через них значения IndexPilot: на страницах архетипов берутся данные плагина колод, на остальных значения не меняются.
 * Canonical плагина колод (адрес с ?hs_mode=standart, одинаковый даже для вольного режима) не берётся: страница
 * указывает на свой чистый адрес — тот же, что в карте сайта. Описание обрезается до 160 знаков по слову.
 * Код плагина колод не правится.
 */
final class DeckArchetypes {

	/**
	 * Класс плагина колод.
	 */
	public const PLUGIN_CLASS = 'HS_Decks_Manager';

	/**
	 * Фильтр IndexPilot → [фильтр AIOSEO, на котором висит обработчик, метод плагина колод].
	 */
	public const MAP = array(
		'indexpilot_title'       => array( 'aioseo_title', 'filter_aioseo_archetype_title' ),
		'indexpilot_description' => array( 'aioseo_description', 'filter_aioseo_archetype_description' ),
	);

	/**
	 * Предел длины описания: длиннее поисковик всё равно обрежет.
	 */
	public const DESCRIPTION_LIMIT = 160;

	/**
	 * Подключает мост. Вызывается, только когда теги печатает IndexPilot.
	 */
	public function register(): void {
		foreach ( self::MAP as $hook => $source ) {
			add_filter(
				$hook,
				fn( $value ) => 'indexpilot_description' === $hook
					? self::shorten( self::pass( $value, $this->handler( $source[0], $source[1] ) ) )
					: self::pass( $value, $this->handler( $source[0], $source[1] ) ),
				20
			);
		}
	}

	/**
	 * Значение после обработчика плагина колод; пустой ответ или его отсутствие оставляют значение IndexPilot.
	 *
	 * @param mixed         $value   Значение IndexPilot.
	 * @param callable|null $handler Обработчик плагина колод.
	 * @return mixed
	 */
	public static function pass( $value, ?callable $handler ) {
		if ( null === $handler || ! is_string( $value ) ) {
			return $value;
		}

		$result = $handler( $value );

		return is_string( $result ) && '' !== trim( $result ) ? $result : $value;
	}

	/**
	 * Описание не длиннее предела: обрезается по границе слова, с многоточием.
	 *
	 * @param mixed $text Описание.
	 * @return mixed
	 */
	public static function shorten( $text ) {
		if ( ! is_string( $text ) || mb_strlen( $text ) <= self::DESCRIPTION_LIMIT ) {
			return $text;
		}

		$cut  = mb_substr( $text, 0, self::DESCRIPTION_LIMIT - 1 );
		$last = mb_strrpos( $cut, ' ' );
		$cut  = false !== $last && $last > self::DESCRIPTION_LIMIT / 2 ? mb_substr( $cut, 0, $last ) : $cut;

		return (string) preg_replace( '/[\s,.;:—-]+$/u', '', $cut ) . '…';
	}

	/**
	 * Обработчик плагина колод на фильтре AIOSEO.
	 *
	 * @param string $hook   Фильтр AIOSEO.
	 * @param string $method Метод плагина колод.
	 */
	private function handler( string $hook, string $method ): ?callable {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return null;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;

				if ( is_array( $function ) && is_object( $function[0] ?? null ) && self::PLUGIN_CLASS === get_class( $function[0] ) && ( $function[1] ?? '' ) === $method && is_callable( $function ) ) {
					return $function;
				}
			}
		}

		return null;
	}
}
