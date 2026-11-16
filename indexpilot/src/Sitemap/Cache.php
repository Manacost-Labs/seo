<?php
/**
 * Кэш файлов карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Settings\Settings;

/**
 * Готовый XML хранится несколько часов и сбрасывается, когда меняется опубликованная запись, термин или настройки.
 */
final class Cache {

	private const PREFIX = 'indexpilot_sm_';
	private const KEYS   = 'indexpilot_sitemap_keys';

	/**
	 * Обычный срок хранения файла: шесть часов.
	 */
	public const TTL = 21600;

	/**
	 * Подключает сброс кэша.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_post_status' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'created_term', array( $this, 'flush' ) );
		add_action( 'edited_term', array( $this, 'flush' ) );
		add_action( 'delete_term', array( $this, 'flush' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'flush' ) );
	}

	/**
	 * Возвращает XML из кэша или строит его.
	 *
	 * @param string   $name  Имя файла карты.
	 * @param callable      $build Функция, возвращающая XML или null, если такого файла нет.
	 * @param callable|null $ttl   Срок хранения в секундах, если он короче обычного: вызывается после сборки файла.
	 */
	public function remember( string $name, callable $build, ?callable $ttl = null ): ?string {
		$key    = self::PREFIX . md5( $name );
		$cached = get_transient( $key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$xml = $build();

		if ( ! is_string( $xml ) ) {
			return null;
		}

		set_transient( $key, $xml, null === $ttl ? self::TTL : max( 60, min( self::TTL, (int) $ttl() ) ) );

		$keys = get_option( self::KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();

		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::KEYS, $keys, false );
		}

		return $xml;
	}

	/**
	 * Сбрасывает кэш, если запись была или стала опубликованной.
	 *
	 * @param string $new_status Новый статус.
	 * @param string $old_status Прежний статус.
	 */
	public function on_post_status( $new_status, $old_status ): void {
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			$this->flush();
		}
	}

	/**
	 * Удаляет все сохранённые файлы карты.
	 */
	public function flush(): void {
		$keys = get_option( self::KEYS, array() );

		if ( ! is_array( $keys ) || array() === $keys ) {
			return;
		}

		foreach ( $keys as $key ) {
			delete_transient( (string) $key );
		}

		delete_option( self::KEYS );
	}
}
