<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Health;

use IndexPilot\Health\SelfCheck;
use IndexPilot\Tests\Support\ArrayHealthStore;
use PHPUnit\Framework\TestCase;

final class SelfCheckTest extends TestCase {

	/**
	 * Текущее время по поддельным часам.
	 *
	 * @var float
	 */
	private float $now = 1000.0;

	/**
	 * Сколько секунд проверка ждала.
	 *
	 * @var list<float>
	 */
	private array $slept = array();

	/**
	 * Какие адреса запрашивались.
	 *
	 * @var list<string>
	 */
	private array $fetched = array();

	private static function words( int $count, string $word = 'слово' ): string {
		return implode( ' ', array_fill( 0, $count, $word ) );
	}

	private static function page( string $text ): string {
		return '<html><head><title>x</title><script>var a = "много слов в скрипте не считаются";</script></head><body><nav>Меню Главная Гайды Колоды</nav><main><p>' . $text . '</p></main><footer>Подвал сайта</footer></body></html>';
	}

	/**
	 * @param array<string, array<string, mixed>> $responses Ответы по адресам.
	 * @param array<string, list<string>>         $posts     Тексты записей, на которые ссылается архив.
	 */
	private function check( ArrayHealthStore $store, array $responses, array $posts = array() ): SelfCheck {
		return new SelfCheck(
			$store,
			function ( string $url ) use ( $responses ): array {
				$this->fetched[] = $url;
				$this->now      += 0.2;

				return $responses[ $url ] ?? array(
					'status'   => 200,
					'location' => '',
					'ms'       => 100,
					'body'     => self::page( self::words( 300 ) ),
				);
			},
			static fn( string $html, string $url ): array => $posts[ $url ] ?? array(),
			function ( float $seconds ): void {
				$this->slept[] = $seconds;
				$this->now    += $seconds;
			},
			fn(): float => $this->now,
			fn(): int => (int) $this->now
		);
	}

	public function test_page_with_twenty_words_is_marked_empty(): void {
		$store = new ArrayHealthStore();
		$check = $this->check(
			$store,
			array(
				'https://site.test/pusto/' => array(
					'status'   => 200,
					'location' => '',
					'ms'       => 120,
					'body'     => self::page( self::words( 20 ) ),
				),
			)
		);

		$check->start( array( array( 'url' => 'https://site.test/pusto/' ), array( 'url' => 'https://site.test/polno/' ) ) );
		$check->run_batch( 10 );

		self::assertSame( 'empty', $store->get( 'page', 'https://site.test/pusto/' )['problems'] );
		self::assertSame( 20, $store->get( 'page', 'https://site.test/pusto/' )['words'] );
		self::assertSame( '', $store->get( 'page', 'https://site.test/polno/' )['problems'] );
	}

	public function test_walk_continues_where_it_stopped(): void {
		$store = new ArrayHealthStore();
		$urls  = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$urls[] = array( 'url' => 'https://site.test/p' . $i . '/' );
		}

		$this->check( $store, array() )->start( $urls );
		$first = $this->check( $store, array() )->run_batch( 2 );

		self::assertSame( 2, $first['checked'] );
		self::assertFalse( $first['done'] );

		// Новый объект — как следующий запуск по расписанию: продолжает с третьего адреса.
		$second = $this->check( $store, array() )->run_batch( 10 );

		self::assertSame( 3, $second['checked'] );
		self::assertTrue( $second['done'] );
		self::assertSame( array_column( $urls, 'url' ), $this->fetched, 'Каждый адрес запрошен один раз и по порядку.' );
	}

	public function test_requests_are_at_least_one_second_apart(): void {
		$store = new ArrayHealthStore();
		$check = $this->check( $store, array() );

		$check->start( array( array( 'url' => 'https://site.test/a/' ), array( 'url' => 'https://site.test/b/' ), array( 'url' => 'https://site.test/c/' ) ) );
		$check->run_batch( 10 );

		self::assertCount( 2, $this->slept );
		self::assertEqualsWithDelta( 0.8, $this->slept[0], 0.001 );
	}

	public function test_errors_redirects_and_slow_pages_are_reported(): void {
		$store = new ArrayHealthStore();
		$check = $this->check(
			$store,
			array(
				'https://site.test/net/'      => array(
					'status'   => 404,
					'location' => '',
					'ms'       => 100,
					'body'     => self::page( self::words( 300 ) ),
				),
				'https://site.test/pereezd/'  => array(
					'status'   => 301,
					'location' => 'https://site.test/novoe/',
					'ms'       => 100,
					'body'     => '',
				),
				'https://site.test/medlenno/' => array(
					'status'   => 200,
					'location' => '',
					'ms'       => 4500,
					'body'     => self::page( self::words( 300 ) ),
				),
			)
		);

		$check->start( array( array( 'url' => 'https://site.test/net/' ), array( 'url' => 'https://site.test/pereezd/' ), array( 'url' => 'https://site.test/medlenno/' ) ) );
		$check->run_batch( 10 );

		self::assertSame( 'status', $store->get( 'page', 'https://site.test/net/' )['problems'] );
		self::assertSame( 'redirect', $store->get( 'page', 'https://site.test/pereezd/' )['problems'] );
		self::assertSame( 'slow', $store->get( 'page', 'https://site.test/medlenno/' )['problems'] );
	}

	public function test_archive_with_full_texts_of_two_posts_is_marked(): void {
		$first  = 'Начало первой записи ' . self::words( 100, 'текст' ) . ' и вот самый конец первой записи про муллиган жреца на легенде';
		$second = 'Начало второй записи ' . self::words( 100, 'колода' ) . ' а это последние слова второй записи о матчапах против охотника';
		$short  = 'Начало третьей записи ' . self::words( 100, 'мета' ) . ' финал третьей записи который в выдержку не попал никогда совсем';

		$store = new ArrayHealthStore();
		$check = $this->check(
			$store,
			array(
				'https://site.test/full/'    => array(
					'status'   => 200,
					'location' => '',
					'ms'       => 100,
					'body'     => self::page( '<article>' . $first . '</article><article>' . $second . '</article>' ),
				),
				'https://site.test/excerpt/' => array(
					'status'   => 200,
					'location' => '',
					'ms'       => 100,
					'body'     => self::page( '<article>' . mb_substr( $first, 0, 300 ) . '…</article><article>' . mb_substr( $short, 0, 300 ) . '…</article>' . self::words( 100 ) ),
				),
			),
			array(
				'https://site.test/full/'    => array( $first, $second ),
				'https://site.test/excerpt/' => array( $first, $short ),
			)
		);

		$check->start(
			array(
				array(
					'url'     => 'https://site.test/full/',
					'archive' => true,
				),
				array(
					'url'     => 'https://site.test/excerpt/',
					'archive' => true,
				),
			)
		);
		$check->run_batch( 10 );

		self::assertSame( 'full_text', $store->get( 'page', 'https://site.test/full/' )['problems'] );
		self::assertSame( '', $store->get( 'page', 'https://site.test/excerpt/' )['problems'] );
		self::assertSame( 2, SelfCheck::full_texts( SelfCheck::text( self::page( $first . ' ' . $second ) ), array( $first, $second ) ) );
	}

	public function test_finished_walk_removes_addresses_that_left_the_sitemap(): void {
		$store = new ArrayHealthStore();
		$store->save(
			'page',
			'https://site.test/staroe/',
			array(
				'problems' => 'status',
				'checked'  => 10,
			)
		);

		$check = $this->check( $store, array() );
		$check->start( array( array( 'url' => 'https://site.test/a/' ) ) );
		$check->run_batch( 10 );

		self::assertNull( $store->get( 'page', 'https://site.test/staroe/' ) );
		self::assertTrue( $check->state()['done'] );
	}
}
