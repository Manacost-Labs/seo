<?php
/**
 * Шаблоны по умолчанию для колод и их таксономий.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Site;

/**
 * Значения накладываются на общие значения по умолчанию и перекрываются сохранёнными настройками.
 *
 * Название колоды на сайтах совпадает с названием архетипа и повторяется у сотен записей,
 * поэтому в заголовок колоды всегда входит дата: без неё заголовки были бы одинаковыми.
 * Фрагменты в {{ }} выводятся, только если у колоды заполнено соответствующее поле.
 */
final class SiteDefaults {

	/**
	 * Значения по умолчанию для профиля сайта в формате опции настроек.
	 *
	 * @param string $profile Профиль сайта — константа SiteProfile.
	 * @return array{post_types: array<string, array<string, mixed>>, taxonomies: array<string, array<string, mixed>>}
	 */
	public static function for_profile( string $profile ): array {
		$deck = array(
			'title'               => '%title% %sep% колода от %date%{{, %tax_deck_mode%}} %sep% %sitename%',
			'description'         => 'Колода «%title%» для Hearthstone{{, класс %tax_deck_class%}}{{, режим %tax_deck_mode%}}: код для копирования и список карт. Обновлено %modified%.',
			'schema_type'         => 'Article',
			// Колода в цепочке стоит под своим архетипом: страница архетипа собирает все его колоды.
			'breadcrumb_taxonomy' => 'deck_archetype',
		);

		if ( SiteProfile::MANACOST === $profile ) {
			// На hs-manacost.ru у колоды есть стоимость в пыли и имя игрока.
			$deck['title']       = '%title% %sep% колода от %date%{{, %tax_deck_mode%}}{{, %cf__dust_cost% пыли}}';
			$deck['description'] = 'Колода «%title%» для Hearthstone{{, класс %tax_deck_class%}}{{, режим %tax_deck_mode%}}: код для копирования и список карт.{{ Стоимость — %cf__dust_cost% пыли.}}{{ Игрок: %cf__deck_player%.}} Обновлено %modified%.';
		}

		if ( SiteProfile::KOLODA === $profile ) {
			// На kolodahearthstone.com у колоды нет таксономий: класс и режим читаются из кода колоды.
			$deck['title']       = '%title% %sep% колода от %date%{{, %deck_mode%}} %sep% %sitename%';
			$deck['description'] = 'Колода «%title%» для Hearthstone{{, класс %deck_class%}}{{, режим %deck_mode%}}: код для копирования и разбор. Обновлено %modified%.';
			unset( $deck['breadcrumb_taxonomy'] );
		}

		return array(
			'post_types' => array(
				'hs_deck' => $deck,
			),
			'taxonomies' => array(
				'deck_archetype' => array(
					'title'                => '%term% %sep% колоды Hearthstone %sep% %page% %sep% %sitename%',
					'fallback_description' => 'Колоды архетипа «%term%» для Hearthstone: актуальные версии с кодами и списками карт на сайте %sitename%.',
				),
				'deck_class'     => array(
					'title'                => 'Колоды: %term% %sep% Hearthstone %sep% %page% %sep% %sitename%',
					'fallback_description' => 'Колоды Hearthstone, класс %term%: актуальные сборки с кодами и списками карт на сайте %sitename%.',
				),
				'deck_mode'      => array(
					'title'                => 'Колоды Hearthstone: %term% %sep% %page% %sep% %sitename%',
					'fallback_description' => 'Колоды Hearthstone, режим %term%: актуальные сборки с кодами и списками карт на сайте %sitename%.',
				),
			),
		);
	}
}
