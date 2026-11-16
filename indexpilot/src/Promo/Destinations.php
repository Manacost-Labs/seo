<?php
/**
 * Справочник площадок для переходов.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Promo;

/** Справочник адресов и меток перехода. */
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Чистая функция работает без WordPress.
final class Destinations {
	public const OPTION = 'indexpilot_promo_destinations';

	/**
	 * Площадки команды: с ними список создаётся при первом запуске плагина. Дальше он правится в админке.
	 */
	public const DEFAULTS = array(
		array(
			'name' => 'Boosty',
			'url'  => 'https://boosty.to/kolodahearthstone',
			'kind' => 'paid',
			'code' => 'boosty',
		),
		array(
			'name' => 'Hearthpulse',
			'url'  => 'https://hearthpulse.net/',
			'kind' => 'paid',
			'code' => 'hearthpulse',
		),
		array(
			'name' => 'ВКонтакте',
			'url'  => 'https://vk.com/manacost',
			'kind' => 'social',
			'code' => 'vk',
		),
		array(
			'name' => 'Telegram',
			'url'  => 'https://t.me/manacost_ru',
			'kind' => 'social',
			'code' => 'telegram',
		),
	);
	/**
	 * Проверяет адреса и присваивает уникальные коды без обращения к WordPress.
	 *
	 * @param array $rows Входные строки.
	 * @return array
	 */
	public static function sanitize( array $rows ): array {
		$result = array();
		$used   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue; }
			$url   = trim( (string) ( $row['url'] ?? '' ) );
			$parts = parse_url( $url );
			if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
				continue; }
			$name = trim( strip_tags( (string) ( $row['name'] ?? '' ) ) );
			$base = trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( ( '' !== (string) ( $row['code'] ?? '' ) ? (string) $row['code'] : $name ) ) ), '-' );
			if ( '' === $base ) {
				$base = 'destination'; }
			// Код входит в адрес перехода и в счётчик: там он не длиннее 64 знаков вместе с номером повтора.
			$base   = rtrim( substr( $base, 0, 48 ), '-' );
			$code   = $base;
			$suffix = 2;
			while ( isset( $used[ $code ] ) ) {
				$code = $base . '-' . ( $suffix++ ); }
			$used[ $code ] = true;
			$utm           = array();
			foreach ( array( 'source', 'medium', 'campaign' ) as $key ) {
				$utm[ $key ] = trim( strip_tags( (string) ( $row['utm'][ $key ] ?? '' ) ) );
			}
			$result[] = array(
				'name' => $name,
				'url'  => $url,
				'kind' => in_array( $row['kind'] ?? '', array( 'paid', 'social', 'own' ), true ) ? $row['kind'] : 'own',
				'code' => $code,
				'utm'  => $utm,
			);
		}
		return $result;
	}
	/** Возвращает сохранённые площадки. */
	public function all(): array {
		return self::sanitize( (array) get_option( self::OPTION, array() ) );
	}
	/**
	 * Находит площадку по коду.
	 *
	 * @param string $code Код площадки.
	 * @return array|null
	 */
	public function find( string $code ): ?array {
		foreach ( $this->all() as $row ) {
			if ( $code === $row['code'] ) {
				return $row; }
		}
		return null;
	}
	/** Возвращает социальные площадки. */
	public function social(): array {
		return array_values( array_filter( $this->all(), static fn( array $row ): bool => 'social' === $row['kind'] ) );
	}
	/** Возвращает уникальные адреса для sameAs. */
	public function urls(): array {
		return array_values( array_unique( array_column( $this->all(), 'url' ) ) );
	}
	/** Возвращает наборы меток переходов. */
	public function utm_presets(): array {
		return array_map( static fn( array $row ): array => array( 'label' => $row['name'] ) + $row['utm'], $this->all() );
	}
}
