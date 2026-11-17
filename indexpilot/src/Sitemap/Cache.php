<?php
/**
 * Кэш файлов карты сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Sitemap;

use IndexPilot\Game\Module;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Settings\Settings;

/**
 * Готовый XML хранится несколько часов и сбрасывается, когда меняется опубликованная запись, термин или настройки.
 */
final class Cache {

	private const PREFIX     = 'indexpilot_sm_';
	private const GENERATION = 'indexpilot_sitemap_generation';

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
		foreach ( array( 'post', 'term' ) as $object ) {
			foreach ( array( 'added', 'updated', 'deleted' ) as $event ) {
				add_action( "{$event}_{$object}_meta", array( $this, 'on_meta' ), 10, 3 );
			}
		}
		foreach ( array( 'add', 'update', 'delete' ) as $event ) {
			add_action( $event . '_option_' . Module::VERSIONS_OPTION, array( $this, 'flush' ) );
		}
	}

	/**
	 * Прямой импорт меты не вызывает сохранение записи или термина.
	 *
	 * @param mixed  $meta_ids ID строки или список удалённых строк.
	 * @param int    $object_id ID записи или термина.
	 * @param string $key Ключ метаданных.
	 */
	public function on_meta( $meta_ids, $object_id, $key ): void {
		if ( MetaKeys::NOINDEX === $key || MetaKeys::CANONICAL === $key ) {
			$this->flush();
		}
	}

	/**
	 * Возвращает XML из кэша или строит его.
	 *
	 * @param string   $name  Имя файла карты.
	 * @param callable      $build Функция, возвращающая XML или null, если такого файла нет.
	 * @param callable|null $ttl   Срок хранения в секундах, если он короче обычного: вызывается после сборки файла.
	 */
	public function remember( string $name, callable $build, ?callable $ttl = null ): ?string {
		$generation = get_option( self::GENERATION );
		if ( false === $generation ) {
			$generation = wp_generate_uuid4();
			if ( ! add_option( self::GENERATION, $generation, '', false ) ) {
				$generation = get_option( self::GENERATION );
			}
		}
		$key    = self::PREFIX . md5( $name );
		$cached = get_transient( $key );

		if ( is_string( $generation ) && isset( $cached['generation'], $cached['xml'] ) && $cached['generation'] === $generation ) {
			return $cached['xml'];
		}

		$xml = $build();

		if ( ! is_string( $xml ) ) {
			return null;
		}

		if ( is_string( $generation ) ) {
			set_transient(
				$key,
				array(
					'generation' => $generation,
					'xml'        => $xml,
				),
				null === $ttl ? self::TTL : max( 60, min( self::TTL, (int) $ttl() ) )
			);
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
	 * Следующее чтение создаст новое поколение. Пачка правок не перестраивает XML на каждой записи.
	 */
	public function flush(): void {
		if ( false !== get_option( self::GENERATION ) ) {
			delete_option( self::GENERATION );
		}
	}
}
