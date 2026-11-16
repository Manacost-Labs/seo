<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Redirects;

use IndexPilot\Redirects\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase {

	public function test_export_and_import_give_the_same_rules(): void {
		$rules = array(
			array(
				'source'  => '^/old/(.*)',
				'target'  => '/new/$1',
				'code'    => 302,
				'regex'   => true,
				'enabled' => false,
				'note'    => 'перенос, "весна"',
				'hits'    => 5,
			),
		);

		$parsed = Csv::parse( Csv::build( $rules ) );

		self::assertSame( '^/old/(.*)', $parsed[0]['source'] );
		self::assertSame( '/new/$1', $parsed[0]['target'] );
		self::assertSame( 302, $parsed[0]['code'] );
		self::assertTrue( $parsed[0]['regex'] );
		self::assertFalse( $parsed[0]['enabled'] );
		self::assertSame( 'перенос, "весна"', $parsed[0]['note'] );
	}

	public function test_file_without_header_is_read_by_column_order(): void {
		$parsed = Csv::parse( "\xEF\xBB\xBF/a/,/b/\n\n/c/,/d/,410\n" );

		self::assertCount( 2, $parsed );
		self::assertSame( 301, $parsed[0]['code'] );
		self::assertTrue( $parsed[0]['enabled'] );
		self::assertSame( 410, $parsed[1]['code'] );
	}

	public function test_formula_like_value_is_escaped(): void {
		self::assertStringContainsString(
			"'=HYPERLINK",
			Csv::build(
				array(
					array(
						'source' => '/a/',
						'note'   => '=HYPERLINK("x")',
					),
				)
			)
		);
	}
}
