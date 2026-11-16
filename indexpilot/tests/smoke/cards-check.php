<?php
/**
 * Проверка внутри тестового WordPress: карты шорткодом «[hs_card id="…"]Название[/hs_card]» в вопросах и ответах
 * и в кратком ответе выполняются на странице, а в описании остаётся только название.
 * Отдаёт JSON, каждое значение должно быть true. Запись удаляется за собой. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\Frontend\AnswerBlock;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;

// Так шорткод регистрирует плагин подсказок карт на сайте.
add_shortcode(
	'hs_card',
	static fn( $atts, $content = '' ): string => '<span class="hs-card" data-id="' . esc_attr( (string) ( $atts['id'] ?? '' ) ) . '">' . esc_html( (string) $content ) . '</span>'
);

$answer  = 'Пять карт: [hs_card id="CORE_DRG_107"]Аметистового чарокрыла[/hs_card] и [hs_card id="BE_251"]Космические источники[/hs_card].';
$post_id = wp_insert_post(
	array(
		'post_title'   => 'Берн маг',
		'post_status'  => 'publish',
		'post_content' => 'Гайд по Берн магу.',
	)
);

update_post_meta( $post_id, MetaKeys::ANSWER, $answer );
update_post_meta(
	$post_id,
	MetaKeys::FAQ,
	array(
		array(
			'question' => 'Какой муллиган у Берн мага?',
			'answer'   => $answer,
		),
	)
);

$plugin = Plugin::instance();
$faq    = $plugin->faq_block()->render( $post_id );
$block  = ( new AnswerBlock( $plugin->settings() ) )->render( $post_id );
$meta   = $plugin->resolver()->resolve( new Context( Context::POST, $post_id, 'post' ) );

$result = array(
	'faq_card_rendered'      => str_contains( $faq, '<span class="hs-card" data-id="CORE_DRG_107">Аметистового чарокрыла</span>' ),
	'faq_no_raw_shortcode'   => ! str_contains( $faq, '[hs_card' ),
	'answer_card_rendered'   => str_contains( $block, '<span class="hs-card" data-id="BE_251">Космические источники</span>' ),
	'description_plain_text' => str_contains( $meta->description, 'Аметистового чарокрыла и Космические источники' ) && ! str_contains( $meta->description, 'hs_card' ),
);

wp_delete_post( $post_id, true );

echo wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
