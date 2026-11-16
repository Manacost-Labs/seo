<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Audit;

use IndexPilot\Audit\Duplicates;
use IndexPilot\Audit\PageFacts;
use PHPUnit\Framework\TestCase;

final class AuditTest extends TestCase {

	private const NOW = 1794700800; // 15.11.2026.

	/**
	 * @param array<string, mixed> $overrides Отличия от обычной страницы.
	 * @return array<string, mixed>
	 */
	private function facts( array $overrides = array() ): array {
		return PageFacts::build(
			$overrides + array(
				'title'              => 'Гайд на мага — Manacost',
				'description'        => 'Колода мага: список карт и тактика.',
				'description_source' => 'custom',
				'indexable'          => true,
				'content'            => '<h2>Вступление</h2><p>' . str_repeat( 'слово ', 400 ) . '</p>',
				'modified'           => self::NOW - 30 * 86400,
				'min_words'          => 300,
				'now'                => self::NOW,
			)
		);
	}

	public function test_two_posts_with_the_same_final_title_form_a_pair(): void {
		$first  = $this->facts();
		$second = $this->facts( array( 'title' => '  гайд на МАГА — manacost ' ) );
		$third  = $this->facts( array( 'title' => 'Гайд на жреца — Manacost' ) );

		self::assertSame( $first['title_hash'], $second['title_hash'], 'Регистр и пробелы по краям не делают заголовки разными.' );
		self::assertNotSame( $first['title_hash'], $third['title_hash'] );

		$groups = Duplicates::groups(
			array(
				10 => $first['title_hash'],
				20 => $second['title_hash'],
				30 => $third['title_hash'],
				40 => '',
			)
		);

		self::assertSame( array( array( 10, 20 ) ), $groups );
	}

	public function test_larger_groups_come_first(): void {
		$groups = Duplicates::groups( array( 1 => 'a', 2 => 'b', 3 => 'b', 4 => 'a', 5 => 'a', 6 => 'c' ) );

		self::assertSame( array( array( 1, 4, 5 ), array( 2, 3 ) ), $groups );
	}

	public function test_normal_page_has_no_problems(): void {
		$facts = $this->facts();

		self::assertSame( 0, $facts['no_description'] );
		self::assertSame( 0, $facts['auto_description'] );
		self::assertSame( 0, $facts['h1'] );
		self::assertSame( 0, $facts['thin'] );
		self::assertSame( 0, $facts['stale'] );
		self::assertSame( 1, $facts['indexable'] );
		self::assertSame( 401, $facts['words'] );
	}

	public function test_problems_are_detected(): void {
		$facts = $this->facts(
			array(
				'description'        => '',
				'description_source' => 'none',
				'content'            => '<h1>Заголовок</h1><p>Короткий текст.</p><H1 class="x">Ещё один</H1>',
				'modified'           => self::NOW - 600 * 86400,
				'indexable'          => false,
			)
		);

		self::assertSame( 1, $facts['no_description'] );
		self::assertSame( '', $facts['description_hash'], 'Пустое описание дублем не считается.' );
		self::assertSame( 2, $facts['h1'] );
		self::assertSame( 1, $facts['thin'] );
		self::assertSame( 1, $facts['stale'] );
		self::assertSame( 0, $facts['indexable'] );
	}

	public function test_auto_description_and_types_without_word_limit(): void {
		$facts = $this->facts(
			array(
				'description_source' => 'auto',
				'content'            => '',
				'min_words'          => 0,
			)
		);

		self::assertSame( 1, $facts['auto_description'] );
		self::assertSame( 0, $facts['thin'], 'Для типа записи без нормы объёма короткий текст не проблема.' );
	}
}
