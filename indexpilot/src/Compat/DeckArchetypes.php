<?php
/**
 * SEO страниц архетипов из плагина колод hs-manacost.ru.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Compat;

/**
 * Плагин колод hs-manacost.ru сам собирает заголовок, описание и canonical страниц архетипов: режим, число колод,
 * винрейт, адрес с выбранным режимом (?hs_mode=standart). Отдаёт он их через фильтры AIOSEO — без AIOSEO их никто
 * не вызывает. Класс находит эти обработчики и пропускает через них значения IndexPilot: на страницах архетипов
 * берутся данные плагина колод, на остальных значения не меняются. Код плагина колод не правится.
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
		'indexpilot_canonical'   => array( 'aioseo_canonical_url', 'filter_aioseo_archetype_canonical_url' ),
	);

	/**
	 * Подключает мост. Вызывается, только когда теги печатает IndexPilot.
	 */
	public function register(): void {
		foreach ( self::MAP as $hook => $source ) {
			add_filter(
				$hook,
				fn( $value ) => self::pass( $value, $this->handler( $source[0], $source[1] ) ),
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
