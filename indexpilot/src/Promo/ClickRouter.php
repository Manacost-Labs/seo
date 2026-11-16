<?php
/**
 * Адреса перехода на площадки и учёт переходов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

use IndexPilot\Http\BotDetector;

/**
 * Обслуживает адреса вида /to/КОД и /to/КОД/ЗАПИСЬ-ПРАВИЛО-ВАРИАНТ: засчитывает переход и перенаправляет
 * на площадку, добавив к её адресу метки — по ним площадка видит, с какой статьи пришёл человек.
 * Сами адреса перехода в поиск попадать не должны — ответ помечается как неиндексируемый.
 */
final class ClickRouter {

	public const PREFIX = 'to';

	/**
	 * Конструктор.
	 *
	 * @param ClickStore   $store        Счётчик переходов.
	 * @param Destinations $destinations Площадки.
	 * @param Rules        $rules        Правила блоков перехода.
	 * @param string       $prefix       Префикс адреса перехода.
	 */
	public function __construct( private ClickStore $store, private Destinations $destinations, private Rules $rules, private string $prefix ) {
	}

	/**
	 * Разбирает адрес запроса; null — это не адрес перехода.
	 *
	 * @param string $request_uri Адрес запроса с путём и параметрами.
	 * @param string $home_path   Путь, по которому установлен сайт: «/» или «/blog/».
	 * @param string $prefix      Префикс адреса перехода.
	 * @return array{code: string, post_id: int, rule_id: int, variant: int}|null
	 */
	public static function parse( string $request_uri, string $home_path, string $prefix ): ?array {
		$path = (string) strtok( $request_uri, '?' );
		$base = '/' . trim( trim( $home_path, '/' ) . '/' . trim( $prefix, '/' ), '/' ) . '/';

		if ( '' === trim( $prefix, '/' ) || ! str_starts_with( $path, $base ) ) {
			return null;
		}

		$rest = strtolower( trim( substr( $path, strlen( $base ) ), '/' ) );

		if ( 1 !== preg_match( '#^([a-z0-9][a-z0-9-]{0,63})(?:/(\d{1,12})-(\d{1,9})-(\d{1,3}))?$#', $rest, $match ) ) {
			return null;
		}

		return array(
			'code'    => $match[1],
			'post_id' => (int) ( $match[2] ?? 0 ),
			'rule_id' => (int) ( $match[3] ?? 0 ),
			'variant' => (int) ( $match[4] ?? 0 ),
		);
	}

	/**
	 * Путь адреса перехода от корня сайта.
	 *
	 * @param string $prefix  Префикс адреса перехода.
	 * @param string $code    Код площадки.
	 * @param int    $post_id Запись, в которой стоит ссылка; 0 — ссылка без записи.
	 * @param int    $rule_id Правило блока; 0 — блок подписки.
	 * @param int    $variant Номер варианта текста.
	 */
	public static function path( string $prefix, string $code, int $post_id = 0, int $rule_id = 0, int $variant = 0 ): string {
		$path = '/' . trim( $prefix, '/' ) . '/' . $code;

		return $post_id > 0 ? $path . '/' . $post_id . '-' . max( 0, $rule_id ) . '-' . max( 0, $variant ) : $path;
	}

	/**
	 * Добавляет метки к адресу. Параметры и якорь адреса сохраняются; метка, которая в адресе уже есть, не меняется
	 * и не повторяется; пустые метки пропускаются.
	 *
	 * @param string                $url   Адрес площадки.
	 * @param array<string, string> $marks Метки: имя параметра => значение.
	 */
	public static function add_utm( string $url, array $marks ): string {
		$anchor_at = strpos( $url, '#' );
		$anchor    = false === $anchor_at ? '' : substr( $url, $anchor_at );
		$address   = false === $anchor_at ? $url : substr( $url, 0, $anchor_at );
		$query_at  = strpos( $address, '?' );
		$present   = array();

		if ( false !== $query_at ) {
			parse_str( substr( $address, $query_at + 1 ), $present );
		}

		$pairs = array();

		foreach ( $marks as $name => $value ) {
			if ( '' !== (string) $value && ! array_key_exists( $name, $present ) ) {
				$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
			}
		}

		if ( array() === $pairs ) {
			return $url;
		}

		if ( false === $query_at ) {
			$glue = '?';
		} else {
			$glue = str_ends_with( $address, '?' ) || str_ends_with( $address, '&' ) ? '' : '&';
		}

		return $address . $glue . implode( '&', $pairs ) . $anchor;
	}

	/**
	 * Адрес площадки с метками перехода.
	 *
	 * @param array<string, mixed> $destination Площадка из справочника.
	 * @param string               $site_host   Хост этого сайта: источник по умолчанию.
	 * @param int                  $post_id     Запись, с которой пришёл читатель; 0 — неизвестна.
	 * @param bool                 $follow      Переход из блока подписки, а не из блока в тексте.
	 */
	public static function target( array $destination, string $site_host, int $post_id, bool $follow ): string {
		$own = static fn( string $key ): string => trim( (string) ( $destination['utm'][ $key ] ?? '' ) );

		return self::add_utm(
			(string) $destination['url'],
			array(
				'utm_source'   => '' !== $own( 'source' ) ? $own( 'source' ) : $site_host,
				'utm_medium'   => '' !== $own( 'medium' ) ? $own( 'medium' ) : ( $follow ? 'follow' : 'article' ),
				'utm_campaign' => '' !== $own( 'campaign' ) ? $own( 'campaign' ) : (string) $destination['code'],
				'utm_content'  => $post_id > 0 ? 'post-' . $post_id : '',
			)
		);
	}

	/**
	 * Убирает из перехода то, чего на сайте нет. Адрес может собрать кто угодно, а счётчик не должен
	 * расти строками о несуществующих записях, правилах и вариантах.
	 *
	 * @param array{code: string, post_id: int, rule_id: int, variant: int} $click       Разобранный адрес.
	 * @param list<array<string, mixed>>                                    $rules       Правила блоков.
	 * @param bool                                                          $post_exists Есть ли опубликованная запись с таким ID.
	 * @return array{code: string, post_id: int, rule_id: int, variant: int}
	 */
	public static function checked( array $click, array $rules, bool $post_exists ): array {
		if ( ! $post_exists ) {
			$click['post_id'] = 0;
		}

		foreach ( $rules as $rule ) {
			if ( $click['post_id'] > 0 && $click['rule_id'] > 0 && $click['rule_id'] === (int) $rule['id'] && $click['code'] === $rule['destination'] ) {
				if ( $click['variant'] >= count( (array) ( $rule['variants'] ?? array() ) ) ) {
					$click['variant'] = 0;
				}

				return $click;
			}
		}

		$click['rule_id'] = 0;
		$click['variant'] = 0;

		return $click;
	}

	/**
	 * Засчитывает переход человека и возвращает адрес, на который его перенаправить. Роботы не считаются.
	 *
	 * @param ClickStore                                                    $store       Счётчик переходов.
	 * @param array{code: string, post_id: int, rule_id: int, variant: int} $click       Проверенный переход.
	 * @param array<string, mixed>                                          $destination Площадка из справочника.
	 * @param string                                                        $user_agent  Строка User-Agent запроса; не сохраняется.
	 * @param string                                                        $day         Сегодня, ГГГГ-ММ-ДД.
	 * @param string                                                        $site_host   Хост этого сайта.
	 */
	public static function visit( ClickStore $store, array $click, array $destination, string $user_agent, string $day, string $site_host ): string {
		if ( ! BotDetector::is_bot( $user_agent ) ) {
			$store->hit( $click['code'], $click['post_id'], $click['rule_id'], $click['variant'], $day );
		}

		return self::target( $destination, $site_host, $click['post_id'], $click['post_id'] > 0 && 0 === $click['rule_id'] );
	}

	/**
	 * Полный адрес перехода.
	 *
	 * @param string $code    Код площадки.
	 * @param int    $post_id Запись, в которой стоит ссылка.
	 * @param int    $rule_id Правило блока.
	 * @param int    $variant Номер варианта текста.
	 */
	public function url( string $code, int $post_id = 0, int $rule_id = 0, int $variant = 0 ): string {
		return home_url( self::path( $this->prefix, $code, $post_id, $rule_id, $variant ) );
	}

	/**
	 * Адрес площадки для запрошенного адреса перехода; переход при этом засчитывается.
	 *
	 * @param string $request_uri Адрес запроса.
	 * @param string $user_agent  Строка User-Agent запроса.
	 * @return string|null Null — это не адрес перехода или такой площадки нет.
	 */
	public function resolve( string $request_uri, string $user_agent ): ?string {
		$click = self::parse( $request_uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $this->prefix );

		if ( null === $click ) {
			return null;
		}

		$destination = $this->destinations->find( $click['code'] );

		if ( null === $destination ) {
			return null;
		}

		$post  = $click['post_id'] > 0 ? get_post( $click['post_id'] ) : null;
		$click = self::checked( $click, $this->rules->all(), $post instanceof \WP_Post && 'publish' === $post->post_status );

		return self::visit( $this->store, $click, $destination, $user_agent, wp_date( 'Y-m-d' ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Перенаправляет, если запрошен адрес перехода. Вызывается на хуке parse_request.
	 * Неизвестный код остаётся обычным запросом: WordPress покажет свою страницу 404.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- из адреса берутся только код и числа, проверенные шаблоном.
		$uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$target     = $this->resolve( $uri, $user_agent );

		if ( null === $target ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow' );

		// Перенаправление не должно запоминаться браузером: иначе повторные переходы не будут засчитаны.
		nocache_headers();

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- цель задаёт администратор сайта в списке площадок, она ведёт на другой домен (Boosty, Telegram); адрес проверен при сохранении.
		wp_redirect( $target, 302, 'IndexPilot' );
		exit;
	}
}
