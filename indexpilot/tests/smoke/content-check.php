<?php
/**
 * Проверка вкладки «Содержание» панели записи внутри тестового WordPress: поля сохраняются настоящей отправкой
 * формы записи, блок «Коротко» стоит перед первым абзацем, вопросы выводятся и попадают в FAQPage, новость получает
 * NewsArticle, факт и цитата видны на странице, краткий ответ идёт в мета-описание, скриптов и стилей на странице нет.
 * Отдаёт JSON, каждое значение должно быть true.
 * Проверка создаёт свою запись и удаляет её за собой. Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Promo\Blocks;

wp_set_current_user( 1 );

set_exception_handler(
	static function ( Throwable $error ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$answer = 'Маг на заклинаниях выигрывает темпом. Дешёвые заклинания чистят стол в первые ходы, а урон в лицо закрывает игру к восьмому ходу. Колода наказывает медленные архетипы, но проседает против агрессии: в муллигане ищите дешёвое удаление и добор, а тяжёлые карты смело меняйте — так вы переживёте ранний натиск и дойдёте до своих сильных ходов.';
$guides = get_term_by( 'slug', 'gajdy', 'category' );
$news   = get_term_by( 'slug', 'novosti', 'category' );

$content = "<p>Первый абзац статьи про мага.</p>\n\n<h2>Список карт</h2>\n\n<p>Текст раздела.</p>\n\n"
	. '[indexpilot_stat value="54 %" label="побед" source="HSReplay" url="https://hsreplay.net/decks/" date="15.11.2026" sample="312 игр"]' . "\n\n"
	. '[indexpilot_stat value="48 %" label="побед против агро"]' . "\n\n"
	. '[indexpilot_quote name="Сильвернейм" role="стример"]Темп решает всё.[/indexpilot_quote]' . "\n\n"
	. "<h2>Муллиган</h2>\n\n<p>Ещё текст.</p>";

$post_id = (int) wp_insert_post(
	array(
		'post_title'    => 'Проверка вкладки «Содержание»: маг на заклинаниях',
		'post_name'     => 'content-check-' . wp_rand( 1000, 9999 ),
		'post_status'   => 'publish',
		'post_content'  => $content,
		'post_category' => array( (int) $guides->term_id, (int) $news->term_id ),
	)
);

// С параметром ?keep=1 запись остаётся на сайте: её панель и страницу можно посмотреть глазами.
$keep = isset( $_GET['keep'] );

register_shutdown_function(
	static function () use ( $post_id, $keep ): void {
		if ( ! $keep ) {
			wp_delete_post( $post_id, true );
		}
	}
);

// Вход администратором: куки сессии для запросов сайта к самому себе.
$expires  = time() + 10 * MINUTE_IN_SECONDS;
$sessions = WP_Session_Tokens::get_instance( 1 );
$session  = $sessions->create( $expires );
$cookies  = array(
	AUTH_COOKIE      => wp_generate_auth_cookie( 1, $expires, 'auth', $session ),
	LOGGED_IN_COOKIE => wp_generate_auth_cookie( 1, $expires, 'logged_in', $session ),
);

$get = static function ( string $url, array $cookies = array() ): string {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 90,
			'redirection' => 0,
			'cookies'     => $cookies,
			'sslverify'   => false,
		)
	);

	return is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
};

/**
 * Поля формы записи так, как их отправил бы браузер: пары «имя — значение».
 *
 * @param string $html HTML экрана правки записи.
 * @return list<array{0: string, 1: string}>
 */
$form_pairs = static function ( string $html ): array {
	$document = new DOMDocument();
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- разметка админки не обязана быть безупречной для libxml.
	@$document->loadHTML( '<?xml encoding="UTF-8">' . $html );

	$xpath = new DOMXPath( $document );
	$pairs = array();

	foreach ( $xpath->query( '//form[@id="post"]//*[self::input or self::textarea or self::select][@name]' ) as $element ) {
		if ( $element->hasAttribute( 'disabled' ) ) {
			continue;
		}

		$name = $element->getAttribute( 'name' );

		if ( 'textarea' === $element->nodeName ) {
			$pairs[] = array( $name, $element->textContent );
		} elseif ( 'select' === $element->nodeName ) {
			$options  = $xpath->query( './/option', $element );
			$selected = $xpath->query( './/option[@selected]', $element );
			$option   = $selected->length > 0 ? $selected->item( 0 ) : $options->item( 0 );

			if ( null !== $option ) {
				$pairs[] = array( $name, $option->hasAttribute( 'value' ) ? $option->getAttribute( 'value' ) : $option->textContent );
			}
		} else {
			$type = strtolower( $element->getAttribute( 'type' ) );

			if ( in_array( $type, array( 'submit', 'button', 'file', 'image', 'reset' ), true ) || ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $element->hasAttribute( 'checked' ) ) ) {
				continue;
			}

			$pairs[] = array( $name, $element->getAttribute( 'value' ) );
		}
	}

	return $pairs;
};

$edit_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
$edit_html = $get( $edit_url, $cookies );
$pairs     = $form_pairs( $edit_html );
$names     = array_column( $pairs, 0 );

// Значения, которые «вводит редактор»: заменяют поля панели, остальные поля формы уходят как есть.
$typed = array(
	array( 'indexpilot_answer', $answer ),
	array( 'indexpilot_faq[question][]', 'Сколько пыли стоит колода?' ),
	array( 'indexpilot_faq[answer][]', 'Около 6000 пыли: две легендарные карты и четыре эпические.' ),
	array( 'indexpilot_faq[question][]', 'Подходит ли колода новичку?' ),
	array( 'indexpilot_faq[answer][]', "Да.\nПлан игры простой: чистим стол и бьём в лицо." ),
	array( 'indexpilot_faq[question][]', 'Вопрос без ответа?' ),
	array( 'indexpilot_faq[answer][]', '' ),
	array( 'indexpilot_schema_type', 'NewsArticle' ),
	array( 'indexpilot_breadcrumb_title', 'Маг на заклинаниях' ),
	array( 'indexpilot_primary_category', (string) $news->term_id ),
	array( 'indexpilot_promo_off', '1' ),
	array( 'indexpilot_cornerstone', '1' ),
);
$replaced = array_unique( array_column( $typed, 0 ) );
$body     = array();

foreach ( array_merge( array_filter( $pairs, static fn( array $pair ): bool => ! in_array( $pair[0], $replaced, true ) ), $typed ) as $pair ) {
	$body[] = rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
}

$posted = wp_remote_post(
	admin_url( 'post.php' ),
	array(
		'timeout'     => 120,
		'redirection' => 0,
		'cookies'     => $cookies,
		'sslverify'   => false,
		'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'body'        => implode( '&', $body ),
	)
);

// Запрос сохранил мету в другом процессе: кэш этого процесса о ней не знает.
clean_post_cache( $post_id );
wp_cache_delete( $post_id, 'post_meta' );

$faq      = get_post_meta( $post_id, MetaKeys::FAQ, true );
$analysis = json_decode( (string) get_post_meta( $post_id, MetaKeys::ANALYSIS, true ), true );
$checks   = array_column( (array) ( $analysis['results'] ?? array() ), 'status', 'id' );

$messages = array_column( (array) ( $analysis['results'] ?? array() ), 'message', 'id' );
$groups   = array_column( (array) ( $analysis['results'] ?? array() ), 'group', 'id' );

// Оценка SEO, пересчитанная без группы «Читаемость»: с ней должны совпасть число в кружке и мета оценки.
$earned = 0;
$max    = 0;

foreach ( (array) ( $analysis['results'] ?? array() ) as $result ) {
	if ( 'readability' !== $result['group'] ) {
		$earned += (int) $result['score'];
		$max    += (int) $result['max'];
	}
}

$seo_score    = $max > 0 ? (int) round( $earned / $max * 100 ) : -1;
$stored_score = (string) get_post_meta( $post_id, MetaKeys::SCORE, true );

// Гайд с разговорными словами в тексте и запись-двойник с ключом-синонимом: словарь игры, тип материала, полнота.
$guide_id = (int) wp_insert_post(
	array(
		'post_title'    => 'Гайд: жрец на контроле',
		'post_name'     => 'content-check-guide-' . wp_rand( 1000, 9999 ),
		'post_status'   => 'publish',
		'post_author'   => 1,
		'post_content'  => "<p>Дека приста на контроле переживает раннюю агрессию и выигрывает за счёт ценности.</p>\n\n<h2>Состав колоды</h2>\n\n<p>Текст.</p>\n\n<h2>Муллиган</h2>\n\n<p>Текст.</p>\n\n<h2>Стратегия</h2>\n\n<p>Текст.</p>\n\n<h2>Матчапы</h2>\n\n<p>Текст.</p>",
		'post_category' => array( (int) $guides->term_id ),
	)
);
$twin_id  = (int) wp_insert_post(
	array(
		'post_title'    => 'Вторая запись про жреца',
		'post_name'     => 'content-check-twin-' . wp_rand( 1000, 9999 ),
		'post_status'   => 'publish',
		'post_author'   => 1,
		'post_content'  => '<p>Текст второй записи.</p>',
		'post_category' => array( (int) $guides->term_id ),
	)
);

register_shutdown_function(
	static function () use ( $guide_id, $twin_id ): void {
		wp_delete_post( $guide_id, true );
		wp_delete_post( $twin_id, true );
	}
);

update_post_meta( $guide_id, MetaKeys::FOCUS_KEYWORD, 'колода жреца' );
update_post_meta( $guide_id, MetaKeys::SCHEMA_TYPE, 'TechArticle' );
update_post_meta( $twin_id, MetaKeys::FOCUS_KEYWORD, 'Дека приста' );
update_post_meta( $twin_id, MetaKeys::ANSWER, 'Короткий ответ второй записи.' );

$plugin        = IndexPilot\Plugin::instance();
$analyze       = static fn( int $id, string $type ): array => $plugin->score_updater()->analyze( new IndexPilot\Resolver\Context( IndexPilot\Resolver\Context::POST, $id, $type ) )->to_array();
$guide_report  = $analyze( $guide_id, 'post' );
$guide_status  = array_column( $guide_report['results'], 'status', 'id' );
$guide_message = array_column( $guide_report['results'], 'message', 'id' );
$deck_id       = (int) get_page_by_path( 'blood-dk', OBJECT, 'hs_deck' )->ID;
$deck_report   = $analyze( $deck_id, 'hs_deck' );
$deck_ids      = array_column( $deck_report['results'], 'id' );

$graph_of = static function ( string $html ): array {
	preg_match( '#<script type="application/ld\+json" class="indexpilot-schema">(.*?)</script>#s', $html, $found );

	return json_decode( $found[1] ?? '', true )['@graph'] ?? array();
};
$node_in  = static function ( array $nodes, string $type ): array {
	foreach ( $nodes as $item ) {
		if ( $type === ( $item['@type'] ?? '' ) ) {
			return $item;
		}
	}

	return array();
};

// Гайд в разметке — Article с жанром. (Новость при выборе «Авто» проверяет check-head.php.)
$guide_url   = (string) get_permalink( $guide_id );
$guide_graph = $graph_of( $get( $guide_url ) );

// Взаимоисключающие сигналы: noindex и ручной каноникал на другую страницу. У такой страницы нет узла статьи.
update_post_meta( $guide_id, MetaKeys::NOINDEX, '1' );
update_post_meta( $guide_id, MetaKeys::CANONICAL, 'https://example.com/other/' );
$conflict       = array_column( $analyze( $guide_id, 'post' )['results'], 'status', 'id' );
$foreign_graph  = $graph_of( $get( $guide_url ) );
$foreign_types  = array_values( array_unique( array_column( $foreign_graph, '@type' ) ) );

// Дата изменения раньше даты публикации (так бывает у записи, поставленной в план): везде выводится дата публикации.
global $wpdb;

$early = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
$wpdb->update( $wpdb->posts, array( 'post_modified' => get_date_from_gmt( $early ), 'post_modified_gmt' => $early ), array( 'ID' => $twin_id ) );
clean_post_cache( $twin_id );

$twin_page    = $get( (string) get_permalink( $twin_id ) );
$twin_article = $node_in( $graph_of( $twin_page ), 'Article' );
$twin_tag     = static fn( string $name ): string => preg_match( '#<meta property="' . preg_quote( $name, '#' ) . '" content="([^"]*)"#', $twin_page, $found ) ? $found[1] : '';

preg_match( '#Обновлено <time datetime="([^"]+)">#u', $twin_page, $twin_stamp );

// Страница записи глазами читателя и глазами редактора. Имя $page занято WordPress: его перезаписывает setup_postdata().
$permalink   = (string) get_permalink( $post_id );
$reader_page = $get( $permalink );
$editor_page = $get( $permalink, $cookies );

preg_match( '#<script type="application/ld\+json" class="indexpilot-schema">(.*?)</script>#s', $reader_page, $json );
$graph = json_decode( $json[1] ?? '', true )['@graph'] ?? array();
$node  = static function ( string $type ) use ( $graph ): array {
	foreach ( $graph as $item ) {
		if ( $type === ( $item['@type'] ?? '' ) ) {
			return $item;
		}
	}

	return array();
};
$ids   = array_column( $graph, '@id' );
$refs  = array();
array_walk_recursive(
	$graph,
	static function ( $value, $key ) use ( &$refs ): void {
		if ( '@id' === $key ) {
			$refs[] = $value;
		}
	}
);

preg_match( '#<meta name="description" content="([^"]*)"#', $reader_page, $description );
$description = html_entity_decode( $description[1] ?? '', ENT_QUOTES, 'UTF-8' );

$faq_page    = $node( 'FAQPage' );
$article     = $node( 'NewsArticle' );
$breadcrumbs = array_column( (array) ( $node( 'BreadcrumbList' )['itemListElement'] ?? array() ), 'name' );
$answer_at   = strpos( $reader_page, 'class="indexpilot-spoiler indexpilot-answer"' );
$first_at    = strpos( $reader_page, '<p>Первый абзац статьи про мага.</p>' );
$faq_at      = strpos( $reader_page, '<section class="indexpilot-faq"' );

// Поле indexpilot в REST: редактор видит новые поля, запись заголовка их не затирает.
$rest_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
$rest_request->set_param( 'context', 'edit' );
$rest_request->set_param( 'indexpilot', array( 'seo_title' => 'Маг на заклинаниях: гайд %sep% %sitedesc% %sep% %sitename%' ) );
$rest = rest_do_request( $rest_request )->get_data()['indexpilot'] ?? array();

// Неизвестная переменная %sitedesc%: в предпросмотре панели остаётся и названа в ответе, на сайт не выходит.
// Само предупреждение в панели проверяет metabox-check.php.
$preview_request = new WP_REST_Request( 'POST', '/indexpilot/v1/analyze' );
$preview_request->set_param( 'object_type', 'post' );
$preview_request->set_param( 'object_id', $post_id );
$preview = (array) rest_do_request( $preview_request )->get_data();

// Сохранение записи без панели (быстрая правка, массовый редактор, импорт) поля не трогает.
$_POST = array();
wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => 'Отрывок.' ) );

$kept = $answer === get_post_meta( $post_id, MetaKeys::ANSWER, true ) && 3 === count( (array) get_post_meta( $post_id, MetaKeys::FAQ, true ) );

// Шорткоды: блоки встают на место шорткода и не повторяются.
wp_update_post( array( 'ID' => $post_id, 'post_content' => "<p>Вступление.</p>\n\n[indexpilot_answer]\n\n[indexpilot_faq]\n\n" . $content . "\n\n[indexpilot_answer]" ) );
$shortcode_page = $get( $permalink );

// llms.txt: опорная запись идёт с кратким ответом целиком.
$llms = $get( home_url( '/llms.txt' ) );

// Проверки по книге «SEO — искусство раскрутки сайтов»: текст ссылок, оплаченное размещение, картинки и ролик.
// Сохранение записи спрашивает у YouTube название ролика; без сети название берётся из записи.
$book_id = (int) wp_insert_post(
	array(
		'post_title'   => 'Проверка по книге: жрец на воскрешении',
		'post_name'    => 'content-check-book-' . wp_rand( 1000, 9999 ),
		'post_status'  => 'publish',
		'post_content' => '<p>Гайд по муллигану — <a href="https://example.com/gajd/">здесь</a>.</p>'
			. '<p>Купите <a href="https://shop.example.com/cards" rel="nofollow">наборы</a> или поддержите нас на <a href="https://hearthpulse.net/decks/">Hearthpulse</a>.</p>'
			. '<img src="https://example.com/IMG_2031.jpg" alt="IMG_2031.jpg" width="800" height="600">'
			. '<img class="is-decorative" src="https://example.com/line.png" alt="" width="10" height="10">'
			. "\n\n" . '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
	)
);

register_shutdown_function(
	static function () use ( $book_id ): void {
		wp_delete_post( $book_id, true );
	}
);

update_post_meta( $book_id, IndexPilot\Analysis\PaperExtras::SPONSORED, '1' );

$book_report   = $analyze( $book_id, 'post' );
$book_status   = array_column( $book_report['results'], 'status', 'id' );
$book_message  = array_column( $book_report['results'], 'message', 'id' );
$book_videos   = get_post_meta( $book_id, IndexPilot\Schema\VideoData::META, true );
$book_video    = $node_in( $graph_of( $get( (string) get_permalink( $book_id ) ) ), 'VideoObject' );

delete_post_meta( $book_id, IndexPilot\Analysis\PaperExtras::SPONSORED );
$book_unpaid = array_column( $analyze( $book_id, 'post' )['results'], 'id' );

// Отчёт «Картинки»: экран открывается и считает картинки медиатеки без описания.
ob_start();
( new IndexPilot\Admin\Reports\ImagesReport() )->render();
$images_html = (string) ob_get_clean();

$sessions->destroy( $session );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'form'      => array(
			'edit_screen_opens'      => str_contains( $edit_html, 'id="indexpilot-panel"' ),
			'has_content_tab'        => str_contains( $edit_html, 'data-tab="content"' ) && strpos( $edit_html, 'data-tab="snippet"' ) < strpos( $edit_html, 'data-tab="content"' ) && strpos( $edit_html, 'data-tab="content"' ) < strpos( $edit_html, 'data-tab="analysis"' ),
			'has_new_fields'         => array() === array_diff( array( 'indexpilot_answer', 'indexpilot_faq[question][]', 'indexpilot_faq[answer][]', 'indexpilot_schema_type', 'indexpilot_breadcrumb_title', 'indexpilot_primary_category' ), $names ),
			'has_buttons'            => 2 === substr_count( $edit_html, 'data-indexpilot-insert="' ) && str_contains( $edit_html, 'data-indexpilot-blurb' ) && str_contains( $edit_html, '[indexpilot_stat value=&quot;&quot;' ),
			'promo_switch_only_once' => 1 === substr_count( $edit_html, 'name="indexpilot_promo_off"' ) && ! str_contains( $edit_html, 'indexpilot_promo_switch_nonce' ),
			'blurb_is_plain_text'    => 1 === preg_match( '#data-indexpilot-blurb-text hidden>([^<]*)</textarea>#', $edit_html, $blurb ) && str_contains( $blurb[1], 'Список карт' ) && str_contains( $blurb[1], 'Муллиган' ) && str_contains( $blurb[1], (string) wp_get_shortlink( $post_id ) ),
			'submit_redirects'       => ! is_wp_error( $posted ) && 302 === (int) wp_remote_retrieve_response_code( $posted ),
		),
		'saved'     => array(
			'answer'              => $answer === get_post_meta( $post_id, MetaKeys::ANSWER, true ),
			'faq_pairs'           => is_array( $faq ) && 3 === count( $faq ) && 'Сколько пыли стоит колода?' === ( $faq[0]['question'] ?? '' ) && str_contains( (string) ( $faq[1]['answer'] ?? '' ), "\n" ),
			'schema_type'         => 'NewsArticle' === get_post_meta( $post_id, MetaKeys::SCHEMA_TYPE, true ),
			'breadcrumb_title'    => 'Маг на заклинаниях' === get_post_meta( $post_id, MetaKeys::BREADCRUMB_TITLE, true ),
			'primary_category'    => (int) $news->term_id === (int) get_post_meta( $post_id, MetaKeys::PRIMARY_CATEGORY, true ),
			'promo_off'           => '1' === get_post_meta( $post_id, Blocks::META_OFF, true ),
			'analysis_has_answer' => 'good' === ( $checks['answer'] ?? '' ),
		),
		'page'      => array(
			'spoiler_style_in_head'     => false !== strpos( $reader_page, '<style id="indexpilot-spoiler">' ) && strpos( $reader_page, '<style id="indexpilot-spoiler">' ) < strpos( $reader_page, '</head>' ) && 1 === substr_count( $reader_page, 'id="indexpilot-spoiler"' ),
			'answer_block_once'         => false !== $answer_at && 1 === substr_count( $reader_page, 'class="indexpilot-spoiler indexpilot-answer"' ),
			'answer_before_first_p'     => false !== $answer_at && false !== $first_at && $answer_at < $first_at,
			'answer_has_update_date'    => 1 === preg_match( '#Обновлено <time datetime="\d{4}-\d\d-\d\dT[^"]+">\d\d\.\d\d\.\d{4}</time>#u', $reader_page ),
			'faq_block_at_the_end'      => false !== $faq_at && $faq_at > strpos( $reader_page, 'Ещё текст.' ) && str_contains( $reader_page, '<h3>Сколько пыли стоит колода?</h3>' ),
			'faq_skips_unfinished_pair' => ! str_contains( $reader_page, 'Вопрос без ответа?' ),
			'stat_block'                => str_contains( $reader_page, 'class="indexpilot-stat"' ) && str_contains( $reader_page, '<a href="https://hsreplay.net/decks/" rel="noopener">HSReplay</a>' ) && str_contains( $reader_page, '<time datetime="2026-11-15">15.11.2026</time>' ),
			'quote_block'               => str_contains( $reader_page, 'class="indexpilot-quote"' ) && str_contains( $reader_page, 'Темп решает всё.' ) && str_contains( $reader_page, 'Сильвернейм</cite>, стример' ),
			'editor_note_only_for_editor' => ! str_contains( $reader_page, 'indexpilot-editor-note' ) && 1 === substr_count( $editor_page, 'class="indexpilot-editor-note"' ),
			'description_is_the_answer' => str_starts_with( $description, 'Маг на заклинаниях выигрывает темпом.' ) && mb_strlen( $description ) <= 155,
			'article_section'           => str_contains( $reader_page, '<meta property="article:section" content="Новости">' ),
			'no_plugin_scripts_or_styles' => 0 === preg_match( '#<(?:script|link)\b[^>]*(?:src|href)="[^"]*/plugins/indexpilot/#', $reader_page ) && 0 === preg_match( '#<style id="indexpilot(?!-spoiler")#', $reader_page ),
		),
		'schema'    => array(
			'news_article'           => array() !== $article && 'Новости' === ( $article['articleSection'] ?? '' ),
			'faq_page_has_questions' => 2 === count( (array) ( $faq_page['mainEntity'] ?? array() ) ) && 'Сколько пыли стоит колода?' === ( $faq_page['mainEntity'][0]['name'] ?? '' ) && 'Около 6000 пыли: две легендарные карты и четыре эпические.' === ( $faq_page['mainEntity'][0]['acceptedAnswer']['text'] ?? '' ),
			'every_id_is_a_node'     => array() !== $graph && array() === array_diff( $refs, $ids ),
			'citation_has_source'    => array( array( '@type' => 'CreativeWork', 'name' => 'HSReplay', 'url' => 'https://hsreplay.net/decks/' ) ) === ( $article['citation'] ?? null ),
			'breadcrumbs_use_fields' => array( 'Главная', 'Новости', 'Маг на заклинаниях' ) === $breadcrumbs,
		),
		'rest'      => array(
			'editor_sees_fields'     => $answer === ( $rest['answer'] ?? '' ) && 3 === count( (array) ( $rest['faq'] ?? array() ) ) && 'NewsArticle' === ( $rest['schema_type'] ?? '' ) && (int) $news->term_id === ( $rest['primary_category'] ?? 0 ),
			'other_saves_keep_fields' => $kept,
		),
		'shortcode' => array(
			'answer_once_at_shortcode' => 1 === substr_count( $shortcode_page, 'class="indexpilot-spoiler indexpilot-answer"' ) && strpos( $shortcode_page, '<p>Вступление.</p>' ) < strpos( $shortcode_page, 'class="indexpilot-spoiler indexpilot-answer"' ),
			'faq_once_at_shortcode'    => 1 === substr_count( $shortcode_page, '<section class="indexpilot-faq"' ) && strpos( $shortcode_page, '<section class="indexpilot-faq"' ) < strpos( $shortcode_page, 'Первый абзац статьи про мага.' ) && str_contains( $shortcode_page, '"FAQPage"' ),
		),
		'llms'      => array(
			'answer_in_llms_txt' => str_contains( $llms, 'дойдёте до своих сильных ходов.' ),
		),
		'analysis'  => array(
			'material_is_detected'          => 'news' === ( $analysis['material']['type'] ?? '' ) && 'Новость' === ( $analysis['material']['label'] ?? '' ),
			'material_shown_in_panel'       => str_contains( $edit_html, 'Тип материала: Новость' ),
			'readability_group_in_panel'    => str_contains( $edit_html, '"readability":' ) && str_contains( $edit_html, 'data-indexpilot-material' ),
			'readability_has_own_score'     => is_int( $analysis['readability'] ?? null ) && in_array( 'readability', $groups, true ),
			'seo_score_ignores_readability' => $seo_score === (int) ( $analysis['score'] ?? -2 ) && (string) $seo_score === $stored_score,
			'citation_checks'               => 'good' === ( $checks['citation_answer'] ?? '' ) && 'good' === ( $checks['citation_dated_numbers'] ?? '' ) && 'good' === ( $checks['citation_sources'] ?? '' ) && isset( $checks['citation_author'] ) && 'good' === ( $checks['citation_fresh'] ?? '' ),
			'no_social_image_remark'        => 'ok' === ( $checks['social_image'] ?? '' ) && str_contains( (string) ( $messages['social_image'] ?? '' ), 'нет картинки для карточки в соцсетях' ),
			'synonym_counts_as_keyword'     => 'good' === ( $guide_status['keyword_in_first_paragraph'] ?? '' ),
			'synonymous_key_is_taken'       => 'bad' === ( $guide_status['keyword_unique'] ?? '' ) && str_contains( (string) ( $guide_message['keyword_unique'] ?? '' ), 'Вторая запись про жреца' ),
			'guide_is_detected'             => 'guide' === ( $guide_report['material']['type'] ?? '' ),
			'missing_section_is_named'      => str_starts_with( (string) ( $guide_message['material_sections'] ?? '' ), 'В гайде нет раздела: Замены.' ),
			'noindex_with_foreign_canonical' => ! isset( $guide_status['noindex_canonical'] ) && 'bad' === ( $conflict['noindex_canonical'] ?? '' ),
			'deck_has_no_answer_or_citation_checks' => 'deck' === ( $deck_report['material']['type'] ?? '' ) && null === $deck_report['readability'] && array() === array_filter( $deck_ids, static fn( string $id ): bool => 'answer' === $id || str_starts_with( $id, 'citation_' ) || str_starts_with( $id, 'readability' ) ),
		),
		'book'       => array(
			'sponsored_checkbox_in_panel' => str_contains( $edit_html, 'name="indexpilot_sponsored"' ),
			'vague_anchor_names_address'  => 'ok' === ( $book_status['anchor_text'] ?? '' ) && str_contains( (string) ( $book_message['anchor_text'] ?? '' ), 'https://example.com/gajd/' ),
			'unmarked_paid_link'          => 'bad' === ( $book_status['sponsored_links'] ?? '' ) && str_contains( (string) ( $book_message['sponsored_links'] ?? '' ), 'shop.example.com' ) && ! str_contains( (string) ( $book_message['sponsored_links'] ?? '' ), 'hearthpulse.net' ),
			'no_ad_label'                 => 'bad' === ( $book_status['sponsored_label'] ?? '' ),
			'nothing_required_unpaid'     => array() === array_filter( $book_unpaid, static fn( string $id ): bool => str_starts_with( $id, 'sponsored_' ) ),
			'alt_is_file_name'            => 'bad' === ( $book_status['image_alt'] ?? '' ) && str_contains( (string) ( $book_message['image_alt'] ?? '' ), 'IMG_2031.jpg' ) && ! str_contains( (string) ( $book_message['image_alt'] ?? '' ), 'line.png' ),
			'video_without_text'          => 'ok' === ( $book_status['video_text'] ?? '' ),
			'video_saved_on_save'         => is_array( $book_videos ) && 1 === count( $book_videos ) && 'youtube' === ( $book_videos[0]['provider'] ?? '' ) && '' !== ( $book_videos[0]['title'] ?? '' ),
			'images_report_opens'         => str_contains( $images_html, 'data-indexpilot-images' ) && str_contains( $images_html, 'Картинок без описания:' ),
			'video_goes_to_sitemap'       => 'https://www.youtube.com/embed/dQw4w9WgXcQ' === ( IndexPilot\Sitemap\PostTypeProvider::videos( $book_videos )[0]['player_loc'] ?? '' ),
			'video_object_in_schema'      => 'https://www.youtube.com/embed/dQw4w9WgXcQ' === ( $book_video['embedUrl'] ?? '' ) && '' !== ( $book_video['name'] ?? '' ) && '' !== ( $book_video['uploadDate'] ?? '' ) && array() !== (array) ( $book_video['thumbnailUrl'] ?? array() ),
		),
		'principles' => array(
			'guide_is_article_with_genre'    => 'Гайд' === ( $node_in( $guide_graph, 'Article' )['genre'] ?? '' ) && array() === $node_in( $guide_graph, 'TechArticle' ),
			'foreign_canonical_has_no_article' => array() !== $foreign_graph && ! in_array( 'Article', $foreign_types, true ) && in_array( 'BreadcrumbList', $foreign_types, true ) && $guide_url === ( $node_in( $foreign_graph, 'WebPage' )['url'] ?? '' ),
			'modified_not_before_published'  => '' !== $twin_tag( 'article:published_time' ) && $twin_tag( 'article:published_time' ) === $twin_tag( 'article:modified_time' ) && $twin_tag( 'article:modified_time' ) === $twin_tag( 'og:updated_time' ),
			'schema_and_label_use_same_date' => '' !== (string) ( $twin_article['datePublished'] ?? '' ) && $twin_article['datePublished'] === ( $twin_article['dateModified'] ?? '' ) && $twin_article['datePublished'] === ( $twin_stamp[1] ?? '' ),
			'unknown_variable_stays_in_preview' => array( '%sitedesc%' ) === ( $preview['unknown_variables'] ?? null ) && str_contains( (string) ( $preview['meta']['title'] ?? '' ), '%sitedesc%' ),
			'no_warning_without_unknown_variables' => 1 === preg_match( '#data-indexpilot-unknown role="alert" hidden>#', $edit_html ) && ! str_contains( $edit_html, 'Неизвестная переменная: %' ),
			'unknown_variable_not_on_site'   => str_contains( $shortcode_page, '<title>Маг на заклинаниях: гайд — Manacost</title>' ) && ! str_contains( $shortcode_page, '%sitedesc%' ),
		),
	) + ( $keep ? array( 'kept_post' => array( 'page' => $permalink, 'edit' => $edit_url ) ) : array() ),
	JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
