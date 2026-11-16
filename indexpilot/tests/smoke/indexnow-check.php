<?php
/**
 * Проверка IndexNow внутри тестового WordPress: очередь, отправка по расписанию, ключ.
 * Запросы к поисковикам перехватываются и наружу не уходят.
 *
 * Режимы: без параметров — проверка и JSON; ?key=1 — только ключ сайта;
 * ?options=1 — сохранить настройки для проверки robots.txt и тега подтверждения (сбрасываются admin-check.php?reset_settings=1).
 * Файл не входит в сборку плагина.
 */

require '/wordpress/wp-load.php';

use IndexPilot\IndexNow\Key;
use IndexPilot\IndexNow\Notifier;
use IndexPilot\IndexNow\Queue;
use IndexPilot\IndexNow\Sender;
use IndexPilot\Plugin;
use IndexPilot\Settings\Settings;

wp_set_current_user( 1 );

if ( isset( $_GET['key'] ) ) {
	echo ( new Key() )->value();
	exit;
}

if ( isset( $_GET['options'] ) ) {
	update_option(
		Settings::OPTION,
		array(
			'general' => array(
				'block_ai_training' => true,
				'verify_yandex'     => '<meta name="yandex-verification" content="1a2b3c" />',
			),
		)
	);
	echo 'saved';
	exit;
}

set_exception_handler(
	static function ( Throwable $error ): void {
		echo wp_json_encode( array( 'error' => $error->getMessage(), 'where' => $error->getFile() . ':' . $error->getLine() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
);

$plugin   = Plugin::instance();
$requests = array();
$answer   = 200;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests, &$answer ) {
		if ( ! str_starts_with( (string) $url, Sender::ENDPOINT ) ) {
			return $pre;
		}

		$requests[] = json_decode( (string) $args['body'], true );

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => $answer, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

delete_option( Queue::OPTION );
wp_clear_scheduled_hook( Notifier::HOOK );

// По умолчанию отправка выключена: сохранение записи ничего не ставит в очередь.
$post_id = static fn( string $slug ): int => (int) get_page_by_path( $slug, OBJECT, 'post' )->ID;
wp_update_post( array( 'ID' => $post_id( 'gajd-8' ) ) );
$queued_when_disabled = ( new Queue() )->count();

// Включённая отправка.
$queue    = new Queue();
$key      = new Key();
$notifier = new Notifier( $queue, new Sender(), $key, $plugin->resolver(), new Settings( array( 'general' => array( 'indexnow_enabled' => true ) ) ) );
$notifier->register();

$url = get_permalink( $post_id( 'gajd-8' ) );

wp_update_post( array( 'ID' => $post_id( 'gajd-8' ) ) );
wp_update_post( array( 'ID' => $post_id( 'gajd-8' ) ) );
wp_update_post( array( 'ID' => $post_id( 'gajd-8' ) ) );
// Запись, закрытая от индексации, в очередь не попадает.
wp_update_post( array( 'ID' => $post_id( 'gajd-2' ) ) );

$after_saves    = array_keys( (array) get_option( Queue::OPTION, array() ) );
$sent_on_save   = count( $requests );
$scheduled      = false !== wp_next_scheduled( Notifier::HOOK );

// Неудачная отправка: адреса возвращаются в очередь.
$answer = 500;
$notifier->send();
$after_failure = $queue->count();

// Удачная отправка.
$answer = 200;
$notifier->send();
$payload = $requests[ count( $requests ) - 1 ] ?? array();

// Снятие с публикации: уходит настоящий адрес записи, а не адрес черновика.
$gone = get_permalink( $post_id( 'gajd-10' ) );
wp_update_post( array( 'ID' => $post_id( 'gajd-10' ), 'post_status' => 'draft' ) );
$after_unpublish = array_keys( (array) get_option( Queue::OPTION, array() ) );

delete_option( Queue::OPTION );
wp_clear_scheduled_hook( Notifier::HOOK );

header( 'Content-Type: application/json; charset=utf-8' );
echo wp_json_encode(
	array(
		'disabled_by_default'        => 0 === $queued_when_disabled,
		'one_address_after_3_saves'  => array( $url ) === $after_saves,
		'nothing_sent_while_saving'  => 0 === $sent_on_save,
		'sending_is_scheduled'       => $scheduled,
		'failed_batch_is_kept'       => 1 === $after_failure,
		'two_requests_total'         => 2 === count( $requests ),
		'payload_has_address'        => array( $url ) === ( $payload['urlList'] ?? null ),
		'payload_has_key'            => $key->value() === ( $payload['key'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $key->value() ),
		'payload_has_key_location'   => home_url( '/' . $key->value() . '.txt' ) === ( $payload['keyLocation'] ?? null ),
		'queue_is_empty_after_send'  => array( $gone ) === $after_unpublish,
		'unpublished_address_is_real' => ! str_contains( (string) $gone, '?p=' ),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
