<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Overview;

use IndexPilot\Overview\TaskList;
use PHPUnit\Framework\TestCase;

final class TaskListTest extends TestCase {

	public function test_five_most_important_tasks_are_picked(): void {
		$tasks = TaskList::pick(
			array(
				'stale'                  => 900,
				'auto_description'       => 4000,
				'no_description'         => 12,
				'orphans'                => 250,
				'broken_links'           => 3,
				'redirect_chains'        => 7,
				'duplicate_titles'       => 40,
				'thin'                   => 0,
				'duplicate_descriptions' => 15,
			)
		);

		self::assertSame(
			array( 'broken_links', 'duplicate_titles', 'no_description', 'redirect_chains', 'orphans' ),
			array_column( $tasks, 'key' ),
			'Порядок задаёт важность дела, а не число записей.'
		);
		self::assertSame( array( 3, 40, 12, 7, 250 ), array_column( $tasks, 'count' ) );
	}

	public function test_missing_data_comes_first(): void {
		$tasks = TaskList::pick(
			array(
				'no_description' => 5,
				'scan_links'     => 1,
				'run_audit'      => 1,
			)
		);

		self::assertSame( array( 'run_audit', 'scan_links', 'no_description' ), array_column( $tasks, 'key' ) );
	}

	public function test_nothing_to_do(): void {
		self::assertSame( array(), TaskList::pick( array() ) );
		self::assertSame(
			array(),
			TaskList::pick(
				array(
					'thin'    => 0,
					'h1'      => -2,
					'unknown' => 10,
				)
			),
			'Нулевые и неизвестные дела в список не попадают.'
		);
	}

	public function test_limit_can_be_changed(): void {
		$counts = array_fill_keys( TaskList::KEYS, 1 );

		self::assertCount( TaskList::LIMIT, TaskList::pick( $counts ) );
		self::assertSame( 5, TaskList::LIMIT );
		self::assertCount( 2, TaskList::pick( $counts, 2 ) );
		self::assertCount( count( TaskList::KEYS ), TaskList::pick( $counts, 100 ) );
	}
}
