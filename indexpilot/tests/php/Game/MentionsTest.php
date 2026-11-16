<?php
/**
 * Проверки поиска упоминаний классов и архетипов.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Tests\Game;

use IndexPilot\Game\Lexicon;
use IndexPilot\Game\Mentions;
use PHPUnit\Framework\TestCase;

/**
 * Находит в тексте классы и архетипы сайта и подсказывает ссылки на их страницы.
 */
final class MentionsTest extends TestCase {

	/**
	 * Упоминания для сайта с тремя архетипами и страницами двух классов.
	 */
	private function mentions(): Mentions {
		return new Mentions(
			new Lexicon(),
			array(
				'priest'       => 'https://hs.test/class/priest/',
				'death-knight' => 'https://hs.test/class/death-knight/',
			),
			array(
				array(
					'name' => 'Blood DK',
					'url'  => 'https://hs.test/archetype/blood-dk/',
				),
				array(
					'name' => 'Маг на заклинаниях',
					'url'  => 'https://hs.test/archetype/spell-mage/',
				),
				array(
					'name' => 'Big',
					'url'  => 'https://hs.test/archetype/big/',
				),
			)
		);
	}

	/**
	 * Архетип, записанный по-русски на слух, находится вместе с адресом его страницы.
	 */
	public function test_archetype_written_in_russian_is_found(): void {
		$found = $this->mentions()->find( 'Как играть против Блад ДК в новом сезоне' );
		$names = array_column( $found, 'name' );

		self::assertContains( 'Blood DK', $names );

		$blood = $found[ (int) array_search( 'Blood DK', $names, true ) ];

		self::assertSame( Mentions::ARCHETYPE, $blood['type'] );
		self::assertSame( 'https://hs.test/archetype/blood-dk/', $blood['url'] );
	}

	/**
	 * Формы слов и написание латиницей тоже считаются; короткое случайное совпадение — нет.
	 */
	public function test_word_forms_and_false_matches(): void {
		$mentions = $this->mentions();

		self::assertSame( array( 'Маг на заклинаниях' ), array_column( $mentions->find( 'Мага на заклинаниях берут реже.' ), 'name' ) );
		self::assertContains( 'Blood DK', array_column( $mentions->find( 'Против blood dk играйте от темпа' ), 'name' ) );
		self::assertSame( array(), $mentions->find( 'Бег по кругу и бог из машины' ) );
	}

	/**
	 * Классы находятся по разговорным именам и получают адрес своей страницы, если она есть.
	 */
	public function test_classes(): void {
		$found = $this->mentions()->find( 'Дека приста и колода шамана' );

		self::assertSame(
			array(
				array(
					'type' => Mentions::CLASS_TYPE,
					'name' => 'Жрец',
					'url'  => 'https://hs.test/class/priest/',
				),
				array(
					'type' => Mentions::CLASS_TYPE,
					'name' => 'Шаман',
					'url'  => '',
				),
			),
			$found
		);
	}

	/**
	 * Подсказка ссылки появляется, только если на страницу ещё не ссылаются.
	 */
	public function test_unlinked(): void {
		$mentions = $this->mentions();
		$html     = '<p>Против Блад ДК и <a href="/class/death-knight">рыцаря смерти</a> играйте от темпа.</p>';
		$found    = $mentions->find( 'Против Блад ДК и рыцаря смерти играйте от темпа.' );
		$missing  = Mentions::unlinked( $found, $html );

		self::assertSame( array( 'Blood DK' ), array_column( $missing, 'name' ) );

		$linked = '<p>Против <a href="https://hs.test/archetype/blood-dk/">Блад ДК</a>.</p>';

		self::assertSame( array(), Mentions::unlinked( $mentions->find( 'Против Блад ДК.' ), $linked ) );
	}
}
