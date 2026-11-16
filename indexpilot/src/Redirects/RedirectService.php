<?php
/**
 * Сведения о редиректах сайта для остальных модулей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Собирает свои редиректы, редиректы плагина Redirection (пока он включён) и результаты проверки целей
 * в один резолвер цепочек. Свои правила срабатывают первыми, поэтому при совпадении адресов учитывается своё.
 */
final class RedirectService {

	/**
	 * Резолвер цепочек.
	 *
	 * @var ChainResolver|null
	 */
	private ?ChainResolver $resolver = null;

	/**
	 * Свои правила в виде редиректов.
	 *
	 * @var list<Redirect>|null
	 */
	private ?array $own_redirects = null;

	/**
	 * Конструктор.
	 *
	 * @param RedirectionAdapter $adapter   Редиректы плагина Redirection.
	 * @param TargetChecker      $targets   Результаты проверки целей.
	 * @param string             $home_host Хост этого сайта.
	 * @param Store|null         $own       Свои правила.
	 */
	public function __construct(
		private RedirectionAdapter $adapter,
		private TargetChecker $targets,
		private string $home_host,
		private ?Store $own = null
	) {
	}

	/**
	 * Есть ли на сайте редиректы, за которыми можно следить.
	 */
	public function available(): bool {
		return $this->adapter->available() || array() !== $this->own();
	}

	/**
	 * Редиректы сайта.
	 *
	 * @return list<Redirect>
	 */
	public function all(): array {
		$own  = $this->own();
		$keys = array();

		foreach ( $own as $redirect ) {
			$keys[ Matcher::key( $redirect->source ) ] = true;
		}

		$foreign = array_filter( $this->adapter->all(), static fn( Redirect $redirect ): bool => ! isset( $keys[ Matcher::key( $redirect->source ) ] ) );

		return array_merge( $own, array_values( $foreign ) );
	}

	/**
	 * Свои включённые правила с точным адресом и целью.
	 *
	 * @return list<Redirect>
	 */
	public function own(): array {
		if ( null === $this->own_redirects ) {
			$this->own_redirects = array();

			foreach ( null === $this->own ? array() : $this->own->all() as $rule ) {
				if ( ! empty( $rule['enabled'] ) && empty( $rule['regex'] ) && '' !== (string) $rule['target'] ) {
					$this->own_redirects[] = new Redirect( (int) $rule['id'], (string) $rule['source'], (string) $rule['target'], (int) $rule['code'], (int) $rule['hits'] );
				}
			}
		}

		return $this->own_redirects;
	}

	/**
	 * Включённые правила плагина Redirection.
	 *
	 * @return list<Redirect>
	 */
	public function foreign(): array {
		return $this->adapter->all();
	}

	/**
	 * Ключи адресов (Matcher::key()), для которых есть включённое правило в Redirection.
	 *
	 * @return array<string, true>
	 */
	public function foreign_keys(): array {
		$keys = array();

		foreach ( $this->adapter->all() as $redirect ) {
			$keys[ Matcher::key( $redirect->source ) ] = true;
		}

		return $keys;
	}

	/**
	 * Редиректы плагина Redirection.
	 */
	public function adapter(): RedirectionAdapter {
		return $this->adapter;
	}

	/**
	 * Сколько включённых редиректов не учитывается.
	 */
	public function skipped(): int {
		return $this->adapter->skipped();
	}

	/**
	 * Результаты проверки целей.
	 */
	public function targets(): TargetChecker {
		return $this->targets;
	}

	/**
	 * Резолвер цепочек: редиректы сайта плюс переходы, найденные проверкой целей.
	 */
	public function resolver(): ChainResolver {
		if ( null === $this->resolver ) {
			$this->resolver = new ChainResolver( $this->all(), $this->home_host, array( $this->targets, 'next_location' ) );
		}

		return $this->resolver;
	}

	/**
	 * Адреса, которые нужно проверить: цели редиректов и адреса, на которые эти цели перенаправляют.
	 *
	 * @return list<string>
	 */
	public function addresses_to_check(): array {
		$resolver = $this->resolver();
		$urls     = array();

		foreach ( $this->all() as $redirect ) {
			$target = $resolver->absolute( $redirect->target );

			if ( 1 === preg_match( '#^https?://#i', $target ) ) {
				$urls[] = $target;
			}
		}

		foreach ( $this->targets->results() as $result ) {
			if ( '' !== $result['location'] ) {
				$urls[] = $result['location'];
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Адреса, ожидающие проверки.
	 *
	 * @return list<string>
	 */
	public function pending(): array {
		return $this->targets->pending( $this->addresses_to_check() );
	}

	/**
	 * Проверяет очередную пачку адресов. После проверки цепочки пересчитываются.
	 *
	 * @param int $limit Размер пачки.
	 * @return array{checked: int, remaining: int}
	 */
	public function check_batch( int $limit = 5 ): array {
		$checked        = $this->targets->check( $this->pending(), $limit );
		$this->resolver = null;

		return array(
			'checked'   => $checked,
			'remaining' => count( $this->pending() ),
		);
	}
}
