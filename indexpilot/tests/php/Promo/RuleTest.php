<?php
/** Тесты правил. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Promo;
use IndexPilot\Promo\Rule;
use PHPUnit\Framework\TestCase;
final class RuleTest extends TestCase {
 public function test_rules(): void {
  $base = array( 'destination'=>'a', 'enabled'=>true, 'variants'=>array(array('text'=>' Hi ')) );
  $rules = Rule::sanitize_all(array($base, $base + array('id'=>8), $base + array('id'=>8), array('destination'=>'bad'), array('destination'=>'a')), array('a'));
  self::assertCount(3,$rules);
  self::assertSame(array(9,8,10),array_column($rules,'id'));
  $facts=array('post_type'=>'post','terms'=>array('category'=>array(2)), 'game'=>'hs','material'=>'guide');
  $rule=$rules[0];
  self::assertTrue(Rule::matches($rule,$facts));
  foreach(array('post_types'=>array('page'),'terms'=>array('category'=>array(3)),'game'=>'other','material'=>'news','enabled'=>false) as $key=>$value) {
   self::assertFalse(Rule::matches(array_replace($rule,array($key=>$value)),$facts));
  }
  self::assertTrue(Rule::matches(array_replace($rule,array('terms'=>array('category'=>array(2,3)))),$facts));
  self::assertCount(2,Rule::select($rules,$facts));
  $rule['variants']=array(array('text'=>'a'),array('text'=>'b'));
  self::assertSame(1,Rule::variant($rule,4,1));
  self::assertSame(0,Rule::variant($rule,4,2));
  self::assertSame(Rule::variant($rule,4,1),Rule::variant($rule,4,1));
 }
}
