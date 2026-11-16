<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Support;

use IndexPilot\Breadcrumbs\BreadcrumbData;
use IndexPilot\Resolver\Context;

/**
 * Данные для хлебных крошек из массивов.
 */
final class ArrayBreadcrumbData implements BreadcrumbData {

	/**
	 * @param array<int, array{name: string, url: string, type: string, parent: int}>  $posts      Записи.
	 * @param array<string, array<int, array{name: string, url: string, parent: int}>> $terms      Термины по таксономиям.
	 * @param array<int, array{id: int, taxonomy: string}>                             $post_terms Главный термин записи.
	 * @param array<string, array{name: string, url: string}>                          $archives   Архивы типов записей.
	 */
	public function __construct(
		private array $posts = array(),
		private array $terms = array(),
		private array $post_terms = array(),
		private array $archives = array()
	) {
	}

	public function home(): array {
		return array(
			'name' => 'Главная',
			'url'  => 'https://site.test/',
		);
	}

	public function post( int $post_id ): array {
		return $this->posts[ $post_id ] ?? array(
			'name'   => '',
			'url'    => '',
			'type'   => '',
			'parent' => 0,
		);
	}

	public function archive( string $post_type ): array {
		return $this->archives[ $post_type ] ?? array(
			'name' => '',
			'url'  => '',
		);
	}

	public function post_term( int $post_id, string $post_type ): array {
		return $this->post_terms[ $post_id ] ?? array(
			'id'       => 0,
			'taxonomy' => '',
		);
	}

	public function term( int $term_id, string $taxonomy ): array {
		return $this->terms[ $taxonomy ][ $term_id ] ?? array(
			'name'   => '',
			'url'    => '',
			'parent' => 0,
		);
	}

	public function author( int $user_id ): array {
		return array(
			'name' => 'Автор ' . $user_id,
			'url'  => 'https://site.test/author/' . $user_id . '/',
		);
	}

	public function current( Context $context ): array {
		return array(
			'name' => 'Страница ' . $context->type,
			'url'  => '',
		);
	}
}
