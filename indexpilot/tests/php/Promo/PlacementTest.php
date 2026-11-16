<?php
/** Тесты размещения. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Promo;
use IndexPilot\Promo\Placement;
use PHPUnit\Framework\TestCase;
final class PlacementTest extends TestCase {
 public function test_sections(): void {
  $content='intro';
  for($i=1;$i<=6;++$i){$content.='<h2>'.$i.'</h2><p>x</p>';}
  self::assertSame(str_replace('<h2>4','BLOCK<h2>4',$content),Placement::insert($content,array(array('place'=>'middle','html'=>'BLOCK'))));
  self::assertSame(str_replace('<h2>2','BLOCK<h2>2',$content),Placement::insert($content,array(array('place'=>'after_first','html'=>'BLOCK'))));
  self::assertSame($content.'AB',Placement::insert($content,array(array('place'=>'end','html'=>''),array('place'=>'end','html'=>'A'),array('place'=>'end','html'=>'B'),array('place'=>'end','html'=>'C'))));
 }
 public function test_paragraphs(): void {
  $content='<p>a</p>\n<p>b</p><p>c</p><p>d</p>';
  self::assertSame('<p>a</p>A\n<p>b</p>B<p>c</p><p>d</p>',Placement::insert($content,array(array('place'=>'after_first','html'=>'A'),array('place'=>'middle','html'=>'B'))));
  self::assertSame('plainAB',Placement::insert('plain',array(array('place'=>'middle','html'=>'A'),array('place'=>'after_first','html'=>'B'))));
 }
}
