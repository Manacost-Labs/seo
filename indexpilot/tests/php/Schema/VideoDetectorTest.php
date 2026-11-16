<?php
/** Проверки видео. @package IndexPilot */
declare( strict_types=1 );
namespace IndexPilot\Tests\Schema;
use IndexPilot\Schema\VideoDetector;
use PHPUnit\Framework\TestCase;
final class VideoDetectorTest extends TestCase {
 public function test_forms(): void {
  $groups = array(
   'youtube'=>array('youtube.com/embed/dQw4w9WgXcQ','youtube-nocookie.com/embed/dQw4w9WgXcQ','youtube.com/watch?v=dQw4w9WgXcQ','youtu.be/dQw4w9WgXcQ','youtube.com/shorts/dQw4w9WgXcQ','youtube.com/live/dQw4w9WgXcQ'),
   'vk'=>array('vk.com/video_ext.php?id=456&oid=-123','vk.com/video-123_456','vkvideo.ru/video-123_456'),
   'twitch'=>array('player.twitch.tv/?video=v123','player.twitch.tv/?video=123','twitch.tv/videos/123'),
   'twitch_clip'=>array('clips.twitch.tv/embed?clip=Slug','clips.twitch.tv/Slug','twitch.tv/NAME/clip/Slug')
  );
  foreach ($groups as $provider=>$urls) { foreach ($urls as $url) {
   $result=VideoDetector::detect('<iframe src="https://'.$url.'"></iframe>');
   self::assertCount(1,$result,$url); self::assertSame($provider,$result[0]['provider']);
   self::assertSame($result,VideoDetector::detect('<p>https://'.$url.'</p>'));
   self::assertSame($result,VideoDetector::detect('[embed]https://'.$url.'[/embed]'));
   self::assertSame($result,VideoDetector::detect("https://".$url."\nhttps://".$url));
  } }
  self::assertSame(array(),VideoDetector::detect('<p>смотрите <a href="https://youtu.be/dQw4w9WgXcQ">ролик</a></p>'));
 }
}
