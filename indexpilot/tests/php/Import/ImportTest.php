<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Import;

use IndexPilot\Import\AioseoImporter;
use IndexPilot\Import\Importer;
use IndexPilot\Import\RankMathImporter;
use IndexPilot\Import\SvlSeoImporter;
use IndexPilot\Import\VariableSyntaxConverter;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Tests\Support\ArrayMetaStore;
use IndexPilot\Tests\Support\ArraySource;
use PHPUnit\Framework\TestCase;

final class ImportTest extends TestCase {

	public function test_aioseo_tags_become_indexpilot_variables(): void {
		self::assertSame( '%title% %sep% %sitename%', VariableSyntaxConverter::from_aioseo( '#post_title #separator_sa #site_title' ) );
		self::assertSame( '%cf__dust_cost% пыли, %tax_deck_mode%', VariableSyntaxConverter::from_aioseo( '#custom_field-_dust_cost пыли, #tax_name-deck_mode' ) );
		self::assertSame( '%excerpt%', VariableSyntaxConverter::from_aioseo( '#post_excerpt_only' ) );
	}

	public function test_aioseo_tags_without_equivalent_are_removed_and_plain_hashtags_stay(): void {
		self::assertSame( '', VariableSyntaxConverter::from_aioseo( '#post_content' ) );
		self::assertSame( 'Топ #1 колода #hearthstone', VariableSyntaxConverter::from_aioseo( 'Топ #1 колода #hearthstone' ) );
	}

	public function test_rank_math_variables_are_renamed(): void {
		self::assertSame( '%title% %sep% %tagline% %year%', VariableSyntaxConverter::from_rank_math( '%title% %sep% %sitedesc% %currentyear%' ) );
		self::assertSame( '%cf__dust_cost% %sitename%', VariableSyntaxConverter::from_rank_math( '%customfield(_dust_cost)% %sitename%' ) );
		self::assertSame( 'Колода %sep% %sitename%', VariableSyntaxConverter::from_rank_math( 'Колода %sep% %sitename% %pagenumber_unknown%' ) );
	}

	public function test_aioseo_row_is_mapped_to_meta_fields(): void {
		$fields = ( new AioseoImporter() )->map(
			array(
				'post_id'             => '10',
				'title'               => '#post_title | Hearthstone',
				'description'         => 'Описание колоды.',
				'keywords'            => '[{"label":"маг","value":"маг"},{"label":"топ колода","value":"топ колода"}]',
				'keyphrases'          => '{"focus":{"keyphrase":"колода мага","score":0},"additional":[{"keyphrase":"берн маг"}]}',
				'canonical_url'       => 'https://hs-manacost.ru/mag/',
				'og_title'            => 'Маг',
				'og_description'      => '',
				'og_image_type'       => 'custom',
				'og_image_custom_url' => 'https://hs-manacost.ru/wp-content/uploads/mag.png',
				'robots_default'      => '0',
				'robots_noindex'      => '1',
				'robots_nofollow'     => '0',
				'pillar_content'      => '1',
			)
		);

		self::assertSame(
			array(
				MetaKeys::TITLE         => '%title% | Hearthstone',
				MetaKeys::DESCRIPTION   => 'Описание колоды.',
				MetaKeys::FOCUS_KEYWORD => 'колода мага',
				MetaKeys::KEYWORDS      => array( 'берн маг', 'маг', 'топ колода' ),
				MetaKeys::CANONICAL     => 'https://hs-manacost.ru/mag/',
				MetaKeys::OG_TITLE      => 'Маг',
				Importer::OG_IMAGE_URL  => 'https://hs-manacost.ru/wp-content/uploads/mag.png',
				MetaKeys::NOINDEX       => '1',
				MetaKeys::CORNERSTONE   => '1',
			),
			$fields
		);
	}

	public function test_aioseo_default_robots_and_empty_values_are_not_imported(): void {
		$fields = ( new AioseoImporter() )->map(
			array(
				'post_id'         => '11',
				'title'           => '',
				'description'     => '#post_content',
				'keywords'        => '[]',
				'keyphrases'      => '{"focus":{"keyphrase":"","score":0},"additional":[]}',
				'robots_default'  => '1',
				'robots_noindex'  => '1',
				'og_image_type'   => 'default',
				'og_image_custom_url' => 'https://hs-manacost.ru/old.png',
			)
		);

		self::assertSame( array(), $fields );
	}

	public function test_svl_meta_is_mapped(): void {
		$fields = ( new SvlSeoImporter() )->map(
			array(
				'_svl_seo_title'     => 'Мета-отчёт',
				'_svl_seo_desc'      => 'Разбор меты.',
				'_svl_seo_focus'     => 'мета бг',
				'_svl_seo_keywords'  => 'мета, поля сражений, , мета',
				'_svl_seo_robots'    => 'noindex, follow',
				'_svl_seo_canonical' => 'https://kolodahearthstone.com/meta/',
				'_svl_seo_og_image'  => 'https://kolodahearthstone.com/wp-content/uploads/meta.png',
			)
		);

		self::assertSame( 'Мета-отчёт', $fields[ MetaKeys::TITLE ] );
		self::assertSame( 'Разбор меты.', $fields[ MetaKeys::DESCRIPTION ] );
		self::assertSame( 'мета бг', $fields[ MetaKeys::FOCUS_KEYWORD ] );
		self::assertSame( array( 'мета', 'поля сражений' ), $fields[ MetaKeys::KEYWORDS ] );
		self::assertSame( '1', $fields[ MetaKeys::NOINDEX ] );
		self::assertArrayNotHasKey( MetaKeys::NOFOLLOW, $fields );
		self::assertSame( 'https://kolodahearthstone.com/meta/', $fields[ MetaKeys::CANONICAL ] );
		self::assertSame( 'https://kolodahearthstone.com/wp-content/uploads/meta.png', $fields[ Importer::OG_IMAGE_URL ] );
	}

	public function test_rank_math_meta_is_mapped(): void {
		$fields = ( new RankMathImporter() )->map(
			array(
				'rank_math_title'              => '%title% %sep% %sitedesc%',
				'rank_math_description'        => 'Топ колоды Жреца.',
				'rank_math_focus_keyword'      => 'колода жреца, жрец, топ жрец',
				'rank_math_robots'             => array( 'noindex', 'nofollow' ),
				'rank_math_facebook_image_id'  => '55',
				'rank_math_pillar_content'     => 'on',
			)
		);

		self::assertSame( '%title% %sep% %tagline%', $fields[ MetaKeys::TITLE ] );
		self::assertSame( 'Топ колоды Жреца.', $fields[ MetaKeys::DESCRIPTION ] );
		self::assertSame( 'колода жреца', $fields[ MetaKeys::FOCUS_KEYWORD ] );
		self::assertSame( array( 'жрец', 'топ жрец' ), $fields[ MetaKeys::KEYWORDS ] );
		self::assertSame( '1', $fields[ MetaKeys::NOINDEX ] );
		self::assertSame( '1', $fields[ MetaKeys::NOFOLLOW ] );
		self::assertSame( 55, $fields[ MetaKeys::OG_IMAGE_ID ] );
		self::assertSame( '1', $fields[ MetaKeys::CORNERSTONE ] );
		self::assertTrue( ( new RankMathImporter() )->is_fallback() );
	}

	public function test_importer_writes_only_empty_fields_and_reports_progress(): void {
		$store = new ArrayMetaStore(
			array(
				1 => array( MetaKeys::TITLE => 'Заголовок редактора' ),
				2 => array(),
				3 => array(),
			)
		);
		$source = new ArraySource(
			array(
				1 => array( MetaKeys::TITLE => 'Старый заголовок', MetaKeys::DESCRIPTION => 'Старое описание' ),
				2 => array( MetaKeys::TITLE => 'Заголовок два' ),
				3 => array(),
				9 => array( MetaKeys::TITLE => 'Запись удалена' ),
			)
		);

		$touched = array();
		$result  = ( new Importer( $store, null, static function ( int $post_id ) use ( &$touched ): void {
			$touched[] = $post_id;
		} ) )->run( $source, 0, 10 );

		self::assertSame( 'Заголовок редактора', $store->get( 1, MetaKeys::TITLE ) );
		self::assertSame( 'Старое описание', $store->get( 1, MetaKeys::DESCRIPTION ) );
		self::assertSame( 'Заголовок два', $store->get( 2, MetaKeys::TITLE ) );
		self::assertFalse( $store->has( 9 ) );

		self::assertSame( 4, $result['processed'] );
		self::assertSame( 2, $result['imported'] );
		self::assertSame( 2, $result['fields'] );
		self::assertSame( 9, $result['last_id'] );
		self::assertTrue( $result['done'] );
		self::assertSame( array( 1, 2 ), $touched );
	}

	public function test_importer_resumes_after_the_last_processed_id(): void {
		$store  = new ArrayMetaStore( array( 1 => array(), 2 => array(), 3 => array() ) );
		$source = new ArraySource(
			array(
				1 => array( MetaKeys::TITLE => 'Один' ),
				2 => array( MetaKeys::TITLE => 'Два' ),
				3 => array( MetaKeys::TITLE => 'Три' ),
			)
		);
		$importer = new Importer( $store );

		$first = $importer->run( $source, 0, 2 );
		self::assertFalse( $first['done'] );
		self::assertSame( 2, $first['last_id'] );
		self::assertSame( '', $store->get( 3, MetaKeys::TITLE ) );

		$second = $importer->run( $source, $first['last_id'], 2 );
		self::assertTrue( $second['done'] );
		self::assertSame( 'Три', $store->get( 3, MetaKeys::TITLE ) );
	}

	public function test_dry_run_writes_nothing_and_returns_samples(): void {
		$store  = new ArrayMetaStore( array( 1 => array() ) );
		$source = new ArraySource( array( 1 => array( MetaKeys::TITLE => 'Один' ) ) );

		$result = ( new Importer( $store ) )->run( $source, 0, 10, true );

		self::assertSame( '', $store->get( 1, MetaKeys::TITLE ) );
		self::assertSame( 1, $result['imported'] );
		self::assertSame( array( array( 'post_id' => 1, 'fields' => array( MetaKeys::TITLE => 'Один' ) ) ), $result['samples'] );
	}

	public function test_overwrite_replaces_existing_values(): void {
		$store  = new ArrayMetaStore( array( 1 => array( MetaKeys::TITLE => 'Новый' ) ) );
		$source = new ArraySource( array( 1 => array( MetaKeys::TITLE => 'Из старого плагина' ) ) );

		( new Importer( $store ) )->run( $source, 0, 10, false, true );

		self::assertSame( 'Из старого плагина', $store->get( 1, MetaKeys::TITLE ) );
	}

	public function test_fallback_source_skips_posts_that_already_have_a_title_or_description(): void {
		$store = new ArrayMetaStore(
			array(
				1 => array( MetaKeys::DESCRIPTION => 'Описание из основного плагина' ),
				2 => array(),
			)
		);
		$source = new ArraySource(
			array(
				1 => array( MetaKeys::TITLE => 'Очень старый заголовок' ),
				2 => array( MetaKeys::TITLE => 'Заголовок для записи без данных' ),
			),
			true
		);

		$result = ( new Importer( $store ) )->run( $source, 0, 10 );

		self::assertSame( '', $store->get( 1, MetaKeys::TITLE ) );
		self::assertSame( 'Заголовок для записи без данных', $store->get( 2, MetaKeys::TITLE ) );
		self::assertSame( 1, $result['imported'] );
		self::assertSame( 1, $result['skipped'] );
	}

	public function test_image_address_is_turned_into_attachment_id(): void {
		$store  = new ArrayMetaStore( array( 1 => array(), 2 => array() ) );
		$source = new ArraySource(
			array(
				1 => array( Importer::OG_IMAGE_URL => 'https://site.ru/known.png' ),
				2 => array( Importer::OG_IMAGE_URL => 'https://site.ru/unknown.png' ),
			)
		);

		$result = ( new Importer( $store, static fn( string $url ): int => str_contains( $url, 'known.png' ) && ! str_contains( $url, 'unknown' ) ? 77 : 0 ) )->run( $source, 0, 10 );

		self::assertSame( 77, $store->get( 1, MetaKeys::OG_IMAGE_ID ) );
		self::assertSame( '', $store->get( 2, MetaKeys::OG_IMAGE_ID ) );
		self::assertSame( 1, $result['imported'] );
	}
}
