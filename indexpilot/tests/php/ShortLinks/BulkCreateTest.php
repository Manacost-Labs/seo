<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\ShortLinks;

use IndexPilot\ShortLinks\Service;
use IndexPilot\Tests\Support\ArrayShortLinkStore;
use PHPUnit\Framework\TestCase;

final class BulkCreateTest extends TestCase {

	public function test_many_links_are_created_from_lines(): void {
		$store   = new ArrayShortLinkStore();
		$service = new Service( $store, static fn( int $id ): string => '' );

		$results = $service->create_many( "https://www.twitch.tv/manacost twitch\n\n  https://youtube.com/@manacost  \nне адрес\nhttps://boosty.to/x twitch", 302, 'весна' );

		self::assertCount( 4, $results );
		self::assertSame( 'twitch', $results[0]['result']['slug'] );
		self::assertSame( 'весна', $results[0]['result']['campaign'] );
		self::assertIsArray( $results[1]['result'] );
		self::assertSame( 'https://youtube.com/@manacost', $results[1]['line'] );
		self::assertIsString( $results[2]['result'], 'Строка без адреса — ошибка, остальные создаются.' );
		self::assertSame( 'Этот код уже занят.', $results[3]['result'] );
		self::assertSame( 2, $store->count() );
	}

	public function test_search_finds_by_code_and_target(): void {
		$store   = new ArrayShortLinkStore();
		$service = new Service( $store, static fn( int $id ): string => '' );
		$service->create( 'https://www.twitch.tv/manacost', 'twitch' );
		$service->create( 'https://boosty.to/manacost', 'boosty' );

		self::assertSame( array( 'twitch' ), array_column( $store->all( 10, 0, null, 'twitch' ), 'slug' ) );
		self::assertSame( array( 'boosty' ), array_column( $store->all( 10, 0, null, 'BOOSTY.to' ), 'slug' ) );
	}
}
