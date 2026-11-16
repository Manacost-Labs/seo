<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Links;

use IndexPilot\Links\Extractor;
use IndexPilot\Links\Indexer;
use IndexPilot\Tests\Support\ArrayLinkStore;
use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase {

	private const HOST = 'hs-manacost.ru';

	/**
	 * Сопоставление адресов записям сайта.
	 */
	public static function resolve( string $url ): int {
		$map = array(
			'https://hs-manacost.ru/gajd-mag/'  => 10,
			'https://hs-manacost.ru/koloda-dk/' => 20,
			'https://hs-manacost.ru/sam/'       => 1,
		);

		return $map[ $url ] ?? 0;
	}

	public function test_links_are_extracted_and_classified(): void {
		$html = '<p><a href="/gajd-mag/">гайд</a> <a class="x" href=\'https://www.hs-manacost.ru/koloda-dk/?utm=1#list\' rel="nofollow noopener">колода</a> '
			. '<a href="https://hsreplay.net/decks/">статистика</a> <a href="#top">вверх</a> <a href="mailto:a@b.ru">почта</a> '
			. '<a href="javascript:void(0)">кнопка</a> <a>без адреса</a> <a href="/gajd-mag/">повтор</a> <a href="//cdn.test/x.png">cdn</a></p>';

		self::assertSame(
			array(
				array( 'url' => 'https://hs-manacost.ru/gajd-mag/', 'type' => 'internal', 'nofollow' => false ),
				array( 'url' => 'https://hs-manacost.ru/koloda-dk/', 'type' => 'internal', 'nofollow' => true ),
				array( 'url' => 'https://hsreplay.net/decks/', 'type' => 'external', 'nofollow' => false ),
				array( 'url' => 'https://cdn.test/x.png', 'type' => 'external', 'nofollow' => false ),
			),
			Extractor::extract( $html, self::HOST )
		);
	}

	public function test_post_with_two_internal_and_one_external_link_gives_three_rows(): void {
		$store   = new ArrayLinkStore();
		$indexer = new Indexer( $store, self::HOST, array( self::class, 'resolve' ) );

		$count = $indexer->index( 1, '<a href="/gajd-mag/">a</a> <a href="/koloda-dk/">b</a> <a href="https://hsreplay.net/">c</a>' );

		self::assertSame( 3, $count );
		self::assertCount( 3, $store->rows( 1 ) );
		self::assertSame( array( 10 => 1, 20 => 1, 30 => 0 ), $store->incoming( array( 10, 20, 30 ) ) );
	}

	public function test_reindexing_replaces_old_rows_and_counts_each_source_once(): void {
		$store   = new ArrayLinkStore();
		$indexer = new Indexer( $store, self::HOST, array( self::class, 'resolve' ) );

		$indexer->index( 1, '<a href="/gajd-mag/">a</a> <a href="/koloda-dk/">b</a>' );
		$indexer->index( 2, '<a href="/gajd-mag/">a</a> <a href="/gajd-mag/?x=1">ещё раз</a>' );
		$indexer->index( 1, '<a href="/gajd-mag/">осталась одна ссылка</a>' );

		self::assertSame( array( 10 => 2, 20 => 0 ), $store->incoming( array( 10, 20 ) ) );
		self::assertCount( 1, $store->rows( 1 ) );

		$indexer->remove( 2 );
		self::assertSame( array( 10 => 1 ), $store->incoming( array( 10 ) ) );
	}

	public function test_link_to_the_post_itself_and_nofollow_links_do_not_count_as_incoming(): void {
		$store   = new ArrayLinkStore();
		$indexer = new Indexer( $store, self::HOST, array( self::class, 'resolve' ) );

		$indexer->index( 1, '<a href="/sam/">на себя</a> <a href="/gajd-mag/" rel="nofollow">закрытая</a>' );

		self::assertSame( array( 1 => 0, 10 => 0 ), $store->incoming( array( 1, 10 ) ) );
		self::assertCount( 1, $store->rows( 1 ), 'Ссылка на саму себя не хранится.' );
	}

	public function test_orphans_are_posts_without_incoming_links(): void {
		$store   = new ArrayLinkStore();
		$indexer = new Indexer( $store, self::HOST, array( self::class, 'resolve' ) );

		$indexer->index( 1, '<a href="/gajd-mag/">a</a>' );

		$incoming = $store->incoming( array( 10, 20 ) );

		self::assertSame( array( 20 ), array_keys( array_filter( $incoming, static fn( int $count ): bool => 0 === $count ) ) );
	}
}
