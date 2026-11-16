<?php
/**
 * Сведения о редиректах сайта для остальных модулей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Собирает редиректы плагина Redirection и результаты проверки целей в один резолвер цепочек.
 */
final class RedirectService {

	/**
	 * Резолвер цепочек.
	 *
	 * @var ChainResolver|null
	 */
	private ?ChainResolver $resolver = null;

	/**
	 * Конструктор.
	 *
	 * @param RedirectionAdapter $adapter   Редиректы плагина Redirection.
	 * @param TargetChecker      $targets   Результаты проверки целей.
	 * @param string             $home_host Хост этого сайта.
	 */
	public function __construct(
		private RedirectionAdapter $adapter,
		private TargetChecker $targets,
		private string $home_host
	) {
	}

	/**
	 * Есть ли на сайте редиректы, за которыми можно следить.
	 */
	public function available(): bool {
		return $this->adapter->available();
	}

	/**
	 * Редиректы сайта.
	 *
	 * @return list<Redirect>
	 */
	public function all(): array {
		return $this->adapter->all();
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
