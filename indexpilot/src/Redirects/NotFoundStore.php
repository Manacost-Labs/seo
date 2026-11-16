<?php
/**
 * Хранилище журнала 404.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

interface NotFoundStore {

	/**
	 * Засчитывает обращение к адресу.
	 *
	 * @param string $url      Адрес (путь без параметров).
	 * @param string $referrer Откуда пришли; пусто — неизвестно.
	 * @return int Номер новой строки или 0, если адрес уже был в журнале.
	 */
	public function add( string $url, string $referrer ): int;

	/**
	 * Оставляет в журнале только самые свежие строки.
	 *
	 * @param int $keep Сколько строк оставить.
	 */
	public function trim( int $keep ): void;
}
