<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Settings;

use IndexPilot\Plugin;
use IndexPilot\Settings\Defaults;
use IndexPilot\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_unknown_post_type_gets_the_default_title_template(): void {
		$defaults = Defaults::post_type( 'some_new_type' );

		self::assertSame( '%title% %sep% %page% %sep% %sitename%', $defaults['title'] );
		self::assertFalse( $defaults['noindex'] );
		self::assertTrue( $defaults['sitemap'] );
	}

	public function test_known_post_types_have_their_own_defaults(): void {
		self::assertSame( 'Article', Defaults::post_type( 'post' )['schema_type'] );
		self::assertSame( 'WebPage', Defaults::post_type( 'page' )['schema_type'] );
		self::assertTrue( Defaults::post_type( 'attachment' )['noindex'] );
		self::assertFalse( Defaults::post_type( 'attachment' )['sitemap'] );
	}

	public function test_taxonomy_title_mentions_the_page_number_variable(): void {
		self::assertSame( '%term% %sep% %page% %sep% %sitename%', Defaults::taxonomy( 'category' )['title'] );
		self::assertTrue( Defaults::taxonomy( 'post_format' )['noindex'] );
	}

	public function test_saved_values_override_defaults(): void {
		$settings = new Settings(
			array(
				'post_types' => array(
					'hs_deck' => array(
						'title'   => '%title% — колода %tax_deck_class% %sep% %sitename%',
						'noindex' => '1',
					),
				),
			)
		);

		$deck = $settings->post_type( 'hs_deck' );

		self::assertSame( '%title% — колода %tax_deck_class% %sep% %sitename%', $deck['title'] );
		self::assertTrue( $deck['noindex'], 'Строка «1» из формы должна стать булевым значением.' );
		self::assertSame( Defaults::post_type( 'post' ), $settings->post_type( 'post' ) );
	}

	public function test_empty_title_template_falls_back_and_unknown_keys_are_ignored(): void {
		$settings = new Settings(
			array(
				'post_types' => array(
					'post' => array(
						'title'       => '   ',
						'description' => '',
						'surprise'    => 'x',
					),
				),
				'general'    => array( 'separator' => '|' ),
			)
		);

		$post = $settings->post_type( 'post' );

		self::assertSame( Defaults::post_type( 'post' )['title'], $post['title'] );
		self::assertSame( '', $post['description'], 'Пустой шаблон описания допустим: описание соберётся автоматически.' );
		self::assertArrayNotHasKey( 'surprise', $post );
		self::assertSame( '|', $settings->general( 'separator' ) );
		self::assertSame( Defaults::general()['thin_archive_threshold'], $settings->general( 'thin_archive_threshold' ) );
	}

	public function test_only_values_that_differ_from_defaults_are_stored(): void {
		$site = array( 'post_types' => array( 'hs_deck' => array( 'title' => '%title% %sep% колода' ) ) );

		$stored = Settings::changes(
			array(
				'general'    => array(
					'separator'              => '|',
					'thin_archive_threshold' => '3',
					'index_paged_archives'   => '0',
					'unknown'                => 'x',
				),
				'post_types' => array(
					'post'    => array(
						'title'     => '%title% %sep% %page% %sep% %sitename%',
						'noindex'   => '1',
						'min_words' => '500',
					),
					'hs_deck' => array(
						'title'       => '%title% %sep% колода',
						'description' => 'Колода %title%',
					),
					'evil'    => array( 'title' => 'x' ),
				),
				'taxonomies' => array( 'category' => array( 'title' => '' ) ),
				'special'    => array( 'search' => array( 'noindex' => '0' ) ),
			),
			$site,
			array(
				'post_types' => array( 'post', 'hs_deck' ),
				'taxonomies' => array( 'category' ),
				'special'    => array( 'home', 'search' ),
			)
		);

		self::assertSame(
			array(
				'general'    => array(
					'separator'            => '|',
					'index_paged_archives' => false,
				),
				'post_types' => array(
					'post'    => array(
						'noindex'   => true,
						'min_words' => 500,
					),
					'hs_deck' => array( 'description' => 'Колода %title%' ),
				),
				'special'    => array( 'search' => array( 'noindex' => false ) ),
			),
			$stored
		);

		// Сохранённый результат при повторной очистке не меняется.
		self::assertSame(
			$stored,
			Settings::changes(
				$stored,
				$site,
				array(
					'post_types' => array( 'post', 'hs_deck' ),
					'taxonomies' => array( 'category' ),
					'special'    => array( 'home', 'search' ),
				)
			)
		);
	}

	public function test_plugin_version_matches_the_plugin_header(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 3 ) . '/indexpilot.php' );

		self::assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $matches ) );
		self::assertSame( $matches[1], Plugin::VERSION );
	}

	public function test_custom_synonyms_are_kept_as_written_and_stored_only_when_set(): void {
		$text = "колода = пачка, стопка\nагро = аггро";

		$changes = Settings::changes( array( 'general' => array( 'custom_synonyms' => "  {$text}\n" ) ), array(), array() );

		self::assertSame( array( 'general' => array( 'custom_synonyms' => $text ) ), $changes );
		self::assertSame( $text, ( new Settings( $changes ) )->general( 'custom_synonyms' ) );
		self::assertSame( '', ( new Settings() )->general( 'custom_synonyms' ) );
		self::assertSame( array(), Settings::changes( array( 'general' => array( 'custom_synonyms' => '' ) ), array(), array() ) );
	}
}
