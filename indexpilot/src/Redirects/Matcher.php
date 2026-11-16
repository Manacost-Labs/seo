<?php
/**
 * Поиск правила редиректа для адреса запроса.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Находит правило по адресу запроса. Правила с точным адресом ищутся по индексу «хеш адреса → номер правила»,
 * шаблоны (регулярные выражения) проверяются по короткому отдельному списку. Само правило читается
 * только после совпадения, поэтому обычная страница сайта не стоит ни одного запроса к базе.
 */
final class Matcher {

	/**
	 * Коды ответа без перенаправления: «удалена навсегда» и «недоступна по закону».
	 */
	public const GONE = array( 410, 451 );

	/**
	 * Рекламные и счётные метки: на поиск правила не влияют.
	 */
	private const AD_PARAMS = array( 'gclid', 'yclid', 'ysclid', 'fbclid', 'msclkid', 'dclid', 'mc_cid', 'mc_eid', '_openstat', 'from', 'ref' );

	/**
	 * Загрузчик правила по номеру.
	 *
	 * @var callable(int): (array<string, mixed>|null)
	 */
	private $load;

	/**
	 * @param array{exact?: array<string, int>, patterns?: array<int, string>} $index Индекс правил.
	 * @param callable(int): (array<string, mixed>|null)                       $load  Загрузчик правила по номеру.
	 */
	public function __construct( private array $index, callable $load ) {
		$this->load = $load;
	}

	/**
	 * Приводит адрес к ключу поиска: путь без домена, в нижнем регистре, без слэша в конце, без рекламных меток.
	 *
	 * @param string $url Адрес или путь.
	 */
	public static function key( string $url ): string {
		$url = trim( $url );

		if ( 1 === preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//[^/?\#]*(.*)$#i', $url, $match ) ) {
			$url = $match[1];
		}

		$url  = explode( '#', $url, 2 )[0];
		$part = explode( '?', $url, 2 );
		$path = '/' . ltrim( rawurldecode( $part[0] ), '/' );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		$path = mb_strtolower( rtrim( $path, '/' ) );
		$path = '' === $path ? '/' : $path;

		$query = self::clean_query( $part[1] ?? '' );

		return '' === $query ? $path : $path . '?' . $query;
	}

	/**
	 * Хеш ключа адреса для индекса.
	 *
	 * @param string $key Ключ адреса (см. key()).
	 */
	public static function hash( string $key ): string {
		return md5( $key );
	}

	/**
	 * Собирает индекс из правил: включённые точные адреса — по хешу, включённые шаблоны — списком.
	 *
	 * @param list<array<string, mixed>> $rules Правила.
	 * @return array{exact: array<string, int>, patterns: array<int, string>}
	 */
	public static function index( array $rules ): array {
		$index = array(
			'exact'    => array(),
			'patterns' => array(),
		);

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			if ( ! empty( $rule['regex'] ) ) {
				$index['patterns'][ (int) $rule['id'] ] = (string) $rule['source'];
			} else {
				$index['exact'][ self::hash( self::key( (string) $rule['source'] ) ) ] = (int) $rule['id'];
			}
		}

		return $index;
	}

	/**
	 * Пуст ли индекс.
	 *
	 * @param mixed $index Индекс.
	 */
	public static function is_empty( $index ): bool {
		return ! is_array( $index ) || ( empty( $index['exact'] ) && empty( $index['patterns'] ) );
	}

	/**
	 * Ответ без перенаправления (410, 451).
	 *
	 * @param int $code Код ответа.
	 */
	public static function is_gone( int $code ): bool {
		return in_array( $code, self::GONE, true );
	}

	/**
	 * Правильный ли шаблон.
	 *
	 * @param string $pattern Регулярное выражение без разделителей.
	 */
	public static function valid_pattern( string $pattern ): bool {
		if ( '' === trim( $pattern ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ошибку разбора шаблона нужно только распознать, не показать.
		return false !== @preg_match( self::regex( $pattern ), '' );
	}

	/**
	 * Находит правило для адреса запроса.
	 *
	 * @param string $request_uri Адрес запроса (путь и параметры).
	 * @return array{id: int, code: int, target: string}|null
	 */
	public function match( string $request_uri ): ?array {
		$key   = self::key( $request_uri );
		$query = (string) ( explode( '?', explode( '#', $request_uri, 2 )[0], 2 )[1] ?? '' );
		$keys  = array( $key );

		// Адрес с параметрами находит и правило для того же пути без них. Для главной так не делаем:
		// там параметры — это поиск и служебные адреса WordPress.
		$path = explode( '?', $key, 2 )[0];
		if ( $path !== $key && '/' !== $path ) {
			$keys[] = $path;
		}

		foreach ( $keys as $candidate ) {
			$id = $this->index['exact'][ self::hash( $candidate ) ] ?? null;

			if ( null === $id ) {
				continue;
			}

			$rule = ( $this->load )( (int) $id );

			if ( is_array( $rule ) && ! empty( $rule['enabled'] ) && empty( $rule['regex'] ) && self::key( (string) $rule['source'] ) === $candidate ) {
				return $this->result( $rule, self::with_query( (string) $rule['target'], $query ) );
			}
		}

		$subject = rawurldecode( explode( '#', $request_uri, 2 )[0] );

		foreach ( (array) ( $this->index['patterns'] ?? array() ) as $id => $pattern ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- сломанный шаблон просто не срабатывает.
			if ( 1 !== @preg_match( self::regex( (string) $pattern ), $subject ) ) {
				continue;
			}

			$rule = ( $this->load )( (int) $id );

			if ( is_array( $rule ) && ! empty( $rule['enabled'] ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- шаблон уже проверен выше.
				$target = (string) @preg_replace( self::regex( (string) $pattern ), (string) $rule['target'], $subject );

				// Подстановка заменяет только совпавшую часть: если шаблон не охватывает весь адрес, берём цель как есть.
				$target = 1 === preg_match( '#^(https?://|/)#i', $target ) ? $target : (string) $rule['target'];

				return $this->result( $rule, $target );
			}
		}

		return null;
	}

	/**
	 * Итог совпадения.
	 *
	 * @param array<string, mixed> $rule   Правило.
	 * @param string               $target Цель после подстановок.
	 * @return array{id: int, code: int, target: string}
	 */
	private function result( array $rule, string $target ): array {
		$code = (int) $rule['code'];

		return array(
			'id'     => (int) $rule['id'],
			'code'   => $code,
			'target' => self::is_gone( $code ) ? '' : $target,
		);
	}

	/**
	 * Переносит параметры запроса на цель, если у цели своих параметров нет.
	 *
	 * @param string $target Цель.
	 * @param string $query  Параметры запроса.
	 */
	private static function with_query( string $target, string $query ): string {
		if ( '' === $target || '' === $query || str_contains( $target, '?' ) ) {
			return $target;
		}

		$parts = explode( '#', $target, 2 );

		return $parts[0] . '?' . $query . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
	}

	/**
	 * Параметры без рекламных меток, по алфавиту.
	 *
	 * @param string $query Строка параметров.
	 */
	private static function clean_query( string $query ): string {
		$pairs = array();

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$name = strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) );

			if ( str_starts_with( $name, 'utm_' ) || in_array( $name, self::AD_PARAMS, true ) ) {
				continue;
			}

			$pairs[] = $pair;
		}

		sort( $pairs );

		return implode( '&', $pairs );
	}

	/**
	 * Шаблон с разделителями: без учёта регистра, в UTF-8.
	 *
	 * @param string $pattern Регулярное выражение без разделителей.
	 */
	private static function regex( string $pattern ): string {
		return '@' . str_replace( '@', '\\@', $pattern ) . '@iu';
	}
}
