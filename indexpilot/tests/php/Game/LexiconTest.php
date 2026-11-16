<?php
/**
 * Проверки игрового словаря.
 *
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Tests\Game;
use IndexPilot\Game\Lexicon;
use PHPUnit\Framework\TestCase;
/** Проверяет контракт словаря. */
final class LexiconTest extends TestCase {
	/** Проверяет сравнение и словоформы. */
	public function test_normalization(): void {
		$l = new Lexicon();
		$this->assertTrue( $l->same( 'дека приста', 'колода жреца' ) );
		$this->assertTrue( $l->same( 'гайд на мага', 'руководство на мага' ) );
		$this->assertFalse( $l->same( 'колода мага', 'колода жреца' ) );
		$this->assertTrue( $l->same( 'СТАРТОВАЯ РУКА!', 'мулиган' ) );
		$this->assertSame( $l->normalize( 'елка' ), $l->normalize( 'Ёлка!' ) );
	}
	/** Проверяет классы и границы слов. */
	public function test_classes(): void {
		$l = new Lexicon();
		$this->assertSame( array( 'demon-hunter' ), $l->classes( 'ДХ на возвещении' ) );
		$this->assertSame( array( 'demon-hunter', 'hunter' ), $l->classes( 'охотник на демонов и охотник' ) );
		$this->assertSame( array( 'death-knight' ), $l->classes( 'рыцаря смерти' ) );
		foreach ( array( 'палка', 'варить суп', 'локация', 'мой друг' ) as $text ) {
			$this->assertSame( array(), $l->classes( $text ) );
		}
		$this->assertSame( array( 'mage', 'priest' ), $l->classes( 'маг прист маг' ) );
		$this->assertCount( 11, $l->class_slugs() );
		$this->assertSame( 'Охотник на демонов', $l->class_name( 'demon-hunter' ) );
		$this->assertSame( '', $l->class_name( 'unknown' ) );
	}
	/** Проверяет термины и дополнения владельца. */
	public function test_terms_and_extras(): void {
		$l = new Lexicon( array( 'колода' => array( 'пачка' ) ), array( 'mage' => array( 'волшебник' ) ) );
		$this->assertSame( array( 'mulligan', 'matchup', 'meta' ), $l->terms( 'муллиган и матчапы против меты' ) );
		$this->assertSame( array( 'tier-list', 'battlegrounds' ), $l->terms( 'тир-лист столов' ) );
		$this->assertTrue( $l->same( 'пачка волшебника', 'колода мага' ) );
		$this->assertSame( array( 'mage' ), $l->classes( 'волшебника' ) );
	}
	/** Проверяет разговорные имена классов в косвенных формах и слова, которые на них только похожи. */
	public function test_colloquial_class_names_in_oblique_forms(): void {
		$l = new Lexicon();
		$this->assertSame( array( 'warlock' ), $l->classes( 'против локов' ) );
		$this->assertSame( array( 'shaman' ), $l->classes( 'у шама' ) );
		$this->assertSame( array( 'hunter' ), $l->classes( 'на ханта' ) );
		$this->assertSame( array( 'rogue' ), $l->classes( 'роги' ) );
		$this->assertSame( array( 'paladin', 'warrior', 'priest' ), $l->classes( 'палом против вара и пристов' ) );
		$this->assertSame( array( 'druid', 'death-knight' ), $l->classes( 'Дру и ДК' ) );
		foreach ( array( 'палка', 'пала', 'варить', 'локация', 'друг', 'шампунь', 'магазин', 'рогов', 'локон', 'шамкать', 'хантер' ) as $text ) {
			$this->assertSame( array(), $l->classes( $text ), $text );
		}
		$this->assertTrue( $l->same( 'сборка против локов', 'колода против чернокнижника' ) );
		$this->assertFalse( $l->same( 'пала башня', 'паладин башня' ) );
	}
	/** Проверяет слова для поиска ключа: синонимы заменены каноническими словами, остальные слова остались как есть. */
	public function test_canonical_words(): void {
		$l = new Lexicon();
		$this->assertSame( array( 'колода', 'жрец' ), $l->canonical_words( 'Дека приста' ) );
		$this->assertSame( array( 'лучшие', 'decks', 'рыцарь', 'смерти', '2026' ), $l->canonical_words( 'Лучшие decks ДК 2026' ) );
		$this->assertSame( array( 'муллиган', 'за', 'охотник', 'на', 'демонов' ), $l->canonical_words( 'стартовая рука за дх' ) );
		$this->assertSame( array(), $l->canonical_words( ' — ' ) );
	}
	/** Проверяет разбор поля настроек «Свои синонимы». */
	public function test_custom_synonyms_are_parsed_from_settings_text(): void {
		$parsed = Lexicon::parse_synonyms( "колода = пачка, Лист

  агро=аггро ,, 
без знака равно
 = пусто
колода = стопка
пустые = , " );
		$this->assertSame(
			array(
				'колода'   => array( 'пачка', 'лист', 'стопка' ),
				'агро'   => array( 'аггро' ),
			),
			$parsed
		);
		$l = new Lexicon( $parsed );
		$this->assertTrue( $l->same( 'пачка приста', 'колода жреца' ) );
		$this->assertTrue( $l->same( 'аггро воин', 'агро вар' ) );
		$this->assertSame( array(), Lexicon::parse_synonyms( '' ) );
	}
}
