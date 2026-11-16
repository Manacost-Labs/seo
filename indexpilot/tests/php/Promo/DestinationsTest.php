<?php

declare( strict_types=1 );
namespace IndexPilot\Tests\Promo;
use IndexPilot\Promo\Destinations;
use PHPUnit\Framework\TestCase;
final class DestinationsTest extends TestCase {
 public function test_missing_url_and_duplicate_codes(): void {
  $rows = Destinations::sanitize( array( array( 'name' => 'Empty' ), array( 'name' => 'Boosty', 'url' => 'https://boosty.to/a' ), array( 'name' => 'Boosty', 'url' => 'https://boosty.to/b' ) ) );
  self::assertCount( 2, $rows );
  self::assertNotSame( $rows[0]['code'], $rows[1]['code'] );
 }
}
