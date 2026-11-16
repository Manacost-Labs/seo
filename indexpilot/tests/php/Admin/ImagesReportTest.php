<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Admin;

use IndexPilot\Admin\Reports\ImagesReport;
use PHPUnit\Framework\TestCase;

final class ImagesReportTest extends TestCase {

	public function test_form_values_become_clean_alts_and_empty_ones_are_skipped(): void {
		self::assertSame(
			array(
				12 => 'Колода жреца — список карт',
				15 => 'Арт героя',
			),
			ImagesReport::alts_from_form(
				array(
					'12'  => "  Колода   жреца — <b>список</b> карт \n",
					'13'  => '   ',
					'0'   => 'нет такой картинки',
					'abc' => 'неверный ID',
					'15'  => 'Арт героя',
					'16'  => array( 'не строка' ),
				)
			)
		);
	}

	public function test_long_alt_is_cut(): void {
		self::assertSame( 250, mb_strlen( ImagesReport::alts_from_form( array( 5 => str_repeat( 'я', 400 ) ) )[5] ) );
		self::assertSame( array(), ImagesReport::alts_from_form( 'мусор' ) );
	}
}
