<?php
/**
 * Опубликованные записи, с адреса которых стоит редирект.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Redirects;

/**
 * Такая запись числится на сайте, но открыть её нельзя: посетитель сразу уходит по редиректу.
 * Её не должно быть в sitemap и в подсказках ссылок, а в списке записей она помечается.
 *
 * Соответствие «запись → редирект» ищется по адресам и запоминается, пока набор редиректов не изменится.
 */
final class RedirectedPosts {

	public const OPTION = 'indexpilot_redirected_posts';

	/**
	 * Соответствие: ID записи => исходный адрес редиректа.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $map = null;

	/**
	 * Конструктор.
	 *
	 * @param RedirectService $service Редиректы сайта.
	 */
	public function __construct( private RedirectService $service ) {
	}

	/**
	 * Опубликованные записи под редиректом: ID записи => исходный адрес редиректа.
	 *
	 * @param bool $rebuild Пересобрать соответствие, если редиректы изменились. При сохранении записи
	 *                      пересборка не нужна: она делает сотни поисков записи по адресу.
	 * @return array<int, string>
	 */
	public function map( bool $rebuild = true ): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$this->map = array();

		if ( ! $this->service->available() ) {
			return $this->map;
		}

		$pairs = array();
		foreach ( $this->service->all() as $redirect ) {
			$pairs[] = $redirect->source . '>' . $redirect->target;
		}

		$hash  = md5( implode( '|', $pairs ) );
		$saved = get_option( self::OPTION, array() );

		$saved = is_array( $saved ) && is_array( $saved['map'] ?? null ) ? $saved : array();

		// Соответствие годится, пока редиректы те же и оно не старше половины суток:
		// за это время могла быть опубликована запись по адресу, с которого уже стоит редирект.
		$fresh = ( $saved['hash'] ?? '' ) === $hash && time() - (int) ( $saved['built'] ?? 0 ) < 12 * HOUR_IN_SECONDS;

		if ( $fresh ) {
			$this->map = array_map( 'strval', $saved['map'] );

			return $this->map;
		}

		if ( ! $rebuild ) {
			// Устаревшее соответствие лучше пустого; в память его не запоминаем, чтобы следующий вызов мог пересобрать.
			$this->map = null;

			return array_map( 'strval', $saved['map'] ?? array() );
		}

		$this->map = $this->build();

		update_option(
			self::OPTION,
			array(
				'hash'  => $hash,
				'built' => time(),
				'map'   => $this->map,
			),
			false
		);

		return $this->map;
	}

	/**
	 * Конечный адрес, на который уходит посетитель записи; пустая строка, если редиректа нет.
	 *
	 * @param int  $post_id ID записи.
	 * @param bool $rebuild Пересобрать соответствие, если редиректы изменились.
	 */
	public function target( int $post_id, bool $rebuild = true ): string {
		$source = $this->map( $rebuild )[ $post_id ] ?? '';

		return '' !== $source ? $this->service->resolver()->resolve( $source )['final'] : '';
	}

	/**
	 * Сбрасывает запомненное соответствие, например после публикации записи.
	 */
	public function flush(): void {
		$this->map = null;
		delete_option( self::OPTION );
	}

	/**
	 * Ищет записи по исходным адресам редиректов.
	 *
	 * @return array<int, string>
	 */
	private function build(): array {
		$resolver = $this->service->resolver();
		$map      = array();

		foreach ( $this->service->all() as $redirect ) {
			if ( ! $resolver->is_local( $redirect->source ) ) {
				continue;
			}

			$path    = (string) wp_parse_url( $resolver->absolute( $redirect->source ), PHP_URL_PATH );
			$post_id = url_to_postid( home_url( $path ) );

			if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
				$map[ $post_id ] = $redirect->source;
			}
		}

		return $map;
	}
}
