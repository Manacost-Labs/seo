<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Schema;

use IndexPilot\Breadcrumbs\Trail;
use IndexPilot\Resolver\Context;
use IndexPilot\Schema\Graph;
use IndexPilot\Schema\GraphBuilder;
use IndexPilot\Tests\Support\ArrayBreadcrumbData;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	private function trail(): Trail {
		return new Trail(
			new ArrayBreadcrumbData(
				array(
					10 => array( 'name' => 'Гайд на мага', 'url' => 'https://site.test/gajd-mag/', 'type' => 'post', 'parent' => 0 ),
					20 => array( 'name' => 'О сайте', 'url' => 'https://site.test/o-sajte/', 'type' => 'page', 'parent' => 0 ),
					21 => array( 'name' => 'Команда', 'url' => 'https://site.test/o-sajte/komanda/', 'type' => 'page', 'parent' => 20 ),
					30 => array( 'name' => 'Blood DK', 'url' => 'https://site.test/hs_deck/blood-dk/', 'type' => 'hs_deck', 'parent' => 0 ),
					40 => array( 'name' => 'Петля', 'url' => 'https://site.test/loop/', 'type' => 'page', 'parent' => 41 ),
					41 => array( 'name' => 'Петля 2', 'url' => 'https://site.test/loop-2/', 'type' => 'page', 'parent' => 40 ),
				),
				array(
					'category'       => array(
						1 => array( 'name' => 'Гайды', 'url' => 'https://site.test/category/gajdy/', 'parent' => 0 ),
						2 => array( 'name' => 'Маг', 'url' => 'https://site.test/category/gajdy/mag/', 'parent' => 1 ),
					),
					'deck_archetype' => array(
						5 => array( 'name' => 'Blood DK', 'url' => 'https://site.test/archetype/blood-dk/', 'parent' => 0 ),
					),
				),
				array(
					10 => array( 'id' => 2, 'taxonomy' => 'category' ),
					30 => array( 'id' => 5, 'taxonomy' => 'deck_archetype' ),
				),
				array(
					'hs_deck' => array( 'name' => 'Колоды', 'url' => 'https://site.test/hs_deck/' ),
				)
			)
		);
	}

	/**
	 * @param list<array{name: string, url: string}> $crumbs Цепочка.
	 * @return list<string>
	 */
	private static function names( array $crumbs ): array {
		return array_column( $crumbs, 'name' );
	}

	public function test_post_in_nested_category(): void {
		$crumbs = $this->trail()->for_context( new Context( Context::POST, 10, 'post' ) );

		self::assertSame( array( 'Главная', 'Гайды', 'Маг', 'Гайд на мага' ), self::names( $crumbs ) );
		self::assertSame( 'https://site.test/category/gajdy/mag/', $crumbs[2]['url'] );
	}

	public function test_child_page_deck_and_term(): void {
		$trail = $this->trail();

		self::assertSame( array( 'Главная', 'О сайте', 'Команда' ), self::names( $trail->for_context( new Context( Context::POST, 21, 'page' ) ) ) );
		self::assertSame( array( 'Главная', 'Колоды', 'Blood DK', 'Blood DK' ), self::names( $trail->for_context( new Context( Context::POST, 30, 'hs_deck' ) ) ) );
		self::assertSame( array( 'Главная', 'Гайды', 'Маг' ), self::names( $trail->for_context( new Context( Context::TERM, 2, 'category' ) ) ) );
		self::assertSame( array( 'Главная' ), self::names( $trail->for_context( new Context( Context::HOME ) ) ) );
		self::assertSame( array( 'Главная', 'Страница search' ), self::names( $trail->for_context( new Context( Context::SEARCH ) ) ) );
	}

	public function test_parent_loop_does_not_hang(): void {
		$crumbs = $this->trail()->for_context( new Context( Context::POST, 40, 'page' ) );

		self::assertSame( 'Петля', $crumbs[ count( $crumbs ) - 1 ]['name'] );
		self::assertLessThan( 15, count( $crumbs ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function article_page(): array {
		return array(
			'site'         => array(
				'url'         => 'https://site.test/',
				'name'        => 'Manacost',
				'description' => 'Всё о Hearthstone',
				'language'    => 'ru-RU',
			),
			'organization' => array(
				'name'    => 'Manacost',
				'logo'    => array( 'url' => 'https://site.test/logo.png', 'width' => 512, 'height' => 512 ),
				'same_as' => array( 'https://vk.com/manacost' ),
			),
			'page'         => array(
				'type'        => 'WebPage',
				'url'         => 'https://site.test/gajd-mag/',
				'title'       => 'Гайд на мага — Manacost',
				'description' => 'Колода мага: список карт и тактика.',
				'published'   => '2026-11-01T10:00:00+03:00',
				'modified'    => '2026-11-10T10:00:00+03:00',
				'image'       => array( 'url' => 'https://site.test/mag.jpg', 'width' => 1200, 'height' => 630 ),
			),
			'breadcrumbs'  => $this->trail()->for_context( new Context( Context::POST, 10, 'post' ) ),
			'article'      => array(
				'type'       => 'Article',
				'headline'   => 'Гайд на мага',
				'section'    => 'Маг',
				'word_count' => 1200,
				'author'     => array( 'name' => 'Иван', 'url' => 'https://site.test/author/ivan/' ),
			),
		);
	}

	public function test_every_reference_points_to_a_node_of_the_graph(): void {
		$graph = ( new GraphBuilder() )->build( $this->article_page() );

		self::assertSame( array(), $graph->dangling() );

		$types = array_map( static fn( array $node ): string => $node['@type'], $graph->nodes() );
		sort( $types );

		self::assertSame( array( 'Article', 'BreadcrumbList', 'ImageObject', 'ImageObject', 'Organization', 'Person', 'WebPage', 'WebSite' ), $types );
	}

	public function test_article_without_person_author_is_authored_by_the_site(): void {
		$page = $this->article_page();
		unset( $page['article']['author'] );

		$graph = ( new GraphBuilder() )->build( $page );

		self::assertSame( $graph->first( 'Organization' )['@id'], $graph->first( 'Article' )['author']['@id'], 'У колоды нет автора-человека.' );
		self::assertSame( array(), $graph->dangling() );
	}

	public function test_article_is_linked_to_page_author_and_publisher(): void {
		$graph   = ( new GraphBuilder() )->build( $this->article_page() );
		$article = $graph->first( 'Article' );
		$page    = $graph->first( 'WebPage' );
		$crumbs  = $graph->first( 'BreadcrumbList' );

		self::assertSame( $page['@id'], $article['mainEntityOfPage']['@id'] );
		self::assertSame( $graph->first( 'Person' )['@id'], $article['author']['@id'] );
		self::assertSame( $graph->first( 'Organization' )['@id'], $article['publisher']['@id'] );
		self::assertSame( '2026-11-01T10:00:00+03:00', $article['datePublished'] );
		self::assertSame( $crumbs['@id'], $page['breadcrumb']['@id'] );
		self::assertSame( $graph->first( 'WebSite' )['@id'], $page['isPartOf']['@id'] );

		self::assertCount( 4, $crumbs['itemListElement'] );
		self::assertSame( 3, $crumbs['itemListElement'][2]['position'] );
		self::assertSame( 'Маг', $crumbs['itemListElement'][2]['name'] );
		self::assertSame( 'https://site.test/category/gajdy/mag/', $crumbs['itemListElement'][2]['item'] );
	}

	public function test_archive_page_is_a_collection_without_article_and_empty_values(): void {
		$page                    = $this->article_page();
		$page['page']['type']    = 'CollectionPage';
		$page['page']['image']   = null;
		$page['page']['description'] = '';
		$page['article']         = null;
		$page['organization']['logo'] = null;
		$page['breadcrumbs']     = array( array( 'name' => 'Главная', 'url' => 'https://site.test/' ) );

		$graph = ( new GraphBuilder() )->build( $page );

		self::assertSame( array(), $graph->dangling() );
		self::assertNull( $graph->first( 'Article' ) );
		self::assertNull( $graph->first( 'BreadcrumbList' ), 'Цепочка из одной главной страницы не выводится.' );
		self::assertArrayNotHasKey( 'description', $graph->first( 'CollectionPage' ) );
		self::assertArrayNotHasKey( 'breadcrumb', $graph->first( 'CollectionPage' ) );
		self::assertArrayNotHasKey( 'logo', $graph->first( 'Organization' ) );
	}

	public function test_missing_node_is_reported(): void {
		$graph = new Graph();
		$graph->add( array( '@type' => 'WebPage', '@id' => 'https://site.test/#webpage', 'isPartOf' => array( '@id' => 'https://site.test/#website' ) ) );

		self::assertSame( array( 'https://site.test/#website' ), $graph->dangling() );
	}

	public function test_json_is_one_block_safe_for_a_script_tag(): void {
		$page                  = $this->article_page();
		$page['page']['title'] = 'Маг </script><script>alert(1)</script>';

		$json = ( new GraphBuilder() )->build( $page )->to_json();
		$data = json_decode( $json, true );

		self::assertStringNotContainsString( '</script>', $json );
		self::assertSame( 'https://schema.org', $data['@context'] );
		self::assertCount( 8, $data['@graph'] );
		self::assertStringContainsString( 'Гайд на мага', $json, 'Кириллица не превращается в коды.' );
	}
}
