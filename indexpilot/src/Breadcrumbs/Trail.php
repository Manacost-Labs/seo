<?php
/**
 * Цепочка хлебных крошек.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Breadcrumbs;

use IndexPilot\Resolver\Context;

/**
 * Строит путь от главной страницы до текущей. Одна и та же цепочка выводится на странице и в разметке JSON-LD.
 */
final class Trail {

	/**
	 * Предел вложенности: защищает от зацикленных родителей.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * Конструктор.
	 *
	 * @param BreadcrumbData $data Данные сайта.
	 */
	public function __construct( private BreadcrumbData $data ) {
	}

	/**
	 * Цепочка для страницы: от главной до текущей включительно.
	 *
	 * @param Context $context Контекст страницы.
	 * @return list<array{name: string, url: string}>
	 */
	public function for_context( Context $context ): array {
		$crumbs = array( $this->data->home() );

		switch ( $context->type ) {
			case Context::HOME:
				break;

			case Context::POST:
				$crumbs = array_merge( $crumbs, $this->post_crumbs( $context->object_id ) );
				break;

			case Context::TERM:
				$crumbs = array_merge( $crumbs, $this->term_crumbs( $context->object_id, $context->subtype ) );
				break;

			case Context::POST_TYPE_ARCHIVE:
				$crumbs[] = $this->data->archive( $context->subtype );
				break;

			case Context::AUTHOR:
				$crumbs[] = $this->data->author( $context->object_id );
				break;

			default:
				$crumbs[] = $this->data->current( $context );
		}

		$trail = array();
		foreach ( $crumbs as $crumb ) {
			if ( '' !== trim( (string) ( $crumb['name'] ?? '' ) ) ) {
				$trail[] = array(
					'name' => (string) $crumb['name'],
					'url'  => (string) ( $crumb['url'] ?? '' ),
				);
			}
		}

		return $trail;
	}

	/**
	 * Часть цепочки для записи: архив типа, затем родительские страницы или рубрика, затем сама запись.
	 *
	 * @param int $post_id ID записи.
	 * @return list<array<string, mixed>>
	 */
	private function post_crumbs( int $post_id ): array {
		$post   = $this->data->post( $post_id );
		$crumbs = array( $this->data->archive( (string) $post['type'] ) );

		if ( (int) $post['parent'] > 0 ) {
			$ancestors = array();
			$seen      = array( $post_id => true );
			$parent_id = (int) $post['parent'];

			$depth = 0;

			while ( $parent_id > 0 && ! isset( $seen[ $parent_id ] ) && $depth < self::MAX_DEPTH ) {
				++$depth;
				$seen[ $parent_id ] = true;
				$parent             = $this->data->post( $parent_id );
				$ancestors[]        = $parent;
				$parent_id          = (int) $parent['parent'];
			}

			$crumbs = array_merge( $crumbs, array_reverse( $ancestors ) );
		} else {
			$term = $this->data->post_term( $post_id, (string) $post['type'] );

			if ( (int) $term['id'] > 0 ) {
				$crumbs = array_merge( $crumbs, $this->term_crumbs( (int) $term['id'], (string) $term['taxonomy'] ) );
			}
		}

		$crumbs[] = $post;

		return $crumbs;
	}

	/**
	 * Часть цепочки для термина: родительские термины, затем сам термин.
	 *
	 * @param int    $term_id  ID термина.
	 * @param string $taxonomy Таксономия.
	 * @return list<array<string, mixed>>
	 */
	private function term_crumbs( int $term_id, string $taxonomy ): array {
		$chain = array();
		$seen  = array();

		$depth = 0;

		while ( $term_id > 0 && ! isset( $seen[ $term_id ] ) && $depth < self::MAX_DEPTH ) {
			++$depth;
			$seen[ $term_id ] = true;
			$term             = $this->data->term( $term_id, $taxonomy );
			$chain[]          = $term;
			$term_id          = (int) $term['parent'];
		}

		return array_reverse( $chain );
	}
}
