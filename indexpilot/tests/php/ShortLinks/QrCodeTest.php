<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\ShortLinks;

use IndexPilot\ShortLinks\QrCode;
use PHPUnit\Framework\TestCase;

/**
 * Эталон (fixtures/qr-reference.json) получен независимо: библиотекой Python qrcode 8.x,
 * режим байтов, коррекция M, рамка 0, маска выбрана самой библиотекой.
 */
final class QrCodeTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: int, 2: int, 3: list<string>}>
	 */
	public static function references(): array {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/qr-reference.json' ), true );
		$out  = array();

		foreach ( $data as $name => $item ) {
			$out[ $name ] = array( $item['text'], $item['version'], $item['mask'], $item['rows'] );
		}

		return $out;
	}

	/**
	 * @dataProvider references
	 *
	 * @param string       $text    Строка.
	 * @param int          $version Версия эталона.
	 * @param int          $mask    Маска эталона.
	 * @param list<string> $rows    Строки модулей эталона.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'references' )]
	public function test_matrix_matches_reference( string $text, int $version, int $mask, array $rows ): void {
		$qr = QrCode::encode( $text );

		self::assertSame( $version, $qr->version() );
		self::assertSame( $mask, $qr->mask() );
		self::assertSame( $rows, $qr->rows() );
	}

	public function test_svg_has_quiet_zone_and_only_paths(): void {
		$svg = QrCode::encode( 'https://hs-manacost.ru/go/pzz' )->svg();

		self::assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg"', $svg );
		// Версия 3 — 29 модулей, плюс рамка по 4 модуля с каждой стороны.
		self::assertStringContainsString( 'viewBox="0 0 37 37"', $svg );
		self::assertStringNotContainsString( '<script', $svg );
	}

	public function test_too_long_text_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		QrCode::encode( str_repeat( 'x', 300 ) );
	}
}
