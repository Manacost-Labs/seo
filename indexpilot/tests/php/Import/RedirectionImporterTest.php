<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Import;

use IndexPilot\Import\RedirectionImporter;
use IndexPilot\Redirects\Manager;
use IndexPilot\Tests\Support\ArrayRedirectStore;
use PHPUnit\Framework\TestCase;

final class RedirectionImporterTest extends TestCase {

	/**
	 * Строка таблицы плагина Redirection.
	 *
	 * @param array<string, mixed> $fields Отличия от обычного правила.
	 * @return array<string, mixed>
	 */
	private static function row( array $fields ): array {
		return $fields + array(
			'id'          => 1,
			'url'         => '/a/',
			'regex'       => 0,
			'status'      => 'enabled',
			'action_type' => 'url',
			'action_code' => 301,
			'action_data' => '/b/',
			'match_type'  => 'url',
			'last_count'  => 0,
		);
	}

	private function import( ArrayRedirectStore $store, array $rows ): array {
		return ( new RedirectionImporter( $store, new Manager( $store, 'hs-manacost.ru' ) ) )->import( $rows );
	}

	public function test_pattern_and_disabled_rules_keep_their_flags(): void {
		$store  = new ArrayRedirectStore();
		$result = $this->import(
			$store,
			array(
				self::row(
					array(
						'url'         => '^/re/(.*)',
						'regex'       => 1,
						'action_data' => '/x/$1',
					)
				),
				self::row(
					array(
						'url'         => '/vyklyuchen/',
						'status'      => 'disabled',
						'action_code' => 302,
						'last_count'  => 15,
					)
				),
			)
		);

		$rules = $store->all();

		self::assertSame( 2, $result['imported'] );
		self::assertTrue( $rules[0]['regex'] );
		self::assertTrue( $rules[0]['enabled'] );
		self::assertSame( '/x/$1', $rules[0]['target'] );
		self::assertFalse( $rules[1]['regex'] );
		self::assertFalse( $rules[1]['enabled'] );
		self::assertSame( 302, $rules[1]['code'] );
		self::assertSame( 15, $rules[1]['hits'] );
	}

	public function test_error_rule_becomes_gone_and_unsupported_rules_are_skipped(): void {
		$store  = new ArrayRedirectStore();
		$result = $this->import(
			$store,
			array(
				self::row(
					array(
						'url'         => '/oshibka/',
						'action_type' => 'error',
						'action_code' => 410,
						'action_data' => '',
					)
				),
				self::row(
					array(
						'url'        => '/login-only/',
						'match_type' => 'login',
					)
				),
				self::row(
					array(
						'url'         => '/random/',
						'action_type' => 'random',
					)
				),
			)
		);

		self::assertSame( 1, $result['imported'] );
		self::assertSame( 2, $result['skipped'] );
		self::assertSame( 410, $store->all()[0]['code'] );
		self::assertSame( '', $store->all()[0]['target'] );
	}

	public function test_chains_are_collapsed_and_repeat_import_adds_nothing(): void {
		$store = new ArrayRedirectStore();
		$rows  = array(
			self::row(
				array(
					'url'         => '/a/',
					'action_data' => '/b/',
				)
			),
			self::row(
				array(
					'id'          => 2,
					'url'         => '/b/',
					'action_data' => 'https://hs-manacost.ru/c/',
					'action_code' => 308,
				)
			),
		);

		$result = $this->import( $store, $rows );

		self::assertSame( 1, $result['collapsed'] );
		self::assertSame( '/c/', $store->all()[0]['target'] );
		self::assertSame( 301, $store->all()[1]['code'], 'Код 308 переносится как постоянный 301.' );
		self::assertNotNull( $store->index );

		$again = $this->import( $store, $rows );

		self::assertSame( 0, $again['imported'] );
		self::assertSame( 2, $again['duplicates'] );
		self::assertCount( 2, $store->all() );
	}
}
