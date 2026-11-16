<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Redirects;

use IndexPilot\Redirects\Manager;
use IndexPilot\Redirects\Redirect;
use IndexPilot\Tests\Support\ArrayRedirectStore;
use PHPUnit\Framework\TestCase;

final class ManagerTest extends TestCase {

	private const HOST = 'hs-manacost.ru';

	private ArrayRedirectStore $store;

	protected function setUp(): void {
		$this->store = new ArrayRedirectStore();
	}

	private function manager( array $foreign = array() ): Manager {
		return new Manager( $this->store, self::HOST, $foreign );
	}

	private function rule( string $source, string $target, int $code = 301 ): array {
		return array(
			'source' => $source,
			'target' => $target,
			'code'   => $code,
		);
	}

	public function test_chain_is_collapsed_and_loop_is_rejected(): void {
		$manager = $this->manager();
		$manager->save( $this->rule( '/b/', '/c/' ) );

		$saved = $manager->save( $this->rule( '/a/', '/b/' ) );

		self::assertIsArray( $saved );
		self::assertSame( '/c/', $saved['target'], 'Правило A → B при существующем B → C сохраняется как A → C.' );

		$loop = $manager->save( $this->rule( '/c/', '/a/' ) );

		self::assertSame( Manager::ERROR_LOOP, $loop );
		self::assertCount( 2, $this->store->all() );
	}

	public function test_rules_leading_to_the_new_source_are_retargeted(): void {
		$manager = $this->manager();
		$first   = $manager->save( $this->rule( '/x/', '/a/' ) );

		$manager->save( $this->rule( '/a/', 'https://hs-manacost.ru/b/' ) );

		self::assertSame( '/b/', $this->store->find( $first['id'] )['target'], 'X → A превращается в X → B: цепочки не остаётся.' );
	}

	public function test_redirection_rules_take_part_in_collapsing(): void {
		$saved = $this->manager( array( new Redirect( 7, '/b/', 'https://kolodahearthstone.com/b/' ) ) )->save( $this->rule( '/a/', '/b/' ) );

		self::assertSame( 'https://kolodahearthstone.com/b/', $saved['target'] );
	}

	public function test_self_redirect_duplicate_and_bad_input_are_rejected(): void {
		$manager = $this->manager();

		self::assertSame( Manager::ERROR_LOOP, $manager->save( $this->rule( '/a/', 'https://www.hs-manacost.ru/A' ) ) );
		self::assertSame( Manager::ERROR_SOURCE, $manager->save( $this->rule( '', '/b/' ) ) );
		self::assertSame( Manager::ERROR_TARGET, $manager->save( $this->rule( '/a/', 'javascript:alert(1)' ) ) );
		self::assertSame( Manager::ERROR_CODE, $manager->save( $this->rule( '/a/', '/b/', 404 ) ) );

		$manager->save( $this->rule( '/a/', '/b/' ) );

		self::assertSame( Manager::ERROR_DUPLICATE, $manager->save( $this->rule( '/A', '/c/' ) ) );
	}

	public function test_gone_rule_needs_no_target_and_pattern_must_compile(): void {
		$manager = $this->manager();
		$gone    = $manager->save( $this->rule( '/udalena/', '/ignored/', 410 ) );

		self::assertSame( '', $gone['target'] );
		self::assertSame( Manager::ERROR_PATTERN, $manager->save( array( 'regex' => true ) + $this->rule( '^/x/(', '/y/' ) ) );
		self::assertIsArray( $manager->save( array( 'regex' => true ) + $this->rule( '^/x/(.*)', '/y/$1' ) ) );
	}

	public function test_editing_keeps_the_rule_and_index_is_rebuilt(): void {
		$manager = $this->manager();
		$saved   = $manager->save( $this->rule( '/a/', '/b/' ) );
		$edited  = $manager->save( $this->rule( '/a/', '/c/', 302 ), $saved['id'] );

		self::assertSame( $saved['id'], $edited['id'] );
		self::assertSame( 302, $this->store->find( $saved['id'] )['code'] );
		self::assertNotNull( $this->store->index );
	}

	public function test_auto_redirect_replaces_the_rule_from_the_new_address(): void {
		$manager = $this->manager();
		$manager->auto( 'https://hs-manacost.ru/old/', 'https://hs-manacost.ru/new/' );
		// Адрес вернули обратно: старое правило «new → …» мешало бы, а «old → new» стало бы петлёй.
		$manager->auto( 'https://hs-manacost.ru/new/', 'https://hs-manacost.ru/old/' );

		$rules = $this->store->all();

		self::assertCount( 1, $rules );
		self::assertSame( '/new/', $rules[0]['source'] );
		self::assertSame( '/old/', $rules[0]['target'] );
	}

	public function test_collapse_all_shortens_chains_and_disables_loops(): void {
		$this->store->insert( $this->rule( '/a/', '/b/' ) );
		$this->store->insert( $this->rule( '/b/', '/c/' ) );
		$this->store->insert( $this->rule( '/l1/', '/l2/' ) );
		$this->store->insert( $this->rule( '/l2/', '/l1/' ) );

		$result = $this->manager()->collapse_all();

		self::assertSame( 1, $result['collapsed'] );
		self::assertSame( 2, $result['loops'] );
		self::assertSame( '/c/', $this->store->find( 1 )['target'] );
		self::assertFalse( $this->store->find( 3 )['enabled'] );
	}
}
