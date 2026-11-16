<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Bulk;

use IndexPilot\Bulk\BatchSaver;
use PHPUnit\Framework\TestCase;

final class BatchSaverTest extends TestCase {

	public function test_row_without_rights_is_rejected_and_the_rest_is_saved(): void {
		$written = array();
		$saver   = new BatchSaver(
			static fn( int $id ): bool => 2 !== $id,
			static function ( int $id, array $fields ) use ( &$written ): void {
				$written[ $id ] = $fields;
			}
		);

		$results = $saver->save(
			array(
				array( 'id' => 1, 'seo_title' => 'Заголовок один', 'seo_description' => 'Описание один' ),
				array( 'id' => 2, 'seo_title' => 'Чужая запись' ),
				array( 'id' => 3, 'seo_description' => 'Только описание', 'post_status' => 'trash' ),
				array( 'id' => 0, 'seo_title' => 'Без записи' ),
				'не массив',
			)
		);

		self::assertSame(
			array(
				1 => array( 'seo_title' => 'Заголовок один', 'seo_description' => 'Описание один' ),
				3 => array( 'seo_description' => 'Только описание' ),
			),
			$written,
			'Сохраняются только разрешённые поля разрешённых записей.'
		);
		self::assertSame(
			array(
				array( 'id' => 1, 'saved' => true ),
				array( 'id' => 2, 'saved' => false ),
				array( 'id' => 3, 'saved' => true ),
			),
			$results
		);
	}

	public function test_batch_is_limited(): void {
		$count = 0;
		$saver = new BatchSaver(
			static fn(): bool => true,
			static function () use ( &$count ): void {
				++$count;
			}
		);

		$items = array();
		for ( $i = 1; $i <= BatchSaver::MAX_ITEMS + 10; $i++ ) {
			$items[] = array( 'id' => $i, 'seo_title' => 'Заголовок ' . $i );
		}

		self::assertCount( BatchSaver::MAX_ITEMS, $saver->save( $items ) );
		self::assertSame( BatchSaver::MAX_ITEMS, $count );
	}
}
