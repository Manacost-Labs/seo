<?php
/**
 * Наполняет тестовый WordPress (Playground) большим объёмом данных для замера нагрузки:
 * записи с текстом 300–1500 слов, рубриками и метками, мета AIOSEO в таблице {prefix}aioseo_posts
 * у части записей и колоды hs_deck с классом, режимом, архетипом и метой.
 *
 * Запись идёт прямыми вставками по сто строк за запрос, без хуков сохранения: так выглядит сайт,
 * на который плагин только что поставили. Вызывается частями, чтобы каждый запрос шёл недолго:
 *   load-setup.php?step=terms
 *   load-setup.php?step=posts&from=0&count=1000
 *   load-setup.php?step=decks&from=0&count=1000
 *   load-setup.php?step=finish
 * Отдаёт JSON. Файл не входит в сборку плагина.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

require '/wordpress/wp-load.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification.Recommended -- тестовый стенд.

global $wpdb;

const INDEXPILOT_LOAD_CATEGORIES = 20;
const INDEXPILOT_LOAD_TAGS       = 400;
const INDEXPILOT_LOAD_CLASSES    = array( 'Жрец', 'Маг', 'Охотник', 'Паладин', 'Воин', 'Друид', 'Чернокнижник', 'Шаман', 'Разбойник', 'Охотник на демонов', 'Рыцарь Смерти' );
const INDEXPILOT_LOAD_MODES      = array( 'Стандарт', 'Вольный' );
const INDEXPILOT_LOAD_ARCHETYPES = 150;
const INDEXPILOT_LOAD_ROWS       = 100;

$step  = sanitize_key( (string) ( $_GET['step'] ?? '' ) );
$from  = max( 0, (int) ( $_GET['from'] ?? 0 ) );
$count = max( 1, min( 5000, (int) ( $_GET['count'] ?? 1000 ) ) );
$start = microtime( true );

/**
 * Слова для текстов: обычная лексика статей о Hearthstone.
 */
function indexpilot_load_words(): array {
	static $words = null;

	if ( null === $words ) {
		$words = explode(
			' ',
			'колода карта ход мана урон существо заклинание оружие герой сила класс архетип мета патч сезон ранг легенда '
			. 'жрец маг охотник паладин воин друид чернокнижник шаман разбойник рыцарь смерти демон агро контроль темп комбо '
			. 'мулиган замена стол игра противник соперник победа поражение процент матчап сильный слабый быстрый медленный '
			. 'дешёвый дорогой новый старый лучший хороший рабочий популярный редкий эпический легендарный обычный '
			. 'стоит нужно можно следует играть брать держать менять ждать начинать заканчивать выигрывать проигрывать '
			. 'первый второй третий четвёртый пятый шестой седьмой восьмой поздний ранний средний этап план линия '
			. 'потому что если когда пока после перед вместо кроме через против для без при под над между '
			. 'очень часто редко всегда никогда обычно иногда почти только уже ещё тоже также поэтому однако'
		);
	}

	return $words;
}

/**
 * Текст записи: абзацы, подзаголовки, внутренние ссылки на более ранние записи.
 *
 * @param int $n     Номер записи.
 * @param int $words Сколько слов.
 */
function indexpilot_load_text( int $n, int $words ): string {
	$vocabulary = indexpilot_load_words();
	$size       = count( $vocabulary );
	$html       = '';
	$written    = 0;
	$paragraph  = 0;

	while ( $written < $words ) {
		if ( 0 === $paragraph % 3 ) {
			$html .= '<h2>' . mb_convert_case( $vocabulary[ mt_rand( 0, $size - 1 ) ], MB_CASE_TITLE ) . ' ' . $vocabulary[ mt_rand( 0, $size - 1 ) ] . '</h2>';
		}

		$length = min( $words - $written, mt_rand( 40, 90 ) );
		$text   = array();

		for ( $i = 0; $i < $length; $i++ ) {
			$text[] = $vocabulary[ mt_rand( 0, $size - 1 ) ];
		}

		// Каждый третий абзац ссылается на одну из ранее созданных записей.
		if ( $n > 1 && 1 === $paragraph % 3 ) {
			$target = mt_rand( 1, $n - 1 );
			$text[] = '<a href="' . esc_url( home_url( '/lt-post-' . $target . '/' ) ) . '">' . $vocabulary[ mt_rand( 0, $size - 1 ) ] . ' ' . $vocabulary[ mt_rand( 0, $size - 1 ) ] . '</a>';
		}

		$html    .= '<p>' . mb_convert_case( implode( ' ', $text ), MB_CASE_LOWER ) . '.</p>';
		$written += $length;
		++$paragraph;
	}

	return $html;
}

/**
 * Многострочная вставка.
 *
 * @param string                    $table Таблица.
 * @param list<array<string, mixed>> $rows  Строки с одинаковыми ключами.
 */
function indexpilot_load_insert( string $table, array $rows ): void {
	global $wpdb;

	foreach ( array_chunk( $rows, INDEXPILOT_LOAD_ROWS ) as $chunk ) {
		$columns = array_keys( $chunk[0] );
		$values  = array();

		foreach ( $chunk as $row ) {
			$values[] = '(' . implode( ',', array_map( static fn( $value ): string => is_int( $value ) ? (string) $value : "'" . esc_sql( (string) $value ) . "'", $row ) ) . ')';
		}

		$result = $wpdb->query( "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $values ) );

		if ( false === $result ) {
			throw new RuntimeException( 'Вставка не удалась: ' . $wpdb->last_error );
		}
	}
}

/**
 * Термины таксономии: создаёт недостающие, возвращает term_taxonomy_id по порядку.
 *
 * @param string       $taxonomy Таксономия.
 * @param list<string> $names    Названия.
 * @return list<int>
 */
function indexpilot_load_terms( string $taxonomy, array $names ): array {
	$ids = array();

	foreach ( $names as $name ) {
		$term = term_exists( $name, $taxonomy );

		if ( ! $term ) {
			$term = wp_insert_term( $name, $taxonomy, array( 'description' => 'Раздел «' . $name . '»: подборка материалов.' ) );
		}

		$ids[] = (int) $term['term_taxonomy_id'];
	}

	return $ids;
}

/**
 * Термины, заведённые шагом terms.
 *
 * @return array{category: list<int>, post_tag: list<int>, deck_class: list<int>, deck_mode: list<int>, deck_archetype: list<int>}
 */
function indexpilot_load_term_ids(): array {
	$ids = get_option( 'indexpilot_load_terms' );

	if ( ! is_array( $ids ) ) {
		throw new RuntimeException( 'Сначала шаг terms.' );
	}

	return $ids;
}

/**
 * Время записи: равномерно за последние три года.
 *
 * @param int $n     Номер.
 * @param int $total Всего.
 */
function indexpilot_load_date( int $n, int $total ): string {
	return gmdate( 'Y-m-d H:i:s', time() - (int) ( ( $total - $n ) * ( 3 * YEAR_IN_SECONDS / max( 1, $total ) ) ) );
}

/**
 * Строка таблицы записей.
 *
 * @param string $type    Тип.
 * @param string $slug    Ярлык.
 * @param string $title   Заголовок.
 * @param string $content Текст.
 * @param string $date    Время (UTC).
 * @return array<string, mixed>
 */
function indexpilot_load_post_row( string $type, string $slug, string $title, string $content, string $date ): array {
	return array(
		'post_author'           => 1,
		'post_date'             => $date,
		'post_date_gmt'         => $date,
		'post_content'          => $content,
		'post_title'            => $title,
		'post_excerpt'          => '',
		'post_status'           => 'publish',
		'comment_status'        => 'closed',
		'ping_status'           => 'closed',
		'post_password'         => '',
		'post_name'             => $slug,
		'to_ping'               => '',
		'pinged'                => '',
		'post_modified'         => $date,
		'post_modified_gmt'     => $date,
		'post_content_filtered' => '',
		'post_parent'           => 0,
		'guid'                  => home_url( '/?p=' . $slug ),
		'menu_order'            => 0,
		'post_type'             => $type,
		'post_mime_type'        => '',
		'comment_count'         => 0,
	);
}

/**
 * ID только что вставленных записей по ярлыкам.
 *
 * @param string       $type  Тип.
 * @param list<string> $slugs Ярлыки.
 * @return array<string, int>
 */
function indexpilot_load_ids( string $type, array $slugs ): array {
	global $wpdb;

	$ids = array();

	foreach ( array_chunk( $slugs, 500 ) as $chunk ) {
		$in   = implode( ',', array_map( static fn( string $slug ): string => "'" . esc_sql( $slug ) . "'", $chunk ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_name IN ({$in})", $type ) );

		foreach ( $rows as $row ) {
			$ids[ (string) $row->post_name ] = (int) $row->ID;
		}
	}

	return $ids;
}

/**
 * Таблица AIOSEO в том виде, в каком её читает импорт.
 */
function indexpilot_load_aioseo_table(): string {
	global $wpdb;

	$table = $wpdb->prefix . 'aioseo_posts';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL, title text, description text, keywords mediumtext, keyphrases longtext, canonical_url text, og_title text, og_description text, og_image_type varchar(20) DEFAULT 'default', og_image_custom_url text, robots_default tinyint(1) NOT NULL DEFAULT 1, robots_noindex tinyint(1) NOT NULL DEFAULT 0, robots_nofollow tinyint(1) NOT NULL DEFAULT 0, pillar_content tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id) )" );

	return $table;
}

$out = array( 'step' => $step );

try {
	switch ( $step ) {
		case 'terms':
			update_option( 'blogname', 'Manacost' );
			update_option( 'blogdescription', 'Всё о Hearthstone' );
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			$wp_rewrite->flush_rules( false );

			$categories = array();
			for ( $i = 1; $i <= INDEXPILOT_LOAD_CATEGORIES; $i++ ) {
				$categories[] = 'Рубрика ' . $i;
			}

			$tags = array();
			for ( $i = 1; $i <= INDEXPILOT_LOAD_TAGS; $i++ ) {
				$tags[] = 'метка ' . $i;
			}

			$archetypes = array();
			for ( $i = 1; $i <= INDEXPILOT_LOAD_ARCHETYPES; $i++ ) {
				$archetypes[] = 'Архетип ' . $i;
			}

			$ids = array(
				'category'       => indexpilot_load_terms( 'category', $categories ),
				'post_tag'       => indexpilot_load_terms( 'post_tag', $tags ),
				'deck_class'     => indexpilot_load_terms( 'deck_class', INDEXPILOT_LOAD_CLASSES ),
				'deck_mode'      => indexpilot_load_terms( 'deck_mode', INDEXPILOT_LOAD_MODES ),
				'deck_archetype' => indexpilot_load_terms( 'deck_archetype', $archetypes ),
			);

			update_option( 'indexpilot_load_terms', $ids, false );
			indexpilot_load_aioseo_table();
			$out['terms'] = array_map( 'count', $ids );
			break;

		case 'posts':
			$terms  = indexpilot_load_term_ids();
			$table  = indexpilot_load_aioseo_table();
			$total  = max( $from + $count, (int) ( $_GET['total'] ?? 15000 ) );
			$rows   = array();
			$slugs  = array();
			$extras = array();

			for ( $n = $from + 1; $n <= $from + $count; $n++ ) {
				mt_srand( $n );
				$slug     = 'lt-post-' . $n;
				$slugs[]  = $slug;
				$rows[]   = indexpilot_load_post_row( 'post', $slug, 'Гайд по колоде номер ' . $n . ': ' . indexpilot_load_words()[ $n % 40 ], indexpilot_load_text( $n, mt_rand( 300, 1500 ) ), indexpilot_load_date( $n, $total ) );
				$extras[] = $n;
			}

			$wpdb->query( 'START TRANSACTION' );
			indexpilot_load_insert( $wpdb->posts, $rows );
			$ids = indexpilot_load_ids( 'post', $slugs );

			$relations = array();
			$aioseo    = array();

			foreach ( $extras as $n ) {
				$post_id = $ids[ 'lt-post-' . $n ] ?? 0;
				if ( 0 === $post_id ) {
					continue;
				}

				$own = array( $terms['category'][ $n % count( $terms['category'] ) ] );
				if ( 0 === $n % 4 ) {
					$own[] = $terms['category'][ ( $n * 7 ) % count( $terms['category'] ) ];
				}
				for ( $t = 0; $t < $n % 5; $t++ ) {
					$own[] = $terms['post_tag'][ ( $n * 13 + $t * 31 ) % count( $terms['post_tag'] ) ];
				}

				foreach ( array_unique( $own ) as $tt_id ) {
					$relations[] = array(
						'object_id'        => $post_id,
						'term_taxonomy_id' => $tt_id,
						'term_order'       => 0,
					);
				}

				// Мета AIOSEO — у двух записей из пяти, как на сайте, где описания заполняли не везде.
				if ( $n % 5 < 2 ) {
					$aioseo[] = array(
						'post_id'             => $post_id,
						'title'               => 0 === $n % 2 ? '#post_title #separator_sa #site_title' : 'Колода номер ' . $n . ' — гайд и мулиган',
						'description'         => 'Описание гайда номер ' . $n . ': состав колоды, мулиган, советы по игре против популярных архетипов.',
						'keywords'            => '',
						'keyphrases'          => wp_json_encode( array( 'focus' => array( 'keyphrase' => 'колода ' . $n ), 'additional' => array() ) ),
						'canonical_url'       => '',
						'og_title'            => '',
						'og_description'      => '',
						'og_image_type'       => 'default',
						'og_image_custom_url' => '',
						'robots_default'      => 0 === $n % 50 ? 0 : 1,
						'robots_noindex'      => 0 === $n % 50 ? 1 : 0,
						'robots_nofollow'     => 0,
						'pillar_content'      => 0,
					);
				}
			}

			indexpilot_load_insert( $wpdb->term_relationships, $relations );
			if ( $aioseo ) {
				indexpilot_load_insert( $table, $aioseo );
			}
			$wpdb->query( 'COMMIT' );

			$out['inserted'] = count( $ids );
			$out['aioseo']   = count( $aioseo );
			break;

		case 'decks':
			$terms = indexpilot_load_term_ids();
			$total = max( $from + $count, (int) ( $_GET['total'] ?? 5000 ) );
			$rows  = array();
			$slugs = array();

			for ( $n = $from + 1; $n <= $from + $count; $n++ ) {
				mt_srand( 100000 + $n );
				$slug    = 'lt-deck-' . $n;
				$slugs[] = $slug;
				$class   = INDEXPILOT_LOAD_CLASSES[ $n % count( INDEXPILOT_LOAD_CLASSES ) ];
				$content = 0 === $n % 3 ? indexpilot_load_text( 1, mt_rand( 40, 120 ) ) : '';
				$rows[]  = indexpilot_load_post_row( 'hs_deck', $slug, 'Архетип ' . ( 1 + $n % INDEXPILOT_LOAD_ARCHETYPES ) . ' ' . $class . ' #' . $n, $content, indexpilot_load_date( $n, $total ) );
			}

			$wpdb->query( 'START TRANSACTION' );
			indexpilot_load_insert( $wpdb->posts, $rows );
			$ids = indexpilot_load_ids( 'hs_deck', $slugs );

			$relations = array();
			$meta      = array();

			for ( $n = $from + 1; $n <= $from + $count; $n++ ) {
				$post_id = $ids[ 'lt-deck-' . $n ] ?? 0;
				if ( 0 === $post_id ) {
					continue;
				}

				$archetype = $n % INDEXPILOT_LOAD_ARCHETYPES;

				foreach ( array( $terms['deck_class'][ $n % count( $terms['deck_class'] ) ], $terms['deck_mode'][ $n % 2 ], $terms['deck_archetype'][ $archetype ] ) as $tt_id ) {
					$relations[] = array(
						'object_id'        => $post_id,
						'term_taxonomy_id' => $tt_id,
						'term_order'       => 0,
					);
				}

				foreach (
					array(
						'_dust_cost'        => (string) ( 1000 + ( $n * 37 ) % 15000 ),
						'_deck_player'      => 0 === $n % 7 ? 'Unknown' : 'Игрок ' . ( $n % 300 ),
						'_hs_archetype_key' => 'archetype-' . ( 1 + $archetype ),
						'_deck_wins'        => (string) ( $n % 90 ),
						'_deck_losses'      => (string) ( $n % 70 ),
					) as $key => $value
				) {
					$meta[] = array(
						'post_id'    => $post_id,
						'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- тестовый стенд.
						'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- тестовый стенд.
					);
				}
			}

			indexpilot_load_insert( $wpdb->term_relationships, $relations );
			indexpilot_load_insert( $wpdb->postmeta, $meta );
			$wpdb->query( 'COMMIT' );

			$out['inserted'] = count( $ids );
			break;

		case 'finish':
			// Счётчики терминов — одним запросом, как их пересчитал бы WordPress.
			$wpdb->query( "UPDATE {$wpdb->term_taxonomy} SET count = ( SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE {$wpdb->term_relationships}.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id )" );
			wp_cache_flush();
			delete_option( 'category_children' );

			$out['posts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
			$out['decks'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'hs_deck' AND post_status = 'publish'" );
			$out['aioseo'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aioseo_posts" );
			$out['avg_content_bytes'] = (int) $wpdb->get_var( "SELECT AVG(LENGTH(post_content)) FROM {$wpdb->posts} WHERE post_type = 'post'" );
			break;

		default:
			throw new RuntimeException( 'Неизвестный шаг. Шаги: terms, posts, decks, finish.' );
	}
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' );
	$out['error'] = $error->getMessage();
}

$out['seconds'] = round( microtime( true ) - $start, 2 );
$out['peak_mb'] = round( memory_get_peak_usage( true ) / 1048576, 1 );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
