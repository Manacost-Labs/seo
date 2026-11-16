<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\ShortLinks;

use IndexPilot\ShortLinks\Router;
use IndexPilot\ShortLinks\Service;
use IndexPilot\ShortLinks\Slug;
use IndexPilot\Tests\Support\ArrayShortLinkStore;
use PHPUnit\Framework\TestCase;

final class ShortLinksTest extends TestCase {

	public function test_utm_preserves_query_and_fragment(): void {
		self::assertSame(
			'https://site.test/?x=1&utm_source=old&utm_medium=social#part',
			Service::append_utm(
				'https://site.test/?x=1&utm_source=old#part',
				array(
					'source'   => 'new',
					'medium'   => 'social',
					'campaign' => '',
				)
			)
		);
	}

	public function test_robot_does_not_increment(): void {
		$store   = new ArrayShortLinkStore();
		$service = $this->service( $store );
		$service->create( 'https://site.test/', 'robot-test' );
		$service->hit( 'robot-test', 'Googlebot' );
		self::assertSame( 0, $store->find( 'robot-test' )['clicks'] );
	}

	public function test_campaign_expiry_and_target_edit(): void {
		$store   = new ArrayShortLinkStore();
		$service = $this->service( $store );
		$link    = $service->create( 'https://site.test/', 'campaign-test', 302, array(), 'Новости', '2000-01-01' );
		self::assertSame( 'Новости', $store->find( 'campaign-test' )['campaign'] );
		self::assertCount( 1, $store->all( 100, 0, 'Новости' ) );
		self::assertSame( array(), $store->all( 100, 0, 'Другая' ) );
		self::assertSame( array( 'Новости' ), $store->campaigns() );
		self::assertNull( $service->resolve( 'campaign-test' ) );
		self::assertTrue( $service->update_target( $link['id'], 'https://site.test/new' ) );
		self::assertSame( 'https://site.test/new', $store->find( 'campaign-test' )['target'] );
		self::assertIsString( $service->update_target( $link['id'], 'javascript:bad' ) );
	}

	public function test_human_robot_and_post_click_log(): void {
		$store   = new ArrayShortLinkStore();
		$log     = new \IndexPilot\Tests\Support\ArrayClickLog();
		$service = new Service( $store, static fn( int $id ): string => 5 === $id ? 'https://site.test/post' : '', $log );
		$service->create( 'https://site.test/', 'human-test' );
		$service->hit( 'human-test', 'Mozilla/5.0', 'https://Example.test/private?secret=yes' );
		self::assertSame( array( gmdate( 'Y-m-d' ) => 1 ), $log->by_day( 'human-test', 30 ) );
		self::assertSame( array( 'example.test' => 1 ), $log->by_referrer( 'human-test', 30 ) );
		$service->hit( 'human-test', 'Googlebot', 'https://robot.test/' );
		self::assertSame( 1, $store->find( 'human-test' )['clicks'] );
		self::assertSame( array( 'example.test' => 1 ), $log->by_referrer( 'human-test', 30 ) );
		$service->hit( 'p5', 'Mozilla/5.0' );
		self::assertSame( array( gmdate( 'Y-m-d' ) => 1 ), $log->by_day( 'p5', 30 ) );
		self::assertNull( $store->find( 'p5' ) );
	}

	private function service( ArrayShortLinkStore $store ): Service {
		// Опубликованы записи 5 и 1295 (её код — «pzz»).
		return new Service(
			$store,
			static fn( int $post_id ): string => in_array( $post_id, array( 5, 1295 ), true ) ? 'https://site.test/?p=' . $post_id : ''
		);
	}

	public function test_post_address_is_restored_from_its_short_code(): void {
		self::assertSame( 'p5', Slug::for_post( 5 ) );
		self::assertSame( 'pzz', Slug::for_post( 1295 ) );
		self::assertSame( 5, Slug::post_id( 'p5' ) );
		self::assertSame( 1295, Slug::post_id( 'pzz' ) );
		self::assertSame( 314159, Slug::post_id( Slug::for_post( 314159 ) ) );
		self::assertSame( 0, Slug::post_id( 'promo-2026' ), 'Код с дефисом — не код записи.' );
		self::assertSame( 0, Slug::post_id( 'x5' ) );
		self::assertSame( 0, Slug::post_id( 'p' ) );

		$resolved = $this->service( new ArrayShortLinkStore() )->resolve( 'p5' );

		self::assertSame( 'https://site.test/?p=5', $resolved['target'] );
		self::assertSame( 301, $resolved['code'] );
		self::assertNull( $this->service( new ArrayShortLinkStore() )->resolve( 'p6' ), 'Неопубликованная запись короткой ссылки не имеет.' );
	}

	public function test_custom_link_is_created_resolved_and_counted(): void {
		$store   = new ArrayShortLinkStore();
		$service = $this->service( $store );

		$link = $service->create( 'https://www.twitch.tv/manacost?ref=site', 'Twitch' );

		self::assertSame( 'twitch', $link['slug'], 'Код приводится к нижнему регистру.' );
		self::assertSame( 302, $link['code'] );

		$resolved = $service->resolve( 'TWITCH' );

		self::assertSame( 'https://www.twitch.tv/manacost?ref=site', $resolved['target'] );
		self::assertSame( 302, $resolved['code'] );

		$service->hit( 'twitch' );
		$service->hit( 'twitch' );

		self::assertSame( 2, $store->find( 'twitch' )['clicks'] );
		self::assertNull( $service->resolve( 'net-takoj' ) );
	}

	public function test_dangerous_and_malformed_targets_are_rejected(): void {
		$service = $this->service( new ArrayShortLinkStore() );

		foreach ( array( 'javascript:alert(1)', 'data:text/html,x', '//evil.test/x', 'twitch.tv/x', '', 'https://', "https://site.test/\nSet-Cookie: x=1" ) as $target ) {
			self::assertIsString( $service->create( $target ), 'Должна быть отклонена цель: ' . $target );
		}

		self::assertIsString( $service->create( 'https://site.test/', 'плохой код!' ) );
		self::assertIsString( $service->create( 'https://site.test/', 'a' ), 'Код из одного символа слишком короткий.' );
		self::assertIsString( $service->create( 'https://site.test/', 'x', 200 ), 'Код ответа должен быть кодом перенаправления.' );
	}

	public function test_taken_code_is_not_issued_twice(): void {
		$store   = new ArrayShortLinkStore();
		$service = $this->service( $store );

		self::assertIsArray( $service->create( 'https://site.test/a/', 'promo' ) );
		self::assertIsString( $service->create( 'https://site.test/b/', 'promo' ) );
		self::assertIsString( $service->create( 'https://site.test/c/', 'pzz' ), 'Код опубликованной записи занять нельзя.' );
		self::assertIsArray( $service->create( 'https://site.test/d/', 'p6' ), 'Код несуществующей записи свободен: если запись появится, её адрес всё равно будет важнее.' );

		// Код не задан — выдаётся свободный, каждый раз новый.
		$first  = $service->create( 'https://site.test/e/' );
		$second = $service->create( 'https://site.test/f/' );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertNotSame( $first['slug'], $second['slug'] );
		self::assertSame( 1, preg_match( '/^[a-z0-9]{6}$/', $first['slug'] ) );
	}

	public function test_request_path_is_matched_to_the_prefix(): void {
		self::assertSame( 'twitch', Router::slug_from_path( '/go/twitch', '/', 'go' ) );
		self::assertSame( 'twitch', Router::slug_from_path( '/go/Twitch/?utm=1', '/', 'go' ) );
		self::assertSame( 'p5', Router::slug_from_path( '/blog/s/p5', '/blog/', 's' ) );
		self::assertSame( '', Router::slug_from_path( '/go/', '/', 'go' ) );
		self::assertSame( '', Router::slug_from_path( '/go/a/b', '/', 'go' ) );
		self::assertSame( '', Router::slug_from_path( '/gajd/', '/', 'go' ) );
		self::assertSame( '', Router::slug_from_path( '/category/go/x', '/', 'go' ) );
	}

	public function test_expiry_boundary_and_invalid_input(): void {
		$store   = new ArrayShortLinkStore();
		$service = $this->service( $store );
		self::assertIsArray( $service->create( 'https://site.test/', 'today-link', 302, array(), 'Campaign', gmdate( 'Y-m-d' ) ) );
		self::assertNotNull( $service->resolve( 'today-link' ) );
		self::assertIsString( $service->create( 'https://site.test/', 'invalid-date', 302, array(), '', '2026-02-30' ) );
		self::assertNull( $store->find( 'invalid-date' ) );
		self::assertFalse( $service->update_target( 999, 'https://site.test/' ) );
		self::assertTrue( $service->update_target( 1, 'https://site.test/new' ) );
		self::assertSame( 'https://site.test/new', $service->resolve( 'today-link' )['target'] );
	}

	public function test_log_window_and_repeated_clicks(): void {
		$log = new \IndexPilot\Tests\Support\ArrayClickLog();
		$log->add( 'test', gmdate( 'Y-m-d' ), '' );
		$log->add( 'test', gmdate( 'Y-m-d' ), '' );
		$log->add( 'test', '2000-01-01', 'old.test' );
		$log->add( 'other', gmdate( 'Y-m-d' ), 'other.test' );
		self::assertSame( array( '' => 2 ), $log->by_referrer( 'test', 30 ) );
		self::assertSame( array( gmdate( 'Y-m-d' ) => 2 ), $log->by_day( 'test', 1 ) );
		self::assertSame( array(), $log->by_day( 'test', 0 ) );
	}

	public function test_custom_link_cannot_take_the_address_of_a_post(): void {
		$store   = new ArrayShortLinkStore();
		$service = $this->service( $store );

		// Ссылка с кодом будущей записи (черновик) после её публикации не перехватывает адрес записи.
		$store->insert( 'p5', 'https://evil.test/', 302 );
		self::assertSame( 'https://site.test/?p=5', $service->resolve( 'p5' )['target'] );
	}
}
