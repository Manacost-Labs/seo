<?php
/**
 * Настройки плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Settings;

/**
 * Сохранённые настройки поверх значений по умолчанию. Тип значения всегда совпадает с типом значения по умолчанию.
 */
final class Settings {

	public const OPTION = 'indexpilot_settings';

	/**
	 * Конструктор.
	 *
	 * @param array<string, mixed> $saved Содержимое опции из базы.
	 * @param array<string, mixed> $site  Значения по умолчанию для конкретного сайта (тот же формат, что у опции).
	 */
	public function __construct(
		private array $saved = array(),
		private array $site = array()
	) {
	}

	/**
	 * Читает настройки из базы WordPress.
	 *
	 * @param array<string, mixed> $site Значения по умолчанию для конкретного сайта.
	 */
	public static function from_option( array $site = array() ): self {
		$saved = get_option( self::OPTION, null );

		// Несохранённые настройки WordPress искал бы в базе на каждой странице: пустая запись с автозагрузкой снимает этот запрос.
		if ( null === $saved ) {
			add_option( self::OPTION, array() );
			$saved = array();
		}

		return new self( is_array( $saved ) ? $saved : array(), $site );
	}

	/**
	 * Общая настройка или null, если такого ключа нет.
	 *
	 * @param string $key Имя настройки.
	 */
	public function general( string $key ): mixed {
		$values = self::merge( Defaults::general(), $this->section( 'general' ) );

		return $values[ $key ] ?? null;
	}

	/**
	 * Настройки типа записи.
	 *
	 * @param string $post_type Тип записи.
	 * @return array<string, mixed>
	 */
	public function post_type( string $post_type ): array {
		return $this->layered( Defaults::post_type( $post_type ), 'post_types', $post_type );
	}

	/**
	 * Настройки таксономии.
	 *
	 * @param string $taxonomy Таксономия.
	 * @return array<string, mixed>
	 */
	public function taxonomy( string $taxonomy ): array {
		return $this->layered( Defaults::taxonomy( $taxonomy ), 'taxonomies', $taxonomy );
	}

	/**
	 * Настройки страниц без собственного объекта: главная, поиск, 404, автор, архивы.
	 *
	 * @param string $context_type Вид страницы — константа Context.
	 * @return array<string, mixed>
	 */
	public function special( string $context_type ): array {
		return $this->layered( Defaults::special( $context_type ), 'special', $context_type );
	}

	/**
	 * Готовит настройки из формы к сохранению: оставляет только известные значения, которые отличаются
	 * от значений по умолчанию. Тогда изменение шаблонов по умолчанию в коде доходит до сайта,
	 * а не перекрывается копией, сохранённой вместе с формой.
	 *
	 * @param array<string, mixed>               $input Данные формы в формате опции.
	 * @param array<string, mixed>               $site  Значения по умолчанию для конкретного сайта.
	 * @param array<string, array<int, string>>  $names Разрешённые имена по разделам post_types, taxonomies, special.
	 * @return array<string, mixed>
	 */
	public static function changes( array $input, array $site, array $names ): array {
		$candidate = new self( $input, $site );
		$baseline  = new self( array(), $site );
		$changes   = array();

		foreach ( array_keys( Defaults::general() ) as $key ) {
			if ( $candidate->general( $key ) !== $baseline->general( $key ) ) {
				$changes['general'][ $key ] = $candidate->general( $key );
			}
		}

		$readers = array(
			'post_types' => 'post_type',
			'taxonomies' => 'taxonomy',
			'special'    => 'special',
		);

		foreach ( $readers as $section => $reader ) {
			foreach ( $names[ $section ] ?? array() as $name ) {
				$default = $baseline->$reader( (string) $name );

				foreach ( $candidate->$reader( (string) $name ) as $key => $value ) {
					if ( $value !== $default[ $key ] ) {
						$changes[ $section ][ (string) $name ][ $key ] = $value;
					}
				}
			}
		}

		return $changes;
	}

	/**
	 * Накладывает слои: общие значения по умолчанию → значения сайта → сохранённые настройки.
	 *
	 * @param array<string, mixed> $defaults Общие значения по умолчанию.
	 * @param string               $section  Раздел настроек.
	 * @param string               $name     Тип записи, таксономия или вид страницы.
	 * @return array<string, mixed>
	 */
	private function layered( array $defaults, string $section, string $name ): array {
		$site  = $this->site[ $section ][ $name ] ?? array();
		$saved = $this->section( $section )[ $name ] ?? array();

		return self::merge(
			self::merge( $defaults, is_array( $site ) ? $site : array() ),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Раздел сохранённых настроек.
	 *
	 * @param string $name Имя раздела.
	 * @return array<string, mixed>
	 */
	private function section( string $name ): array {
		$section = $this->saved[ $name ] ?? array();

		return is_array( $section ) ? $section : array();
	}

	/**
	 * Накладывает сохранённые значения на значения по умолчанию.
	 *
	 * Неизвестные ключи отбрасываются, типы приводятся к типу значения по умолчанию.
	 * Пустой шаблон заголовка не имеет смысла, поэтому заменяется шаблоном по умолчанию.
	 *
	 * @param array<string, mixed> $defaults Значения по умолчанию.
	 * @param array<string, mixed> $saved    Сохранённые значения.
	 * @return array<string, mixed>
	 */
	private static function merge( array $defaults, array $saved ): array {
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $saved ) ) {
				continue;
			}

			$value = $saved[ $key ];

			if ( is_bool( $default ) ) {
				$defaults[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				$defaults[ $key ] = is_numeric( $value ) ? (int) $value : $default;
			} elseif ( is_array( $default ) ) {
				$defaults[ $key ] = is_array( $value ) ? array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) ) : $default;
			} elseif ( is_scalar( $value ) ) {
				$value            = trim( (string) $value );
				$defaults[ $key ] = ( 'title' === $key && '' === $value ) ? $default : $value;
			}
		}

		return $defaults;
	}
}
