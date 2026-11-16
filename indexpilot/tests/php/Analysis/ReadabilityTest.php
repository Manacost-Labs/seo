<?php
/** Проверки удобства чтения. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Readability;
use PHPUnit\Framework\TestCase;
/** Проверяет метрики текста. */
final class ReadabilityTest extends TestCase {
	/** Проверяет обязательные сценарии. */
	public function test_contract(): void {
		$long = Readability::measure( str_repeat( trim( str_repeat( 'слово ', 40 ) ) . '. ', 3 ) );
		$this->assertSame( 3, $long['sentences'] );
		$this->assertContains( 'long_sentences', Readability::issues( $long ) );
		$this->assertSame( array(), Readability::issues( Readability::measure( '<p>Короткий текст.</p><ul><li>Первый пункт.</li></ul><p>Ещё абзац.</p>' ) ) );
		$this->assertContains( 'filler', Readability::issues( Readability::measure( 'Является данный таким образом.' ) ) );
		$this->assertSame( 1, Readability::measure( '54.5 % побед и т. д. в патче' )['sentences'] );
		$issues = Readability::issues( Readability::measure( str_repeat( 'слово ', 400 ) ) );
		$this->assertContains( 'no_subheadings', $issues );
		$this->assertContains( 'no_lists', $issues );
		$this->assertSame( 0, Readability::measure( '' )['score'] );
		$this->assertSame( array(), Readability::issues( Readability::measure( '' ) ) );
		$this->assertSame( 1, Readability::measure( '<h2>Заголовок.</h2><ul><li>Пункт!</li></ul><table><tr><td>Ячейка?</td></tr></table><p>Текст.</p>' )['sentences'] );
		$this->assertSame( 0, Readability::measure( '<script>слово</script><style>слово</style>[card name="слово"]' )['words'] );
		$this->assertSame( array( 'данный', 'является' ), Readability::measure( 'Данный является данный.' )['filler'] );
	}
}
