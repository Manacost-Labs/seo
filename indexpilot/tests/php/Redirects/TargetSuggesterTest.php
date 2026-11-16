<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Redirects;

use IndexPilot\Redirects\TargetSuggester;
use PHPUnit\Framework\TestCase;

final class TargetSuggesterTest extends TestCase {

	private const CANDIDATES = array(
		'post:1' => 'koloda-zhreca-na-voskreshenii',
		'post:2' => 'gajd-po-arene',
		'term:5' => 'gajdy',
		'post:3' => 'meta-otchet-noyabr',
	);

	public function test_similar_slug_is_suggested(): void {
		self::assertSame( 'post:1', TargetSuggester::suggest( '/2024/05/koloda-zhreca-voskreshenie/', self::CANDIDATES ) );
		self::assertSame( 'term:5', TargetSuggester::suggest( '/category/gajd/', self::CANDIDATES ) );
	}

	public function test_truncated_address_finds_the_full_slug(): void {
		self::assertSame( 'post:3', TargetSuggester::suggest( '/meta-otchet', self::CANDIDATES ) );
	}

	public function test_nothing_similar_gives_empty_suggestion(): void {
		self::assertSame( '', TargetSuggester::suggest( '/kontakty/', self::CANDIDATES ) );
		self::assertSame( '', TargetSuggester::suggest( '/', self::CANDIDATES ) );
	}
}
