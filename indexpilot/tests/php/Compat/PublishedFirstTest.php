<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Compat;

use IndexPilot\Compat\PublishedFirst;
use PHPUnit\Framework\TestCase;

final class PublishedFirstTest extends TestCase {

	public function test_published_post_goes_before_newer_draft_with_the_same_address(): void {
		$draft     = (object) array(
			'ID'          => 7335,
			'post_status' => 'draft',
		);
		$published = (object) array(
			'ID'          => 4574,
			'post_status' => 'publish',
		);
		$public    = static fn( object $post ): bool => 'publish' === $post->post_status;

		self::assertSame( array( $published, $draft ), PublishedFirst::order( array( $draft, $published ), $public ) );
		self::assertSame( array( $published, $draft ), PublishedFirst::order( array( $published, $draft ), $public ), 'Порядок уже верный — не меняется.' );
		self::assertSame( array( $draft ), PublishedFirst::order( array( $draft ), $public ), 'Одна запись — не трогаем.' );
		self::assertSame( array( $draft, $draft ), PublishedFirst::order( array( $draft, $draft ), $public ), 'Показывать нечего — WordPress сам отдаст 404.' );
	}
}
