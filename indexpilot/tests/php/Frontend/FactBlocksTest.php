<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Frontend;

use IndexPilot\Frontend\FactBlocks;
use PHPUnit\Framework\TestCase;

final class FactBlocksTest extends TestCase {

	private const FULL = array(
		'value'  => '54 %',
		'label'  => 'побед',
		'source' => 'HSReplay',
		'url'    => 'https://hsreplay.net/decks/',
		'date'   => '15.11.2026',
		'sample' => '312 игр',
	);

	public function test_fact_without_date_or_source_is_marked_for_the_editor_and_is_not_complete(): void {
		$no_date   = array( 'date' => '' ) + self::FULL;
		$no_source = array( 'source' => '' ) + self::FULL;

		self::assertTrue( FactBlocks::is_complete( self::FULL ) );
		self::assertFalse( FactBlocks::is_complete( $no_date ) );
		self::assertFalse( FactBlocks::is_complete( $no_source ) );

		// Блок выводится в любом случае, пометка — только редактору.
		self::assertStringContainsString( '54 %', FactBlocks::stat_html( $no_date, false ) );
		self::assertStringNotContainsString( 'indexpilot-editor-note', FactBlocks::stat_html( $no_date, false ) );
		self::assertStringContainsString( 'indexpilot-editor-note', FactBlocks::stat_html( $no_date, true ) );
		self::assertStringContainsString( 'indexpilot-editor-note', FactBlocks::stat_html( $no_source, true ) );
		self::assertStringNotContainsString( 'indexpilot-editor-note', FactBlocks::stat_html( self::FULL, true ) );
	}

	public function test_complete_fact_shows_value_label_sample_source_and_date(): void {
		$html = FactBlocks::stat_html( self::FULL, false );

		self::assertStringContainsString( 'class="indexpilot-stat"', $html );
		self::assertStringContainsString( '54 %', $html );
		self::assertStringContainsString( 'побед', $html );
		self::assertStringContainsString( '312 игр', $html );
		self::assertStringContainsString( '<a href="https://hsreplay.net/decks/" rel="noopener">HSReplay</a>', $html );
		self::assertStringContainsString( '<time datetime="2026-11-15">15.11.2026</time>', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertSame( '', FactBlocks::stat_html( array( 'label' => 'побед' ), true ) );
	}

	public function test_values_are_escaped_and_unsafe_addresses_are_dropped(): void {
		$html = FactBlocks::stat_html(
			array(
				'value'  => '<b>54</b>',
				'source' => 'HS"Replay',
				'url'    => 'javascript:alert(1)',
				'date'   => 'вчера',
			),
			false
		);

		self::assertStringContainsString( '&lt;b&gt;54&lt;/b&gt;', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
		self::assertStringNotContainsString( '<a ', $html );
		self::assertStringNotContainsString( '<time', $html );
		self::assertStringContainsString( 'вчера', $html );
	}

	public function test_facts_and_their_sources_are_read_from_the_post_text(): void {
		$content = '<p>Текст.</p>[indexpilot_stat value="54 %" label="побед" source="HSReplay" url="https://hsreplay.net/decks/" date="15.11.2026" sample="312 игр"]'
			. "[indexpilot_stat value='61 %' source='Vicious Syndicate' url='https://www.vicioussyndicate.com/' date='2026-11-10']"
			. '[indexpilot_stat value="48 %" source="HSReplay" url="https://hsreplay.net/decks/"]'
			. '[indexpilot_stat value="12" label="карт" source="Свои игры" date="01.11.2026"]'
			. '[indexpilot_quote name="Сильвернейм" role="стример"]Темп решает.[/indexpilot_quote]';

		$stats = FactBlocks::stats( $content );

		self::assertCount( 4, $stats );
		self::assertSame( '54 %', $stats[0]['value'] );
		self::assertSame( 'Vicious Syndicate', $stats[1]['source'] );
		self::assertSame( 3, FactBlocks::complete_count( $content ) );

		// В цитируемые источники попадают только источники с адресом, без повторов.
		self::assertSame(
			array(
				array(
					'name' => 'HSReplay',
					'url'  => 'https://hsreplay.net/decks/',
				),
				array(
					'name' => 'Vicious Syndicate',
					'url'  => 'https://www.vicioussyndicate.com/',
				),
			),
			FactBlocks::citations( $content )
		);
	}

	public function test_quote_shows_words_name_and_role(): void {
		$html = FactBlocks::quote_html( "</p>\nТемп <em>решает</em>.\nВсегда.<p>", 'Сильвернейм', 'стример' );

		self::assertStringContainsString( 'class="indexpilot-quote"', $html );
		self::assertStringContainsString( '<blockquote', $html );
		self::assertStringContainsString( 'Темп решает.', $html );
		self::assertStringContainsString( '<cite style="font-style:normal;font-weight:600">Сильвернейм</cite>, стример', $html );
		self::assertSame( '', FactBlocks::quote_html( '  ', 'Сильвернейм', 'стример' ) );
		self::assertStringNotContainsString( '<figcaption', FactBlocks::quote_html( 'Слова.', '', '' ) );
	}

	public function test_dates_in_russian_and_iso_form_are_recognised(): void {
		self::assertSame( '2026-11-15', FactBlocks::iso_date( '15.11.2026' ) );
		self::assertSame( '2026-11-05', FactBlocks::iso_date( '2026-11-05' ) );
		self::assertSame( '', FactBlocks::iso_date( '31.02.2026' ) );
		self::assertSame( '', FactBlocks::iso_date( 'ноябрь' ) );
	}
}
