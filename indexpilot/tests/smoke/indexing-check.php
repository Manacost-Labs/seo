<?php
/**
 * Регрессии индексации на тестовом сайте: фильтр robots, карта после импорта, страница записей.
 */

use IndexPilot\Frontend\Head;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;

require '/wordpress/wp-load.php';

$plugin = Plugin::instance();
$id     = wp_insert_post( array( 'post_title' => 'Indexing regression', 'post_status' => 'publish' ) );
$url    = get_permalink( $id );
$in_map = static function () use ( $plugin, $url ): bool {
	$xml = $plugin->sitemap()->render( array( 'kind' => 'pt', 'name' => 'post', 'page' => 1 ) );
	return str_contains( (string) $xml, '<loc>' . esc_html( $url ) . '</loc>' );
};

$checks = array( 'warm_sitemap' => $in_map() );
foreach ( array( MetaKeys::NOINDEX => '1', MetaKeys::CANONICAL => home_url( '/other/' ) ) as $key => $value ) {
	add_post_meta( $id, $key, $value );
	$checks[ $key . '_add' ] = ! $in_map();
	update_post_meta( $id, $key, MetaKeys::NOINDEX === $key ? '0' : $url );
	$checks[ $key . '_update' ] = $in_map();
	update_post_meta( $id, $key, $value );
	$checks[ $key . '_rewarm' ] = ! $in_map();
	delete_post_meta( $id, $key );
	$checks[ $key . '_delete' ] = $in_map();
}

$term     = get_term_by( 'slug', 'gajdy', 'category' );
$term_url = get_term_link( $term );
$term_in_map = static function () use ( $plugin, $term_url ): bool {
	$xml = $plugin->sitemap()->render( array( 'kind' => 'tax', 'name' => 'category', 'page' => 1 ) );
	return str_contains( (string) $xml, '<loc>' . esc_html( $term_url ) . '</loc>' );
};
$checks['term_warm'] = $term_in_map();
add_term_meta( $term->term_id, MetaKeys::CANONICAL, home_url( '/other/' ) );
$checks['term_excluded'] = ! $term_in_map();
update_term_meta( $term->term_id, MetaKeys::CANONICAL, $term_url );
$checks['term_changed'] = $term_in_map();
update_term_meta( $term->term_id, MetaKeys::CANONICAL, home_url( '/other/' ) );
$checks['term_excluded_again'] = ! $term_in_map();
delete_term_meta( $term->term_id, MetaKeys::CANONICAL );
$checks['term_restored'] = $term_in_map();

// Если импорт закончился во время сборки XML, старый результат не годится для следующего чтения.
$cache = new IndexPilot\Sitemap\Cache();
$cache->remember( 'race-check', static function () use ( $cache ) {
	$cache->flush();
	return 'previous';
} );
$checks['concurrent_flush'] = 'current' === $cache->remember( 'race-check', static fn() => 'current' );

// Долгий импорт считает опцию отсутствующей в своей памяти, а соседний запрос уже заполнил карту.
$generation_key = 'indexpilot_sitemap_generation';
wp_cache_delete( $generation_key, 'options' );
$absent = (array) wp_cache_get( 'notoptions', 'options' );
$absent[ $generation_key ] = true;
wp_cache_set( 'notoptions', $absent, 'options' );
$cache->flush();
wp_cache_delete( 'notoptions', 'options' );
$checks['bulk_worker_flush'] = 'after_import' === $cache->remember( 'race-check', static fn() => 'after_import' );

$head = new Head( $plugin->resolver(), $plugin->settings() );
$robots = $head->robots( array( 'noindex' => true, 'nofollow' => true, 'nosnippet' => true, 'noarchive' => true ) );
$checks['foreign_restrictions'] = isset( $robots['noindex'], $robots['nofollow'], $robots['nosnippet'], $robots['noarchive'] )
	&& ! isset( $robots['index'] ) && ! isset( $robots['follow'] );
$robots = $head->robots( array( 'max-image-preview' => 'standard', 'max-snippet' => '35', 'max-video-preview' => '0' ) );
$checks['preview_limits'] = 'standard' === $robots['max-image-preview'] && '35' === (string) $robots['max-snippet'] && '0' === (string) $robots['max-video-preview'];

$old_front = get_option( 'show_on_front' );
$old_blog  = (int) get_option( 'page_for_posts' );
$blog      = wp_insert_post( array( 'post_title' => 'Audit blog', 'post_status' => 'publish', 'post_type' => 'page' ) );
try {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_for_posts', $blog );
	$canonical = $plugin->resolver()->own_url( new Context( Context::POST, $blog, 'page', 2 ) );
	$checks['posts_page_canonical'] = trailingslashit( get_permalink( $blog ) ) . 'page/2/' === $canonical;
} finally {
	update_option( 'page_for_posts', $old_blog );
	update_option( 'show_on_front', $old_front );
	wp_delete_post( $blog, true );
	wp_delete_post( $id, true );
}

wp_send_json( $checks );
