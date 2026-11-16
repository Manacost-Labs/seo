<?php
/**
 * Типы записей, которые на боевых сайтах регистрируют другие плагины. Нужны только тестовому WordPress.
 */

add_action(
	'init',
	static function (): void {
		register_post_type(
			'hs_deck',
			array(
				'public'      => true,
				'label'       => 'Колоды',
				'has_archive' => true,
				'rewrite'     => array( 'slug' => 'deck' ),
				'supports'    => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			)
		);
		register_taxonomy( 'deck_class', 'hs_deck', array( 'public' => true, 'label' => 'Класс', 'rewrite' => array( 'slug' => 'deck-class' ) ) );
		register_taxonomy( 'deck_mode', 'hs_deck', array( 'public' => true, 'label' => 'Режим', 'rewrite' => array( 'slug' => 'deck-mode' ) ) );
		register_taxonomy( 'deck_archetype', 'hs_deck', array( 'public' => true, 'label' => 'Архетип', 'rewrite' => array( 'slug' => 'archetype' ) ) );
		register_taxonomy( 'deck_streamer', 'hs_deck', array( 'public' => true, 'label' => 'Стример', 'rewrite' => array( 'slug' => 'streamer' ) ) );
	}
);

// Тестовый сайт ведёт себя как hs-manacost.ru: у колод есть стоимость в пыли и игрок.
add_filter( 'indexpilot_site_profile', static fn(): string => 'manacost' );

// Тестовый сайт не должен обновлять WordPress посреди прогона: на время обновления он закрывается на обслуживание.
add_filter( 'automatic_updater_disabled', '__return_true' );
add_filter( 'auto_update_core', '__return_false' );

// На боевых сайтах работает классический редактор — тестовый сайт показывает панель так же.
add_filter( 'use_block_editor_for_post', '__return_false' );

// Замок закрытого текста, как Locker на kolodahearthstone.com: посетитель видит приглашение вместо текста.
add_action(
	'init',
	static function (): void {
		add_shortcode( 'sociallocker', static fn(): string => '<p>Поделитесь записью, чтобы открыть разбор.</p>' );
	}
);
