<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Resolver;

use IndexPilot\Frontend\SchemaOutput;
use IndexPilot\Game\Deck;
use IndexPilot\Game\DeckSchema;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Resolver\PaginationRules;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Resolver\ThinArchiveRule;
use IndexPilot\Robots\RobotsTxt;
use IndexPilot\Schema\Graph;
use IndexPilot\Settings\Settings;
use IndexPilot\Sitemap\EntryPolicy;
use IndexPilot\Tests\Support\ArrayDataSource;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Правки по сверке с принципами SEO (docs/SEO-PRINCIPLES.md, раздел «Несоответствия»).
 */
final class PrinciplesTest extends TestCase {

	/**
	 * @param array<string, mixed> $saved
	 */
	private function resolver( ArrayDataSource $data, array $saved = array() ): MetaResolver {
		$registry = new VariableRegistry();
		$registry->register( 'title', static fn(): string => 'Колода мага' );
		$registry->register( 'sitename', static fn(): string => 'Manacost' );
		$registry->register( 'tagline', static fn(): string => 'Всё о Hearthstone' );
		$registry->register( 'page', static fn( Context $c ): string => $c->page > 1 ? 'страница ' . $c->page : '' );

		return new MetaResolver( new Settings( $saved ), new Renderer( $registry, '—' ), $data );
	}

	public function test_unknown_variable_never_reaches_the_site_but_is_named_for_the_editor(): void {
		$data     = new ArrayDataSource( array( MetaKeys::TITLE => '%title% %sep% %sitedesc% %sep% %sitename%' ) );
		$saved    = array( 'post_types' => array( 'post' => array( 'description' => 'Гайд: %title%. %oops%' ) ) );
		$resolver = $this->resolver( $data, $saved );

		$site    = $resolver->resolve( new Context( Context::POST, 7, 'post' ) );
		$preview = $resolver->resolve( new Context( Context::POST, 7, 'post', 1, array(), true ) );

		self::assertSame( 'Колода мага — Manacost', $site->title );
		self::assertSame( 'Гайд: Колода мага.', $site->description );
		self::assertSame( 'Колода мага — %sitedesc% — Manacost', $preview->title );
		self::assertStringContainsString( '%oops%', $preview->description );
		self::assertSame( array( 'sitedesc', 'oops' ), $resolver->unknown_variables( new Context( Context::POST, 7, 'post' ) ) );

		// Несохранённый заголовок из редактора: шаблон типа записи уже не участвует.
		$typed = new Context( Context::POST, 7, 'post', 1, array( 'seo_title' => '%title% %typo%', 'seo_description' => 'Описание.' ), true );

		self::assertSame( array( 'typo' ), $resolver->unknown_variables( $typed ) );
		self::assertSame( array(), $this->resolver( new ArrayDataSource() )->unknown_variables( new Context( Context::POST, 7, 'post' ) ) );
	}

	public function test_modified_date_is_never_earlier_than_the_publication_date(): void {
		// Запись поставили в план в 10:00, а вышла она в 18:00: время правки раньше времени публикации.
		self::assertSame( 1_800_028_800, PostDates::effective( 1_800_028_800, 1_800_000_000 ) );
		self::assertSame( 1_800_100_000, PostDates::effective( 1_800_028_800, 1_800_100_000 ) );
		self::assertSame( 1_800_028_800, PostDates::effective( 1_800_028_800, 1_800_028_800 ) );
		self::assertSame( 1_800_000_000, PostDates::effective( 0, 1_800_000_000 ), 'Неизвестная дата публикации ничего не меняет.' );
	}

	public function test_missing_or_empty_author_archive_is_closed_and_has_no_canonical(): void {
		self::assertTrue( ThinArchiveRule::is_thin( new Context( Context::AUTHOR, 0 ), 5, 3 ), 'Автора нет, хотя записи без автора на сайте есть.' );
		self::assertTrue( ThinArchiveRule::is_thin( new Context( Context::AUTHOR, 7 ), 0, 3 ) );
		self::assertFalse( ThinArchiveRule::is_thin( new Context( Context::AUTHOR, 7 ), 2, 3 ) );

		$nobody = $this->resolver( new ArrayDataSource( array(), array(), 'https://example.test/author/', false, 4 ) )->resolve( new Context( Context::AUTHOR, 0 ) );
		$empty  = $this->resolver( new ArrayDataSource( array(), array(), 'https://example.test/author/novichok/', false, 0 ) )->resolve( new Context( Context::AUTHOR, 7 ) );
		$writer = $this->resolver( new ArrayDataSource( array(), array(), 'https://example.test/author/admin/', false, 4 ) )->resolve( new Context( Context::AUTHOR, 7 ) );

		self::assertSame( array( 'noindex', 'follow' ), $nobody->robots );
		self::assertSame( '', $nobody->canonical );
		self::assertSame( array( 'noindex', 'follow' ), $empty->robots );
		self::assertSame( '', $empty->canonical );
		self::assertTrue( $writer->is_indexable() );
		self::assertSame( 'https://example.test/author/admin/', $writer->canonical );

		$indexing = $this->resolver( new ArrayDataSource( array(), array(), 'https://example.test/author/', false, 4 ) )->indexing( new Context( Context::AUTHOR, 0 ) );

		self::assertSame(
			array(
				'indexable' => false,
				'canonical' => '',
			),
			$indexing
		);
	}

	public function test_page_number_is_added_to_the_description_after_a_full_stop(): void {
		$second = new Context( Context::HOME, 0, '', 2 );

		self::assertSame( 'Всё о Hearthstone. Страница 2.', PaginationRules::description( 'Всё о Hearthstone', $second, 'страница %d' ) );
		self::assertSame( 'Все гайды по Hearthstone. Страница 2.', PaginationRules::description( 'Все гайды по Hearthstone.', $second, 'страница %d' ) );
		self::assertSame( 'Что брать в легенду? Страница 2.', PaginationRules::description( 'Что брать в легенду? ', $second, 'страница %d' ) );
		self::assertSame( 'Гайды и колоды… Страница 2.', PaginationRules::description( 'Гайды и колоды…', $second, 'страница %d' ) );
		self::assertSame( 'Всё о Hearthstone', PaginationRules::description( 'Всё о Hearthstone', new Context( Context::HOME ), 'страница %d' ) );
		self::assertSame( '', PaginationRules::description( '', $second, 'страница %d' ) );
	}

	public function test_deck_date_carries_the_time_zone(): void {
		$deck = new Deck( 5, 'Blood DK', 'Рыцарь смерти', 'Стандарт', 'Blood DK', 9400, '', '', false, 'blood dk', '2026-11-15 10:00:00' );

		foreach ( array( array( '+03:00', '2026-11-15T10:00:00+03:00' ), array( '', '2026-11-15T10:00:00+00:00' ) ) as $case ) {
			$graph = new Graph();
			$graph->add(
				array(
					'@type' => 'WebPage',
					'@id'   => 'https://site.test/deck/blood-dk/#webpage',
					'url'   => 'https://site.test/deck/blood-dk/',
				)
			);

			if ( '' === $case[0] ) {
				DeckSchema::add_deck( $graph, 'https://site.test/', 'https://site.test/deck/blood-dk/', $deck );
			} else {
				DeckSchema::add_deck( $graph, 'https://site.test/', 'https://site.test/deck/blood-dk/', $deck, $case[0] );
			}

			self::assertSame( $case[1], $graph->first( 'CreativeWork' )['datePublished'] );
		}
	}

	public function test_yandex_click_id_is_declared_insignificant(): void {
		self::assertContains( 'ysclid', explode( '&', RobotsTxt::CLEAN_PARAM ) );
		self::assertContains( 'yclid', explode( '&', RobotsTxt::CLEAN_PARAM ) );
	}

	public function test_empty_json_gives_no_script_block(): void {
		$broken = new Graph();
		$broken->add(
			array(
				'@type' => 'WebPage',
				'@id'   => 'https://site.test/#webpage',
				'name'  => "Битая строка \xB1\x31",
			)
		);

		self::assertSame( '', $broken->to_json(), 'Строка с неверной кодировкой в JSON не кодируется.' );
		self::assertSame( '', SchemaOutput::script( $broken->to_json() ) );
		self::assertSame( '', SchemaOutput::script( '' ) );
		self::assertSame( '<script type="application/ld+json" class="indexpilot-schema">{"a":1}</script>' . "\n", SchemaOutput::script( '{"a":1}' ) );
	}

	public function test_addresses_are_compared_without_scheme_case_and_trailing_slash(): void {
		self::assertTrue( EntryPolicy::same_address( 'https://Site.test/deck/blood-dk/', 'http://site.test/deck/blood-dk' ) );
		self::assertFalse( EntryPolicy::same_address( 'https://site.test/deck/blood-dk-old/', 'https://site.test/deck/blood-dk/' ) );
	}
}
