<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Analysis;

use IndexPilot\Analysis\Analyzer;
use IndexPilot\Analysis\Checks\ImageChecks;
use IndexPilot\Analysis\Paper;
use IndexPilot\Analysis\Result;
use PHPUnit\Framework\TestCase;

final class ImageChecksTest extends TestCase {

	/**
	 * @return array<string, Result>
	 */
	private function results( Paper $paper ): array {
		$results = array();

		foreach ( ( new Analyzer( array( new ImageChecks() ) ) )->analyze( $paper )->results as $result ) {
			$results[ $result->id ] = $result;
		}

		return $results;
	}

	public function test_alt_equal_to_file_name_counts_as_empty(): void {
		$results = $this->results( new Paper( content: '<img src="https://example.test/wp-content/uploads/IMG_2031.jpg" alt="IMG_2031.jpg" width="800" height="600">' ) );

		self::assertSame( Result::BAD, $results['image_alt']->status );
		self::assertStringContainsString( 'IMG_2031.jpg', $results['image_alt']->message );
	}

	public function test_image_without_size_gets_a_remark(): void {
		$results = $this->results( new Paper( content: '<img src="/deck.png" alt="Колода жреца: список карт">' ) );

		self::assertSame( Result::GOOD, $results['image_alt']->status );
		self::assertSame( Result::OK, $results['image_size']->status );
		self::assertStringContainsString( 'deck.png', $results['image_size']->message );
	}

	public function test_media_library_image_gets_its_size_from_wordpress(): void {
		// WordPress сам допишет width и height картинке с классом wp-image-ID при выводе.
		$results = $this->results( new Paper( content: '<img class="wp-image-15" src="/deck.png" alt="Колода жреца">' ) );

		self::assertArrayNotHasKey( 'image_size', $results );
	}

	public function test_decorative_image_without_alt_is_fine(): void {
		self::assertSame( array(), $this->results( new Paper( content: '<img class="is-decorative" src="/line.png" alt="" width="10" height="10">' ) ) );
	}

	public function test_deck_screenshot_without_alt_gets_a_remark(): void {
		$results = $this->results( new Paper( content: '<img src="/screenshot-deck.png" width="800" height="600">' ) );

		self::assertSame( Result::BAD, $results['image_alt']->status );
		self::assertSame( 3, $results['image_alt']->max );
	}

	public function test_duplicate_alt_heavy_file_and_small_thumbnail(): void {
		$results = $this->results(
			new Paper(
				content: '<img class="wp-image-7" src="/a.jpg" alt="Колода"><img class="wp-image-8" src="/b.jpg" alt="колода">',
				image_bytes: array(
					7 => 512 * 1024,
					8 => 100 * 1024,
				),
				featured_image: array(
					'width'  => 800,
					'height' => 450,
				)
			)
		);

		self::assertSame( Result::OK, $results['image_alt_duplicate']->status );
		self::assertSame( Result::OK, $results['image_weight']->status );
		self::assertStringContainsString( 'a.jpg', $results['image_weight']->message );
		self::assertStringNotContainsString( 'b.jpg', $results['image_weight']->message );
		self::assertSame( Result::OK, $results['featured_image']->status );
		self::assertStringContainsString( '800 × 450', $results['featured_image']->message );
	}

	public function test_missing_thumbnail_is_reported_once(): void {
		self::assertSame( Result::OK, $this->results( new Paper( featured_image: array(), has_social_image: true ) )['featured_image']->status );
		// Нет ни миниатюры, ни картинки для соцсетей — об этом уже говорит проверка social_image.
		self::assertSame( array(), $this->results( new Paper( featured_image: array(), has_social_image: false ) ) );
		self::assertSame(
			array(),
			$this->results(
				new Paper(
					featured_image: array(
						'width'  => 1200,
						'height' => 630,
					)
				)
			)
		);
	}

	public function test_text_without_images_has_no_remarks(): void {
		self::assertSame( array(), $this->results( new Paper( content: '<p>Колода без картинок.</p>' ) ) );
	}
}
