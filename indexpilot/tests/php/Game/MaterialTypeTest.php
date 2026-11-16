<?php
/** Проверки типов материалов. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Game;
use IndexPilot\Game\MaterialType;
use PHPUnit\Framework\TestCase;
/** Проверяет публичный контракт. */
final class MaterialTypeTest extends TestCase {
	/** Проверяет распознавание и разделы. */
	public function test_contract(): void {
		$this->assertSame( 'guide', MaterialType::detect( '', array(), '<h2>Муллиган</h2><h3>Матчапы</h3>' ) );
		$this->assertSame( array( 'Замены' ), MaterialType::missing_sections( 'guide', '<h2>Состав колоды</h2><h3>Муллиган</h3><h4>Стратегия</h4><h2>Матчапы</h2>' ) );
		$this->assertSame( 'tier_list', MaterialType::detect( 'Тир-лист колод ноября', array(), '' ) );
		$this->assertSame( 'deck', MaterialType::detect( '', array(), '', 'hs_deck' ) );
		$this->assertSame( 'news', MaterialType::detect( '', array( 'Новости' ), '' ) );
		$this->assertSame( 'other', MaterialType::detect( 'Обычный текст', array(), 'Простой текст' ) );
		foreach ( MaterialType::all() as $type ) {
			$this->assertNotSame( '', MaterialType::label( $type ) );
		}
		$this->assertSame( array( 'Муллиган' ), MaterialType::missing_sections( 'deck', 'AAE' . str_repeat( 'A', 40 ) ) );
	}
}
