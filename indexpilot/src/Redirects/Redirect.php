<?php
/**
 * Один редирект.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Редирект из плагина Redirection: откуда, куда и сколько раз сработал.
 */
final class Redirect {

	/**
	 * Конструктор.
	 *
	 * @param int    $id     ID редиректа в плагине Redirection.
	 * @param string $source Исходный адрес: путь на этом сайте или полный адрес.
	 * @param string $target Цель: путь на этом сайте или полный адрес.
	 * @param int    $code   Код ответа.
	 * @param int    $hits   Число срабатываний.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $source,
		public readonly string $target,
		public readonly int $code = 301,
		public readonly int $hits = 0
	) {
	}
}
