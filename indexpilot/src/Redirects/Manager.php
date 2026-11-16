<?php
/**
 * Создание и правка своих редиректов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Проверяет и сохраняет правила. Цепочки не сохраняются: цель сразу заменяется конечным адресом,
 * а правила, которые вели на новый исходный адрес, переводятся на его цель. Петли запрещены.
 */
final class Manager {

	public const CODES = array( 301, 302, 307, 410, 451 );

	public const ERROR_SOURCE    = 'source';
	public const ERROR_PATTERN   = 'pattern';
	public const ERROR_TARGET    = 'target';
	public const ERROR_CODE      = 'code';
	public const ERROR_DUPLICATE = 'duplicate';
	public const ERROR_LOOP      = 'loop';

	private const MAX_URL = 2000;

	/**
	 * Хост сайта без www.
	 *
	 * @var string
	 */
	private string $home_host;

	/**
	 * @param Store          $store     Хранилище правил.
	 * @param string         $home_host Хост сайта.
	 * @param list<Redirect> $foreign   Действующие правила плагина Redirection: участвуют в схлопывании цепочек.
	 */
	public function __construct( private Store $store, string $home_host, private array $foreign = array() ) {
		$home_host       = strtolower( trim( $home_host ) );
		$this->home_host = str_starts_with( $home_host, 'www.' ) ? substr( $home_host, 4 ) : $home_host;
	}

	/**
	 * Проверяет и сохраняет правило.
	 *
	 * @param array<string, mixed> $input Поля: source, target, code, regex, enabled, note.
	 * @param int                  $id    Номер правила при правке; 0 — новое.
	 * @return array<string, mixed>|string Сохранённое правило или код ошибки (ERROR_*).
	 */
	public function save( array $input, int $id = 0 ) {
		$regex  = ! empty( $input['regex'] );
		$code   = (int) ( $input['code'] ?? 301 );
		$source = trim( (string) ( $input['source'] ?? '' ) );
		$source = $regex ? $source : $this->local_path( $source );

		if ( '' === $source || strlen( $source ) > self::MAX_URL || ( ! $regex && ! str_starts_with( $source, '/' ) ) ) {
			return self::ERROR_SOURCE;
		}

		if ( $regex && ! Matcher::valid_pattern( $source ) ) {
			return self::ERROR_PATTERN;
		}

		if ( ! in_array( $code, self::CODES, true ) ) {
			return self::ERROR_CODE;
		}

		$target = Matcher::is_gone( $code ) ? '' : $this->local_path( trim( (string) ( $input['target'] ?? '' ) ) );

		if ( ! Matcher::is_gone( $code ) && ! self::valid_target( $target ) ) {
			return self::ERROR_TARGET;
		}

		$key = $regex ? '' : Matcher::key( $source );

		if ( ! $regex ) {
			$same = $this->store->find_by_source( $key );

			if ( null !== $same && (int) $same['id'] !== $id ) {
				return self::ERROR_DUPLICATE;
			}
		}

		if ( ! $regex && '' !== $target ) {
			$final = $this->final_target( $target, $id );

			if ( null === $final || ( $this->is_local( $final ) && Matcher::key( $final ) === $key ) ) {
				return self::ERROR_LOOP;
			}

			$target = $final;
		}

		$fields = array(
			'source'  => $source,
			'regex'   => $regex,
			'target'  => $target,
			'code'    => $code,
			'enabled' => ! array_key_exists( 'enabled', $input ) || ! empty( $input['enabled'] ),
			'note'    => mb_substr( trim( (string) ( $input['note'] ?? '' ) ), 0, 100 ),
		);

		if ( $id > 0 && null !== $this->store->find( $id ) ) {
			$this->store->update( $id, $fields );
		} else {
			$id = $this->store->insert( $fields );
		}

		if ( ! $regex && '' !== $target ) {
			$this->retarget( $key, $target, $id );
		}

		$this->store->rebuild_index();

		return (array) $this->store->find( $id );
	}

	/**
	 * Редирект при смене адреса записи или термина: со старого адреса на новый.
	 * Правило с нового адреса удаляется — теперь там живёт содержимое.
	 *
	 * @param string $old_url Прежний адрес.
	 * @param string $new_url Новый адрес.
	 * @return array<string, mixed>|string|null Правило, код ошибки или null, если адрес не изменился.
	 */
	public function auto( string $old_url, string $new_url ) {
		if ( ! $this->is_local( $old_url ) || Matcher::key( $old_url ) === Matcher::key( $new_url ) ) {
			return null;
		}

		$blocking = $this->store->find_by_source( Matcher::key( $new_url ) );

		if ( null !== $blocking ) {
			$this->store->delete( (int) $blocking['id'] );
		}

		$existing = $this->store->find_by_source( Matcher::key( $old_url ) );

		return $this->save(
			array(
				'source'  => $old_url,
				'target'  => $new_url,
				'code'    => 301,
				'enabled' => true,
				'note'    => null !== $existing ? (string) $existing['note'] : 'авто: адрес изменён',
			),
			null !== $existing ? (int) $existing['id'] : 0
		);
	}

	/**
	 * Удаляет правило.
	 *
	 * @param int $id Номер правила.
	 */
	public function delete( int $id ): void {
		$this->store->delete( $id );
		$this->store->rebuild_index();
	}

	/**
	 * Включает или выключает правило.
	 *
	 * @param int  $id      Номер правила.
	 * @param bool $enabled Включить.
	 */
	public function set_enabled( int $id, bool $enabled ): void {
		$this->store->update( $id, array( 'enabled' => $enabled ) );
		$this->store->rebuild_index();
	}

	/**
	 * Схлопывает цепочки во всех правилах с точным адресом; правила, которые ходят по кругу, выключаются.
	 *
	 * @return array{collapsed: int, loops: int}
	 */
	public function collapse_all(): array {
		$rules    = array_filter( $this->store->all(), static fn( array $rule ): bool => ! empty( $rule['enabled'] ) && empty( $rule['regex'] ) && '' !== (string) $rule['target'] );
		$resolver = new ChainResolver( array_merge( $this->foreign, $this->redirects( $rules ) ), $this->home_host );
		$result   = array(
			'collapsed' => 0,
			'loops'     => 0,
		);
		$changes  = array();

		foreach ( $rules as $rule ) {
			$chain = $resolver->resolve( (string) $rule['source'] );

			if ( $chain['loop'] ) {
				$changes[ (int) $rule['id'] ] = array(
					'enabled' => false,
					'note'    => mb_substr( trim( 'петля ' . (string) $rule['note'] ), 0, 100 ),
				);
				++$result['loops'];
			} elseif ( count( $chain['hops'] ) > 1 ) {
				$changes[ (int) $rule['id'] ] = array( 'target' => $this->local_path( $chain['final'] ) );
				++$result['collapsed'];
			}
		}

		foreach ( $changes as $id => $fields ) {
			$this->store->update( $id, $fields );
		}

		$this->store->rebuild_index();

		return $result;
	}

	/**
	 * Переводит правила, которые вели на исходный адрес нового правила, сразу на его цель.
	 *
	 * @param string $key    Ключ исходного адреса нового правила.
	 * @param string $target Цель нового правила.
	 * @param int    $id     Номер нового правила.
	 */
	private function retarget( string $key, string $target, int $id ): void {
		foreach ( $this->store->all() as $rule ) {
			if ( (int) $rule['id'] === $id || ! empty( $rule['regex'] ) || '' === (string) $rule['target'] ) {
				continue;
			}

			if ( $this->is_local( (string) $rule['target'] ) && Matcher::key( (string) $rule['target'] ) === $key ) {
				$this->store->update( (int) $rule['id'], array( 'target' => $target ) );
			}
		}
	}

	/**
	 * Конечный адрес цели по действующим правилам; null — петля.
	 *
	 * @param string $target Цель.
	 * @param int    $except Номер правила, которое сейчас правится.
	 */
	private function final_target( string $target, int $except ): ?string {
		$rules = array_filter(
			$this->store->all(),
			static fn( array $rule ): bool => (int) $rule['id'] !== $except && ! empty( $rule['enabled'] ) && empty( $rule['regex'] ) && '' !== (string) $rule['target']
		);

		$resolver = new ChainResolver( array_merge( $this->foreign, $this->redirects( $rules ) ), $this->home_host );
		$chain    = $resolver->resolve( $target );

		if ( $chain['loop'] ) {
			return null;
		}

		return array() === $chain['hops'] ? $target : $this->local_path( $chain['final'] );
	}

	/**
	 * Правила в виде объектов Redirect для ChainResolver.
	 *
	 * @param array<array<string, mixed>> $rules Правила.
	 * @return list<Redirect>
	 */
	private function redirects( array $rules ): array {
		$list = array();

		foreach ( $rules as $rule ) {
			$list[] = new Redirect( (int) $rule['id'], (string) $rule['source'], (string) $rule['target'], (int) $rule['code'], (int) ( $rule['hits'] ?? 0 ) );
		}

		return $list;
	}

	/**
	 * Адрес своего сайта — в виде пути с параметрами; чужой адрес остаётся как есть.
	 *
	 * @param string $url Адрес.
	 */
	private function local_path( string $url ): string {
		if ( 1 !== preg_match( '#^https?://#i', $url ) || ! $this->is_local( $url ) ) {
			return $url;
		}

		return 1 === preg_match( '#^https?://[^/?\#]+(.*)$#i', $url, $match ) && '' !== $match[1] ? ( str_starts_with( $match[1], '/' ) ? $match[1] : '/' . $match[1] ) : '/';
	}

	/**
	 * Адрес ведёт на свой сайт (путь или полный адрес с хостом сайта).
	 *
	 * @param string $url Адрес.
	 */
	private function is_local( string $url ): bool {
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}

		if ( 1 !== preg_match( '#^https?://([^/?\#:]+)#i', $url, $match ) ) {
			return false;
		}

		$host = strtolower( $match[1] );
		$host = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;

		return $host === $this->home_host;
	}

	/**
	 * Цель — путь на сайте или полный адрес http(s) без управляющих символов.
	 *
	 * @param string $target Цель.
	 */
	private static function valid_target( string $target ): bool {
		if ( '' === $target || strlen( $target ) > self::MAX_URL || 1 === preg_match( '/[\x00-\x20\x7f<>"]/', $target ) ) {
			return false;
		}

		return ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) || 1 === preg_match( '#^https?://[^/?\#]+#i', $target );
	}
}
