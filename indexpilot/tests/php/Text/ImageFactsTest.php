<?php
/** Проверки изображений. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Text;
use IndexPilot\Text\ImageFacts;
use PHPUnit\Framework\TestCase;
final class ImageFactsTest extends TestCase {
 public function test_facts(): void {
  $images = ImageFacts::extract('<img class="wp-image-57" src="/uploads/IMG_2031.jpg?x=1" alt="IMG_2031.jpg"><img src="/uploads/IMG_2031.jpg" alt="img-2031"><img class="is-decorative" alt=""><img alt="Колода жреца" width="20" height="30"><img alt=" колода ЖРЕЦА " width="20" height="30">');
  self::assertSame(57, $images[0]['attachment_id']);
  self::assertSame('IMG_2031.jpg', $images[0]['filename']);
  $issues = ImageFacts::issues($images);
  foreach (array(0,1) as $i) { self::assertContains(array('index'=>$i,'src'=>$images[$i]['src'],'issue'=>ImageFacts::ALT_IS_FILENAME),$issues); }
  self::assertContains(array('index'=>0,'src'=>$images[0]['src'],'issue'=>ImageFacts::NO_SIZE),$issues);
  self::assertSame(array(ImageFacts::NO_SIZE),array_column(array_values(array_filter($issues,static fn($v)=>2 === $v['index'])),'issue'));
  self::assertContains(array('index'=>4,'src'=>'','issue'=>ImageFacts::DUPLICATE_ALT),$issues);
  $separator = ImageFacts::issues(ImageFacts::extract(str_repeat('<img src="/sep.png" alt="Разделитель" width="9" height="9">',3).'<img src="/other.png" alt="разделитель" width="9" height="9">'));
  self::assertSame(array(3),array_column($separator,'index'),'Одна картинка три раза — не повтор; другая с тем же alt — повтор.');
 }
}
