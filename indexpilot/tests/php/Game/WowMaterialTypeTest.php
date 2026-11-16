<?php
/** Проверки игрового контракта.
 * @package IndexPilot
 */
declare( strict_types=1 );
namespace IndexPilot\Tests\Game;

use PHPUnit\Framework\TestCase;
/** Проверяет контракт. */
final class WowMaterialTypeTest extends TestCase {
	/** Проверяет контракт. */
	public function test_contract(): void {
		$type = \IndexPilot\Game\WowMaterialType::detect( 'Гайд шп', array(), '' );
		$this->assertSame( 'guide', $type );
		$this->assertContains( 'Ротация', \IndexPilot\Game\WowMaterialType::missing_sections( $type, '<h2>Таланты</h2>' ) );
	}
	/** Проверяет разделы и приоритет типов. */
	public function test_sections(): void {
		$html = '<h2>Таланты</h2><h2>Характеристики</h2><h2>Экипировка</h2><h3>Чары и камни</h3><h3>Аддоны и макросы</h3><p>Ротация</p>';
		$this->assertSame( array( 'Ротация' ), \IndexPilot\Game\WowMaterialType::missing_sections( 'guide', $html ) );
		$this->assertSame( array(), \IndexPilot\Game\WowMaterialType::missing_sections( 'guide', $html . '<h2>Ротация</h2>' ) );
		foreach ( array(
			'Патч мага'              => 'patch',
			'Новости'                => 'news',
			'Тактика босса'          => 'boss',
			'Маршрут подземелья'     => 'dungeon',
			'Профессии'              => 'professions',
			'Прокачка'               => 'levelling',
			'Золото'                 => 'gold',
			'Тир-лист специализаций' => 'tier_list',
			'Об игре'                => 'other',
		) as $title => $type ) {
			$this->assertSame( $type, \IndexPilot\Game\WowMaterialType::detect( $title, array(), '' ) );
		}
	}
}
