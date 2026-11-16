<?php
/**
 * Общие настройки индексации из AIOSEO.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Import;

/**
 * Импорт записей переносит поля каждой записи, а закрытые от индексации виды страниц AIOSEO хранит в своих
 * общих настройках: метки, архивы авторов, дат, поиск. Там же — шаблоны заголовков видов страниц и разделитель.
 * Без их переноса после отключения AIOSEO такие страницы открылись бы для индексации, а заголовки поменялись бы.
 * Переносятся запреты (noindex), шаблоны заголовков и разделитель — только там, где в IndexPilot ничего не задано.
 * Шаблон колод не переносится: у колод свои заголовки с датой и режимом, иначе тысячи колод носили бы одно название.
 */
final class AioseoSettings {

	/**
	 * Служебные страницы AIOSEO → виды страниц IndexPilot.
	 */
	private const ARCHIVES = array(
		'author' => 'author',
		'date'   => 'date',
		'search' => 'search',
	);

	/**
	 * Запреты индексации из настроек AIOSEO в виде настроек IndexPilot.
	 *
	 * @param array<string, mixed> $options Опция aioseo_options (разобранный JSON).
	 * @param array<string, mixed> $dynamic Опция aioseo_options_dynamic (разобранный JSON).
	 * @return array<string, array<string, mixed>>
	 */
	public static function patch( array $options, array $dynamic ): array {
		$patch     = array();
		$separator = trim( (string) ( $options['searchAppearance']['global']['separator'] ?? '' ) );

		if ( '' !== $separator ) {
			$patch['general']['separator'] = $separator;
		}

		// Описание главной страницы.
		$home = trim( (string) ( $options['searchAppearance']['global']['metaDescription'] ?? '' ) );

		if ( '' !== $home ) {
			$patch['special']['home']['description'] = VariableSyntaxConverter::from_aioseo( $home );
		}

		foreach ( array(
			'taxonomies' => 'taxonomies',
			'postTypes'  => 'post_types',
		) as $group => $section ) {
			foreach ( (array) ( $dynamic['searchAppearance'][ $group ] ?? array() ) as $name => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( self::closed( $item ) ) {
					$patch[ $section ][ (string) $name ]['noindex'] = true;
				}

				$title = trim( (string) ( $item['title'] ?? '' ) );

				if ( '' !== $title && \IndexPilot\Game\DeckVariables::POST_TYPE !== (string) $name ) {
					$patch[ $section ][ (string) $name ]['title'] = VariableSyntaxConverter::from_aioseo( $title );
				}
			}
		}

		foreach ( self::ARCHIVES as $archive => $special ) {
			$item = $options['searchAppearance']['archives'][ $archive ] ?? null;

			if ( is_array( $item ) && self::closed( $item ) ) {
				$patch['special'][ $special ]['noindex'] = true;
			}
		}

		return $patch;
	}

	/**
	 * Дописывает перенесённое в настройки IndexPilot, не трогая то, что уже задано.
	 *
	 * @param array<string, mixed>                $settings Настройки IndexPilot.
	 * @param array<string, array<string, mixed>> $patch    Перенесённое из AIOSEO.
	 * @return array<string, mixed>
	 */
	public static function merge( array $settings, array $patch ): array {
		foreach ( $patch as $section => $items ) {
			foreach ( $items as $name => $values ) {
				if ( ! is_array( $values ) ) {
					// Настройка раздела general: разделитель.
					if ( ! isset( $settings[ $section ][ $name ] ) ) {
						$settings[ $section ][ $name ] = $values;
					}

					continue;
				}

				foreach ( $values as $key => $value ) {
					if ( ! isset( $settings[ $section ][ $name ][ $key ] ) ) {
						$settings[ $section ][ $name ][ $key ] = $value;
					}
				}
			}
		}

		return $settings;
	}

	/**
	 * Переносит запреты на сайте. Возвращает перенесённое: раздел → список имён.
	 *
	 * @param bool $dry_run Только показать, ничего не записывать.
	 * @return array<string, list<string>>
	 */
	public static function apply( bool $dry_run = false ): array {
		$options = json_decode( (string) get_option( 'aioseo_options', '' ), true );
		$dynamic = json_decode( (string) get_option( 'aioseo_options_dynamic', '' ), true );
		$current = get_option( 'indexpilot_settings', array() );
		$current = is_array( $current ) ? $current : array();
		$merged  = self::merge( $current, self::patch( is_array( $options ) ? $options : array(), is_array( $dynamic ) ? $dynamic : array() ) );
		$changed = array();

		foreach ( $merged as $section => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $name => $values ) {
				if ( ( $current[ $section ][ $name ] ?? null ) !== $values ) {
					$changed[ (string) $section ][] = (string) $name;
				}
			}
		}

		// Картинка соцсетей по умолчанию: AIOSEO хранит адрес, IndexPilot — номер вложения.
		$default = (string) ( is_array( $options ) ? ( $options['social']['facebook']['general']['defaultImagePosts'] ?? '' ) : '' );
		$image   = '' !== $default && empty( $current['general']['default_og_image_id'] ) ? attachment_url_to_postid( $default ) : 0;

		if ( $image > 0 ) {
			$merged['general']['default_og_image_id'] = $image;
			$changed['general'][]                     = 'default_og_image_id';
		}

		if ( ! $dry_run && array() !== $changed ) {
			update_option( 'indexpilot_settings', $merged );
		}

		return $changed;
	}

	/**
	 * Закрыт ли вид страниц: скрыт из поиска или в своих настройках robots стоит noindex.
	 *
	 * @param array<string, mixed> $item Настройки вида страниц AIOSEO.
	 */
	private static function closed( array $item ): bool {
		if ( false === ( $item['show'] ?? true ) ) {
			return true;
		}

		$robots = (array) ( $item['advanced']['robotsMeta'] ?? array() );

		return false === ( $robots['default'] ?? true ) && true === ( $robots['noindex'] ?? false );
	}
}
