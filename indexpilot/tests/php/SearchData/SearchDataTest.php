<?php
declare(strict_types=1);
namespace IndexPilot\Tests\SearchData;
use PHPUnit\Framework\TestCase;
use IndexPilot\SearchData\Sync;
final class SearchDataTest extends TestCase {
 public function test_merge_is_idempotent(): void {
  $sync = new Sync();
  $rows = array(array('page'=>'/a','query'=>'test','impressions'=>20));
  $once = $sync->merge(array(),$rows,'google','2026-11');
  self::assertSame($once,$sync->merge($once,$rows,'google','2026-11'));
 }
}
