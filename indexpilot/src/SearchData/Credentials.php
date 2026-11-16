<?php
/**
 * Доступы к Google Search Console и Яндекс Вебмастеру.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\SearchData;

/**
 * Хранит файл ключа служебной учётной записи Google, адрес ресурса в Search Console и токен Яндекс Вебмастера
 * в отдельной опции без автозагрузки: на страницах сайта она не читается и в память WordPress не попадает.
 * В интерфейс ключ и токен не выводятся — только адрес служебной учётной записи и отметка «токен сохранён».
 */
final class Credentials {

	public const OPTION = 'indexpilot_search_data';

	/**
	 * Самый большой допустимый файл ключа, байт.
	 */
	public const MAX_KEY_SIZE = 20000;

	/**
	 * Прочитанные значения.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $data = null;

	/**
	 * Конструктор.
	 *
	 * @param Http|null $http Сеть; null — запросы WordPress.
	 */
	public function __construct( private ?Http $http = null ) {
	}

	/**
	 * Значение по имени.
	 *
	 * @param string $name google_key, google_site или yandex_token.
	 */
	private function get( string $name ): string {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$this->data = is_array( $stored ) ? array_map( 'strval', array_filter( $stored, 'is_scalar' ) ) : array();
		}

		return (string) ( $this->data[ $name ] ?? '' );
	}

	/**
	 * Записывает значение.
	 *
	 * @param string $name  Имя.
	 * @param string $value Значение; пусто — удалить.
	 */
	private function set( string $name, string $value ): void {
		$this->get( $name );
		$this->data[ $name ] = $value;
		$this->data          = array_filter( $this->data, 'strlen' );

		if ( array() === $this->data ) {
			delete_option( self::OPTION );
			return;
		}

		// Без автозагрузки: ключ и токен не попадают в память на каждой странице.
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $this->data, '', false );
		} else {
			update_option( self::OPTION, $this->data, false );
		}
	}

	/**
	 * Сохраняет файл ключа после проверки. Ошибка — исключение с понятным текстом.
	 *
	 * @param string $json Содержимое файла.
	 * @throws SearchDataException Файл не похож на ключ служебной учётной записи.
	 */
	public function save_google_key( string $json ): string {
		if ( strlen( $json ) > self::MAX_KEY_SIZE ) {
			throw new SearchDataException( 'Файл ключа слишком большой: нужен JSON-файл служебной учётной записи Google' );
		}

		$client = new GoogleClient( $json, $this->transport(), 'time' );
		$this->set( 'google_key', $json );

		return $client->email();
	}

	/**
	 * Удаляет файл ключа.
	 */
	public function remove_google_key(): void {
		$this->set( 'google_key', '' );
	}

	/**
	 * Адрес ресурса в Search Console: адрес сайта со слэшем или sc-domain:имя.
	 *
	 * @param string $site Адрес.
	 */
	public function set_google_site( string $site ): void {
		$site = trim( $site );

		if ( '' !== $site && ! str_starts_with( $site, 'sc-domain:' ) ) {
			$site = trailingslashit( esc_url_raw( $site ) );
		}

		$this->set( 'google_site', sanitize_text_field( $site ) );
	}

	/**
	 * Ресурс в Search Console; по умолчанию — адрес главной.
	 */
	public function google_site(): string {
		$site = $this->get( 'google_site' );

		return '' !== $site ? $site : home_url( '/' );
	}

	/**
	 * Сохраняет токен Яндекс Вебмастера.
	 *
	 * @param string $token Токен; пусто — удалить.
	 */
	public function set_yandex_token( string $token ): void {
		$this->set( 'yandex_token', (string) preg_replace( '/[^A-Za-z0-9_\-.]/', '', $token ) );
	}

	/**
	 * Есть ли ключ Google.
	 */
	public function has_google(): bool {
		return '' !== $this->get( 'google_key' );
	}

	/**
	 * Есть ли токен Яндекса.
	 */
	public function has_yandex(): bool {
		return '' !== $this->get( 'yandex_token' );
	}

	/**
	 * Адрес служебной учётной записи Google — его владелец добавляет пользователем в Search Console.
	 */
	public function google_email(): string {
		$key = json_decode( $this->get( 'google_key' ), true );

		return is_array( $key ) ? sanitize_email( (string) ( $key['client_email'] ?? '' ) ) : '';
	}

	/**
	 * Клиент Google; null — ключа нет или он испорчен.
	 */
	public function google(): ?GoogleClient {
		if ( ! $this->has_google() ) {
			return null;
		}

		try {
			return new GoogleClient( $this->get( 'google_key' ), $this->transport(), 'time' );
		} catch ( SearchDataException $error ) {
			return null;
		}
	}

	/**
	 * Клиент Яндекса; null — токена нет.
	 */
	public function yandex(): ?YandexClient {
		return $this->has_yandex() ? new YandexClient( $this->get( 'yandex_token' ), $this->transport() ) : null;
	}

	/**
	 * Подключённые поисковики.
	 *
	 * @return list<string>
	 */
	public function engines(): array {
		return array_keys( array_filter( array( 'google' => $this->has_google(), 'yandex' => $this->has_yandex() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Сеть.
	 */
	private function transport(): Http {
		return $this->http ?? new WpHttp();
	}
}
