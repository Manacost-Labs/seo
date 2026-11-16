<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Import;

use IndexPilot\Import\AioseoSettings;
use PHPUnit\Framework\TestCase;

final class AioseoSettingsTest extends TestCase {

	/**
	 * Настройки AIOSEO в том виде, как они хранились на hs-manacost.ru: метки скрыты из поиска,
	 * архивы авторов, дат и поиск закрыты, рубрики и записи открыты.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function options(): array {
		$closed = array(
			'show'     => false,
			'advanced' => array(
				'robotsMeta' => array(
					'default' => false,
					'noindex' => true,
				),
			),
		);
		$open   = array(
			'show'     => true,
			'advanced' => array(
				'robotsMeta' => array(
					'default' => true,
					'noindex' => false,
				),
			),
		);
		$custom = array(
			'show'     => true,
			'advanced' => array(
				'robotsMeta' => array(
					'default' => false,
					'noindex' => true,
				),
			),
		);

		return array(
			array(
				'searchAppearance' => array(
					'archives' => array(
						'author' => $closed,
						'date'   => $closed,
						'search' => $closed,
					),
				),
			),
			array(
				'searchAppearance' => array(
					'taxonomies' => array(
						'category'   => $open,
						'post_tag'   => $closed,
						'deck_class' => $custom,
					),
					'postTypes'  => array(
						'post'    => $open,
						'hs_deck' => $open,
					),
				),
			),
		);
	}

	public function test_closed_page_kinds_become_noindex(): void {
		list( $options, $dynamic ) = $this->options();

		$patch = AioseoSettings::patch( $options, $dynamic );

		self::assertSame( array( 'noindex' => true ), $patch['taxonomies']['post_tag'] );
		self::assertSame( array( 'noindex' => true ), $patch['taxonomies']['deck_class'], 'Свой robots с noindex тоже закрывает.' );
		self::assertArrayNotHasKey( 'category', $patch['taxonomies'] );
		self::assertArrayNotHasKey( 'post_types', $patch, 'Открытые типы записей не трогаются.' );
		self::assertSame( array( 'author', 'date', 'search' ), array_keys( $patch['special'] ) );
	}

	public function test_settings_already_set_in_indexpilot_are_kept(): void {
		list( $options, $dynamic ) = $this->options();

		$settings = array(
			'general'    => array( 'hub_auto' => false ),
			'taxonomies' => array( 'post_tag' => array( 'noindex' => false ) ),
		);
		$merged   = AioseoSettings::merge( $settings, AioseoSettings::patch( $options, $dynamic ) );

		self::assertFalse( $merged['taxonomies']['post_tag']['noindex'], 'Решение, принятое в IndexPilot, важнее.' );
		self::assertTrue( $merged['special']['author']['noindex'] );
		self::assertFalse( $merged['general']['hub_auto'] );
		self::assertSame( array(), AioseoSettings::patch( array(), array() ) );
	}
}
