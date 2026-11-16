<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Ai;

use IndexPilot\Ai\Questions;
use IndexPilot\Ai\Visits;
use PHPUnit\Framework\TestCase;

final class VisitsTest extends TestCase {

	public function test_ten_searchbot_visits_to_one_page_give_one_row_with_ten(): void {
		$buffer = array();
		$agent  = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot';

		for ( $i = 0; $i < 10; $i++ ) {
			$source = Visits::source( $agent, '' );
			$buffer = Visits::add( $buffer, '2026-11-16', Visits::path( '/gajd-4/?utm_source=x' ), $source['kind'], $source['agent'], 1000 + $i );
		}

		$rows = Visits::rows( $buffer );

		self::assertCount( 1, $rows );
		self::assertSame(
			array(
				'day'   => '2026-11-16',
				'path'  => '/gajd-4/',
				'kind'  => Visits::BOT,
				'agent' => 'OAI-SearchBot',
				'hits'  => 10,
			),
			$rows[0]
		);
	}

	public function test_bots_and_assistant_referrals_are_told_apart(): void {
		self::assertSame( 'ChatGPT-User', Visits::source( 'Mozilla/5.0; compatible; ChatGPT-User/1.0', '' )['agent'] );
		self::assertSame( 'GPTBot', Visits::source( 'Mozilla/5.0; compatible; GPTBot/1.2', '' )['agent'] );
		self::assertSame( 'PerplexityBot', Visits::source( 'PerplexityBot/1.0', '' )['agent'] );
		self::assertSame( 'Claude-SearchBot', Visits::source( 'Claude-SearchBot/1.0', '' )['agent'] );

		$human = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

		self::assertSame(
			array(
				'kind'  => Visits::REFERRAL,
				'agent' => 'ChatGPT',
			),
			Visits::source( $human, 'chatgpt.com' )
		);
		self::assertSame( 'Perplexity', Visits::source( $human, 'www.perplexity.ai' )['agent'] );
		self::assertSame( 'Алиса', Visits::source( $human, 'ya.ru' )['agent'] );
		self::assertNull( Visits::source( $human, 'google.com' ) );
		self::assertNull( Visits::source( $human, '' ) );
		// Робот с поддельным переходом из ассистента не считается переходом человека.
		self::assertNull( Visits::source( 'curl/8.0', 'chatgpt.com' ) );
	}

	public function test_buffer_is_written_in_batches(): void {
		$buffer = Visits::add( array(), '2026-11-16', '/a/', Visits::BOT, 'GPTBot', 1000 );

		self::assertFalse( Visits::due( $buffer, 1000 + 60 ) );
		self::assertTrue( Visits::due( $buffer, 1000 + Visits::INTERVAL ) );
		self::assertFalse( Visits::due( array(), 99999 ) );
	}

	public function test_buffer_does_not_grow_without_limit(): void {
		$buffer = array();

		for ( $i = 0; $i < Visits::MAX_KEYS + 50; $i++ ) {
			$buffer = Visits::add( $buffer, '2026-11-16', '/p' . $i . '/', Visits::BOT, 'GPTBot', 1000 );
		}

		self::assertCount( Visits::MAX_KEYS, Visits::rows( $buffer ) );
		self::assertTrue( Visits::due( $buffer, 1001 ) );
	}

	public function test_path_is_cut_and_has_no_query(): void {
		self::assertSame( '/', Visits::path( '' ) );
		self::assertSame( '/llms.txt', Visits::path( '/llms.txt?x=1#y' ) );
		self::assertSame( 190, strlen( Visits::path( '/' . str_repeat( 'a', 400 ) ) ) );
	}

	public function test_twenty_default_questions_can_be_edited(): void {
		$defaults = Questions::defaults();

		self::assertCount( 20, $defaults );
		self::assertSame( 'лучшая колода жреца hearthstone', $defaults[0]['question'] );

		$saved = Questions::sanitize(
			array(
				array(
					'question' => '  своя формулировка  ',
					'status'   => 'yes',
					'who'      => '',
					'checked'  => '2026-11-16',
				),
				array(
					'question' => 'второй',
					'status'   => 'взлом',
					'who'      => 'hearthpwn.com, <b>x</b>',
					'checked'  => 'вчера',
				),
				array( 'question' => '' ),
			)
		);

		self::assertCount( 2, $saved );
		self::assertSame( 'своя формулировка', $saved[0]['question'] );
		self::assertSame( Questions::CITED, $saved[0]['status'] );
		self::assertSame( '', $saved[1]['status'] );
		self::assertSame( 'hearthpwn.com, x', $saved[1]['who'] );
		self::assertSame( '', $saved[1]['checked'] );
	}
}
