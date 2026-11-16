<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\ShortLinks\Store;

/**
 * Короткие ссылки в памяти для тестов.
 */
final class ArrayShortLinkStore implements Store {

	/**
	 * @var array<string, array{id: int, slug: string, target: string, code: int, clicks: int}>
	 */
	private array $links = array();

	public function find( string $slug ): ?array {
		return $this->links[ $slug ] ?? null;
	}

	public function insert( string $slug, string $target, int $code, string $campaign = '', ?string $expires_at = null ): int {
		$id                   = count( $this->links ) + 1;
		$this->links[ $slug ] = array(
			'id'         => $id,
			'slug'       => $slug,
			'target'     => $target,
			'code'       => $code,
			'clicks'     => 0,
			'campaign'   => $campaign,
			'expires_at' => $expires_at,
		);

		return $id;
	}

	public function hit( string $slug ): void {
		if ( isset( $this->links[ $slug ] ) ) {
			++$this->links[ $slug ]['clicks'];
		}
	}

	public function all( int $limit, int $offset, ?string $campaign = null, ?string $search = null ): array {
		$search = null === $search ? '' : mb_strtolower( $search );

		return array_slice(
			array_values(
				array_filter(
					$this->links,
					static fn( array $row ): bool => ( null === $campaign || $campaign === $row['campaign'] ) && ( '' === $search || str_contains( mb_strtolower( $row['slug'] . ' ' . $row['target'] ), $search ) )
				)
			),
			$offset,
			$limit
		);
	}

	public function count(): int {
		return count( $this->links );
	}

	public function delete( int $id ): void {
		foreach ( $this->links as $slug => $link ) {
			if ( $id === $link['id'] ) {
				unset( $this->links[ $slug ] );
			}
		}
	}

	/**
	 * Изменяет адрес, сохраняя код.
	 *
	 * @param int $id ID ссылки.
	 * @param string $target Новый адрес.
	 */
	public function update_target( int $id, string $target ): bool {
		foreach ( $this->links as &$link ) {
			if ( $id === $link['id'] ) {
				$link['target'] = $target;
				return true; }
		}
		return false;
	}

	/**
	 * Список непустых кампаний.
	 *
	 * @return list<string>
	 */
	public function campaigns(): array {
		return array_values( array_unique( array_filter( array_column( $this->links, 'campaign' ) ) ) );
	}

	/**
	 * Находит ссылку по ID.
	 *
	 * @param int $id ID ссылки.
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		foreach ( $this->links as $link ) {
			if ( $id === $link['id'] ) {
				return $link; }
		}
		return null;
	}
}
