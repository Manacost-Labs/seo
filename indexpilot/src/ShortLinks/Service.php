<?php
/**
 * Правила коротких ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\ShortLinks;

/**
 * Создание своих коротких ссылок и поиск адреса по коду.
 *
 * Сначала код ищется среди своих ссылок, затем проверяется, не код ли это записи.
 * Цели задаёт только редактор сайта, и только адреса http(s): посторонний не может превратить
 * сайт в перенаправление на произвольный адрес.
 */
final class Service {

	public const CODES = array( 301, 302, 307 );

	private const MAX_TARGET = 2000;

	/**
	 * Добавляет отсутствующие метки, сохраняя исходные параметры и фрагмент.
	 *
	 * @param string                $target Адрес.
	 * @param array<string, string> $utm Метки source, medium, campaign.
	 * @return string
	 */
	public static function append_utm( string $target, array $utm ): string {
		$parts = explode( '#', $target, 2 );
		$query = explode( '?', $parts[0], 2 );
		$keys  = array();
		foreach ( explode( '&', $query[1] ?? '' ) as $pair ) {
			$keys[] = urldecode( explode( '=', $pair, 2 )[0] );
		}
		foreach ( array( 'source', 'medium', 'campaign' ) as $name ) {
			$key   = 'utm_' . $name;
			$value = trim( (string) ( $utm[ $name ] ?? $utm[ $key ] ?? '' ) );
			if ( '' !== $value && ! in_array( $key, $keys, true ) ) {
				$separator = str_contains( $parts[0], '?' ) ? ( str_ends_with( $parts[0], '?' ) || str_ends_with( $parts[0], '&' ) ? '' : '&' ) : '?';
				$parts[0] .= $separator . $key . '=' . rawurlencode( $value );
			}
		}
		return $parts[0] . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
	}

	/**
	 * Функция «ID записи → её адрес» (пустая строка, если запись не опубликована).
	 *
	 * @var callable
	 */
	private $post_url;

	/**
	 * Конструктор.
	 *
	 * @param Store    $store    Хранилище своих ссылок.
	 * @param ?ClickLog $click_log Журнал переходов.
	 * @param callable $post_url Функция: ID записи => адрес опубликованной записи или пустая строка.
	 */
	public function __construct( private Store $store, callable $post_url, private ?ClickLog $click_log = null ) {
		$this->post_url = $post_url;
	}

	/**
	 * Создаёт свою короткую ссылку.
	 *
	 * @param string $target Адрес, на который ведёт ссылка.
	 * @param string $slug   Желаемый код; пусто — выдать случайный.
	 * @param int    $code   Код перенаправления: 301, 302 или 307.
	 * @param array<string, string> $utm Метки перехода.
	 * @param string $campaign Кампания.
	 * @param ?string $expires_at Дата окончания.
	 * @return array{id: int, slug: string, target: string, code: int}|string Ссылка или текст ошибки.
	 */
	public function create( string $target, string $slug = '', int $code = 302, array $utm = array(), string $campaign = '', ?string $expires_at = null ) {
		$target = self::append_utm( trim( $target ), $utm );

		$campaign   = trim( $campaign );
		$expires_at = '' === $expires_at ? null : $expires_at;
		if ( null !== $expires_at && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) || ! checkdate( (int) substr( $expires_at, 5, 2 ), (int) substr( $expires_at, 8, 2 ), (int) substr( $expires_at, 0, 4 ) ) ) ) {
			return 'Неверная дата окончания.';
		}
		if ( preg_match_all( '/./us', $campaign ) > 60 ) {
			return 'Название кампании слишком длинное.'; }
		// Управляющие символы в адресе позволили бы подделать заголовки ответа.
		if ( strlen( $target ) > self::MAX_TARGET || 1 === preg_match( '/[\x00-\x20\x7f<>"]/', $target ) || 1 !== preg_match( '#^https?://[^/?\#]+\.[^/?\#]+#i', $target ) ) {
			return 'Адрес должен быть полным и начинаться с http:// или https://.';
		}

		if ( ! in_array( $code, self::CODES, true ) ) {
			return 'Код перенаправления должен быть 301, 302 или 307.';
		}

		$slug = Slug::normalize( $slug );

		if ( '' === $slug ) {
			$slug = $this->free_slug();
		} elseif ( ! Slug::valid( $slug ) ) {
			return 'Код ссылки: от 2 до 64 символов, латинские буквы, цифры, дефис и подчёркивание.';
		} elseif ( $this->taken( $slug ) ) {
			return 'Этот код уже занят.';
		}

		return array(
			'id'         => $this->store->insert( $slug, $target, $code, $campaign, $expires_at ),
			'slug'       => $slug,
			'campaign'   => $campaign,
			'expires_at' => $expires_at,
			'target'     => $target,
			'code'       => $code,
		);
	}

	/**
	 * Создаёт ссылки пачкой: одна строка — «адрес» или «адрес код». Пустые строки пропускаются.
	 *
	 * @param string $text     Строки.
	 * @param int    $code     Код перенаправления.
	 * @param string $campaign Название кампании.
	 * @return list<array{line: string, result: array<string, mixed>|string}>
	 */
	public function create_many( string $text, int $code = 302, string $campaign = '' ): array {
		$results = array();

		foreach ( (array) preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$parts     = (array) preg_split( '/[\s,;]+/', $line );
			$results[] = array(
				'line'   => $line,
				'result' => $this->create( (string) $parts[0], (string) ( $parts[1] ?? '' ), $code, array(), $campaign ),
			);
		}

		return $results;
	}

	/**
	 * Адрес и код перенаправления по коду ссылки; null — такой ссылки нет.
	 *
	 * @param string $slug Код из адреса.
	 * @return array{target: string, code: int, custom: bool}|null
	 */
	public function resolve( string $slug ): ?array {
		$slug = Slug::normalize( $slug );

		// Короткий адрес опубликованной записи важнее своей ссылки с тем же кодом:
		// иначе ссылку, созданную заранее, нельзя было бы отличить от адреса будущей статьи.
		$post_id = Slug::post_id( $slug );
		$url     = $post_id > 0 ? (string) ( $this->post_url )( $post_id ) : '';

		if ( '' !== $url ) {
			// Короткий адрес записи постоянный, как и сама запись: перенаправление 301.
			return array(
				'target' => $url,
				'code'   => 301,
				'custom' => false,
			);
		}

		$link = $this->store->find( $slug );

		if ( null !== $link ) {
			if ( ! empty( $link['expires_at'] ) && $link['expires_at'] < gmdate( 'Y-m-d' ) ) {
				return null; }
			return array(
				'target' => (string) $link['target'],
				'code'   => (int) $link['code'],
				'custom' => true,
			);
		}

		return null;
	}

	/**
	 * Засчитывает переход по своей ссылке.
	 *
	 * @param string $slug Код.
	 * @param string $referrer Адрес источника, сохраняется только хост.
	 * @param string $user_agent Строка браузера; значение по умолчанию сохраняет прежнее поведение прямых вызовов.
	 */
	public function hit( string $slug, string $user_agent = 'Mozilla/5.0', string $referrer = '' ): void {
		if ( \IndexPilot\Http\BotDetector::is_bot( $user_agent ) ) {
			return;
		}
		$slug = Slug::normalize( $slug );
		if ( null === $this->resolve( $slug ) ) {
			return; }
		if ( null !== $this->store->find( $slug ) ) {
			$this->store->hit( $slug ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- сервис работает без WordPress.
		$host = strtolower( (string) parse_url( $referrer, PHP_URL_HOST ) );
		$this->click_log?->add( $slug, gmdate( 'Y-m-d' ), substr( $host, 0, 100 ) );
	}

	/**
	 * Занят ли код своей ссылкой или опубликованной записью.
	 *
	 * @param string $slug Код в едином виде.
	 */
	private function taken( string $slug ): bool {
		if ( null !== $this->store->find( $slug ) ) {
			return true;
		}

		$post_id = Slug::post_id( $slug );

		return $post_id > 0 && '' !== (string) ( $this->post_url )( $post_id );
	}

	/**
	 * Свободный случайный код.
	 */
	private function free_slug(): string {
		do {
			$slug = Slug::random();
		} while ( $this->taken( $slug ) || Slug::post_id( $slug ) > 0 );

		return $slug;
	}

	/**
	 * Меняет цель с той же проверкой адреса, что и при создании.
	 *
	 * @param int $id ID ссылки.
	 * @param string $target Адрес.
	 * @return bool|string
	 */
	public function update_target( int $id, string $target ) {
		$target = trim( $target );
		if ( strlen( $target ) > self::MAX_TARGET || 1 === preg_match( '/[\x00-\x20\x7f<>"]/', $target ) || 1 !== preg_match( '#^https?://[^/?\#]+\.[^/?\#]+#i', $target ) ) {
			return 'Адрес должен быть полным и начинаться с http:// или https://.';
		}
		return null !== $this->store->find_by_id( $id ) && $this->store->update_target( $id, $target );
	}
}
