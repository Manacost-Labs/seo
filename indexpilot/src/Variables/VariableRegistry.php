<?php
/**
 * Реестр переменных шаблонов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Variables;

use IndexPilot\Resolver\Context;

/**
 * Хранит переменные вида %title% и переменные с аргументом вида %cf_КЛЮЧ%.
 */
final class VariableRegistry {

	/**
	 * Переменные с точным именем.
	 *
	 * @var array<string, array{callback: callable, cacheable: bool}>
	 */
	private array $exact = array();

	/**
	 * Переменные с префиксом и аргументом.
	 *
	 * @var array<string, array{callback: callable, cacheable: bool}>
	 */
	private array $prefixed = array();

	/**
	 * Уже посчитанные значения.
	 *
	 * @var array<string, string>
	 */
	private array $memo = array();

	/**
	 * Регистрирует переменную %name%.
	 *
	 * @param string   $name      Имя без знаков процента.
	 * @param callable $callback  Функция fn( Context $context ): string.
	 * @param bool     $cacheable Можно ли запомнить значение для одного контекста.
	 */
	public function register( string $name, callable $callback, bool $cacheable = true ): void {
		$this->exact[ $name ] = array(
			'callback'  => $callback,
			'cacheable' => $cacheable,
		);
	}

	/**
	 * Регистрирует семейство переменных %prefixАРГУМЕНТ%, например %cf_dust_cost%.
	 *
	 * @param string   $prefix    Префикс, например «cf_».
	 * @param callable $callback  Функция fn( string $argument, Context $context ): string.
	 * @param bool     $cacheable Можно ли запомнить значение для одного контекста.
	 */
	public function register_prefix( string $prefix, callable $callback, bool $cacheable = true ): void {
		$this->prefixed[ $prefix ] = array(
			'callback'  => $callback,
			'cacheable' => $cacheable,
		);
	}

	/**
	 * Известна ли переменная.
	 *
	 * @param string $token Имя без знаков процента.
	 */
	public function has( string $token ): bool {
		return isset( $this->exact[ $token ] ) || null !== $this->match_prefix( $token );
	}

	/**
	 * Значение переменной или null, если она не зарегистрирована.
	 *
	 * @param string  $token   Имя без знаков процента.
	 * @param Context $context Контекст страницы.
	 */
	public function resolve( string $token, Context $context ): ?string {
		$memo_key = $context->cache_key() . '|' . $token;
		if ( isset( $this->memo[ $memo_key ] ) ) {
			return $this->memo[ $memo_key ];
		}

		if ( isset( $this->exact[ $token ] ) ) {
			$entry = $this->exact[ $token ];
			$value = (string) ( $entry['callback'] )( $context );
		} else {
			$prefix = $this->match_prefix( $token );
			if ( null === $prefix ) {
				return null;
			}
			$entry = $this->prefixed[ $prefix ];
			$value = (string) ( $entry['callback'] )( substr( $token, strlen( $prefix ) ), $context );
		}

		$value = trim( $value );
		if ( $entry['cacheable'] ) {
			$this->memo[ $memo_key ] = $value;
		}

		return $value;
	}

	/**
	 * Имена переменных для подсказок в интерфейсе.
	 *
	 * @return array{exact: list<string>, prefixed: list<string>}
	 */
	public function names(): array {
		return array(
			'exact'    => array_keys( $this->exact ),
			'prefixed' => array_keys( $this->prefixed ),
		);
	}

	/**
	 * Сбрасывает запомненные значения, например после сохранения записи.
	 */
	public function flush(): void {
		$this->memo = array();
	}

	/**
	 * Находит самый длинный подходящий префикс.
	 *
	 * @param string $token Имя без знаков процента.
	 */
	private function match_prefix( string $token ): ?string {
		$found = null;
		foreach ( array_keys( $this->prefixed ) as $prefix ) {
			if ( strlen( $token ) > strlen( $prefix ) && str_starts_with( $token, $prefix ) ) {
				if ( null === $found || strlen( $prefix ) > strlen( $found ) ) {
					$found = $prefix;
				}
			}
		}

		return $found;
	}
}
