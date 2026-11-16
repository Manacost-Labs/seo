<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Site;

use IndexPilot\Resolver\Context;
use IndexPilot\Settings\Defaults;
use IndexPilot\Settings\Settings;
use IndexPilot\Site\SiteDefaults;
use IndexPilot\Site\SiteProfile;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;
use PHPUnit\Framework\TestCase;

final class SiteDefaultsTest extends TestCase {

	/**
	 * @param array<string, string> $values
	 */
	private function render( string $template, array $values ): string {
		$registry = new VariableRegistry();
		foreach ( $values as $name => $value ) {
			$registry->register( $name, static fn(): string => $value );
		}
		$registry->register_prefix( 'tax_', static fn( string $tax ): string => $values[ 'tax:' . $tax ] ?? '' );
		$registry->register_prefix( 'cf_', static fn( string $key ): string => $values[ 'cf:' . $key ] ?? '' );

		return ( new Renderer( $registry, '—' ) )->render( $template, new Context( Context::POST, 1, 'hs_deck' ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function deck( string $class_name, string $mode = 'Вольный', string $dust = '6320' ): array {
		return array(
			'title'          => 'Меха-К’Тун Друид',
			'date'           => '15.11.2026',
			'modified'       => '15.11.2026',
			'sitename'       => 'Manacost',
			'tax:deck_class' => $class_name,
			'tax:deck_mode'  => $mode,
			'cf:_dust_cost'  => $dust,
		);
	}

	public function test_profile_is_detected_from_the_host_including_test_subdomains(): void {
		self::assertSame( SiteProfile::MANACOST, SiteProfile::from_host( 'hs-manacost.ru' ) );
		self::assertSame( SiteProfile::MANACOST, SiteProfile::from_host( 'test.hs-manacost.ru' ) );
		self::assertSame( SiteProfile::KOLODA, SiteProfile::from_host( 'kolodahearthstone.com' ) );
		self::assertSame( SiteProfile::GENERIC, SiteProfile::from_host( '127.0.0.1' ) );
	}

	public function test_manacost_deck_title_contains_date_mode_and_dust(): void {
		$settings = new Settings( array(), SiteDefaults::for_profile( SiteProfile::MANACOST ) );
		$title    = $this->render( $settings->post_type( 'hs_deck' )['title'], $this->deck( 'Друид' ) );

		self::assertSame( 'Меха-К’Тун Друид — колода от 15.11.2026, Вольный, 6320 пыли', $title );
	}

	public function test_missing_deck_fields_leave_no_traces(): void {
		$settings = new Settings( array(), SiteDefaults::for_profile( SiteProfile::MANACOST ) );
		$config   = $settings->post_type( 'hs_deck' );
		$values   = $this->deck( '', '', '' );

		self::assertSame( 'Меха-К’Тун Друид — колода от 15.11.2026', $this->render( $config['title'], $values ) );
		self::assertSame(
			'Колода «Меха-К’Тун Друид» для Hearthstone: код для копирования и список карт. Обновлено 15.11.2026.',
			$this->render( $config['description'], $values )
		);
	}

	public function test_decks_of_different_classes_get_different_non_empty_descriptions(): void {
		$settings = new Settings( array(), SiteDefaults::for_profile( SiteProfile::MANACOST ) );
		$template = $settings->post_type( 'hs_deck' )['description'];

		$druid = $this->render( $template, $this->deck( 'Друид' ) );
		$mage  = $this->render( $template, $this->deck( 'Маг' ) );

		self::assertSame(
			'Колода «Меха-К’Тун Друид» для Hearthstone, класс Друид, режим Вольный: код для копирования и список карт. Стоимость — 6320 пыли. Обновлено 15.11.2026.',
			$druid
		);
		self::assertNotSame( $druid, $mage );
		self::assertNotSame( '', $mage );
	}

	public function test_koloda_deck_template_does_not_rely_on_fields_that_site_lacks(): void {
		$settings = new Settings( array(), SiteDefaults::for_profile( SiteProfile::KOLODA ) );
		$config   = $settings->post_type( 'hs_deck' );

		// На этом сайте у колоды нет ни произвольных полей, ни таксономий: класс и режим даёт игровой модуль из кода колоды.
		self::assertStringNotContainsString( 'cf_', $config['title'] . $config['description'] );
		self::assertStringNotContainsString( 'tax_', $config['title'] . $config['description'] );

		$values = $this->deck( 'Друид' ) + array(
			'deck_class' => 'Друид',
			'deck_mode'  => 'Вольный',
		);

		self::assertSame( 'Меха-К’Тун Друид — колода от 15.11.2026, Вольный — Manacost', $this->render( $config['title'], $values ) );
		self::assertStringContainsString( 'класс Друид, режим Вольный', $this->render( $config['description'], $values ) );
		self::assertSame( 'Меха-К’Тун Друид — колода от 15.11.2026 — Manacost', $this->render( $config['title'], $this->deck( 'Друид' ) + array( 'deck_class' => '', 'deck_mode' => '' ) ) );
	}

	public function test_saved_settings_still_win_over_site_defaults_and_other_types_are_untouched(): void {
		$saved    = array( 'post_types' => array( 'hs_deck' => array( 'title' => '%title% — своя формула' ) ) );
		$settings = new Settings( $saved, SiteDefaults::for_profile( SiteProfile::MANACOST ) );

		self::assertSame( '%title% — своя формула', $settings->post_type( 'hs_deck' )['title'] );
		self::assertSame( Defaults::post_type( 'post' ), $settings->post_type( 'post' ) );
		self::assertStringContainsString( 'архетипа', $settings->taxonomy( 'deck_archetype' )['fallback_description'] );
	}

	public function test_conditional_fragment_needs_every_variable_inside_it(): void {
		$values = array(
			'title'          => 'Колода',
			'tax:deck_class' => 'Маг',
		);

		self::assertSame( 'Колода, класс Маг', $this->render( '%title%{{, класс %tax_deck_class%}}{{, режим %tax_deck_mode%}}', $values ) );
		self::assertSame( 'Колода', $this->render( '%title%{{ (%tax_deck_class%, %tax_deck_mode%)}}', $values ) );
		self::assertSame( 'Колода (Маг)', $this->render( '%title% (%tax_deck_class%, %tax_deck_mode%)', $values ) );
	}
}
