<?php
/**
 * Запись ссылок текста в граф ссылок.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Links;

/**
 * Разбирает текст записи и сохраняет её ссылки: на какие записи сайта она ссылается и на какие внешние адреса.
 * По этим данным строятся отчёты: записи без входящих ссылок, ссылки на несуществующие и перенаправленные адреса.
 */
final class Indexer {

	/**
	 * Функция «адрес страницы сайта → ID записи» (0, если это не запись).
	 *
	 * @var callable
	 */
	private $resolve_post;

	/**
	 * Конструктор.
	 *
	 * @param LinkStore $store        Хранилище ссылок.
	 * @param string    $home_host    Хост сайта.
	 * @param callable  $resolve_post Функция: адрес => ID записи или 0.
	 */
	public function __construct( private LinkStore $store, private string $home_host, callable $resolve_post ) {
		$this->resolve_post = $resolve_post;
	}

	/**
	 * Сохраняет ссылки записи, заменяя прежние. Возвращает число сохранённых ссылок.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $html    HTML текста записи.
	 */
	public function index( int $post_id, string $html ): int {
		$rows = array();

		foreach ( Extractor::extract( $html, $this->home_host ) as $link ) {
			$target = 'internal' === $link['type'] ? (int) ( $this->resolve_post )( $link['url'] ) : 0;

			// Ссылка записи на саму себя ничего не говорит о её связях с сайтом.
			if ( $target === $post_id ) {
				continue;
			}

			$rows[] = array(
				'target_post_id' => $target,
				'url'            => $link['url'],
				'type'           => $link['type'],
				'nofollow'       => $link['nofollow'],
			);
		}

		$this->store->replace( $post_id, $rows );

		return count( $rows );
	}

	/**
	 * Удаляет ссылки записи, например когда её сняли с публикации.
	 *
	 * @param int $post_id ID записи.
	 */
	public function remove( int $post_id ): void {
		$this->store->remove( $post_id );
	}
}
