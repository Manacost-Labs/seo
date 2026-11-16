<?php
/**
 * Постановка адресов в очередь IndexNow и отправка по расписанию.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\IndexNow;

use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;

/**
 * При публикации, изменении и снятии записи с публикации её адрес попадает в очередь.
 * Сам запрос к поисковикам уходит отдельной задачей расписания, а не во время сохранения записи.
 */
final class Notifier {

	public const HOOK = 'indexpilot_indexnow_send';

	private const BATCH       = 500;
	private const DELAY       = MINUTE_IN_SECONDS;
	private const RETRY_DELAY = 15 * MINUTE_IN_SECONDS;

	/**
	 * Конструктор.
	 *
	 * @param Queue        $queue    Очередь адресов.
	 * @param Sender       $sender   Отправка.
	 * @param Key          $key      Ключ сайта.
	 * @param MetaResolver $resolver Резолвер: закрытые от индексации страницы не отправляются.
	 * @param Settings     $settings Настройки.
	 * @param Log|null     $log      Журнал отправок.
	 */
	public function __construct(
		private Queue $queue,
		private Sender $sender,
		private Key $key,
		private MetaResolver $resolver,
		private Settings $settings,
		private ?Log $log = null
	) {
		$this->log ??= new Log();
	}

	/**
	 * Очередь адресов.
	 */
	public function queue(): Queue {
		return $this->queue;
	}

	/**
	 * Журнал отправок.
	 */
	public function log(): Log {
		return $this->log;
	}

	/**
	 * Ключ сайта.
	 */
	public function key(): Key {
		return $this->key;
	}

	/**
	 * Отправляет адреса сразу, минуя очередь: для отправки вручную со страницы «Поисковики».
	 *
	 * @param list<string> $urls Адреса страниц этого сайта.
	 * @return array{sent: int, ok: bool, code: int}
	 */
	public function submit( array $urls ): array {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ), 'strlen' ) ) );
		$own  = Sender::payload( home_url( '/' ), $this->key->value(), $urls )['urlList'];

		if ( array() === $own ) {
			return array(
				'sent' => 0,
				'ok'   => true,
				'code' => 0,
			);
		}

		$ok = $this->sender->send( home_url( '/' ), $this->key->value(), $own );

		$this->log->add( count( $own ), $ok, $this->sender->last_code(), $own[0] );

		return array(
			'sent' => count( $own ),
			'ok'   => $ok,
			'code' => $this->sender->last_code(),
		);
	}

	/**
	 * Включена ли отправка: настройка плагина и сайт, открытый для поисковиков.
	 */
	public function enabled(): bool {
		return (bool) $this->settings->general( 'indexnow_enabled' ) && '0' !== (string) get_option( 'blog_public' );
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'send' ) );
		add_action( 'parse_request', array( $this->key, 'handle' ), 1 );

		if ( ! $this->enabled() ) {
			return;
		}

		add_action( 'transition_post_status', array( $this, 'on_status' ), 20, 3 );
		add_action( 'edited_term', array( $this, 'on_term' ), 20, 3 );
		add_action( 'pre_post_update', array( $this, 'before_update' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'before_removal' ) );
		add_action( 'wp_trash_post', array( $this, 'before_removal' ) );
	}

	/**
	 * Запись опубликована или изменена.
	 *
	 * @param string   $new_status Новый статус.
	 * @param string   $old_status Прежний статус.
	 * @param \WP_Post $post       Запись.
	 */
	public function on_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		if ( ! $this->resolver->indexing( new Context( Context::POST, (int) $post->ID, $post->post_type ) )['indexable'] ) {
			return;
		}

		$this->enqueue( (string) get_permalink( $post ) );

		// Новая запись меняет главную страницу и архивы своих терминов: о них тоже стоит сообщить.
		if ( 'publish' !== $old_status ) {
			$this->enqueue( home_url( '/' ) );

			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
				if ( ! is_taxonomy_viewable( $taxonomy ) ) {
					continue;
				}

				$terms = get_the_terms( $post, $taxonomy->name );

				foreach ( is_array( $terms ) ? array_slice( $terms, 0, 5 ) : array() as $term ) {
					$this->enqueue_term( (int) $term->term_id, $taxonomy->name );
				}
			}
		}
	}

	/**
	 * Изменён термин: его архив мог получить новые название, описание или тексты.
	 *
	 * @param int    $term_id  ID термина.
	 * @param int    $tt_id    ID связи термина с таксономией.
	 * @param string $taxonomy Таксономия.
	 */
	public function on_term( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );

		if ( is_taxonomy_viewable( (string) $taxonomy ) ) {
			$this->enqueue_term( (int) $term_id, (string) $taxonomy );
		}
	}

	/**
	 * Запись снимают с публикации: адрес запоминается, пока он ещё настоящий.
	 *
	 * @param int                  $post_id ID записи.
	 * @param array<string, mixed> $data    Новые данные записи.
	 */
	public function before_update( $post_id, $data ): void {
		if ( 'publish' === get_post_status( (int) $post_id ) && 'publish' !== ( $data['post_status'] ?? 'publish' ) ) {
			$this->enqueue_post( (int) $post_id );
		}
	}

	/**
	 * Запись удаляют или отправляют в корзину.
	 *
	 * @param int $post_id ID записи.
	 */
	public function before_removal( $post_id ): void {
		if ( 'publish' === get_post_status( (int) $post_id ) ) {
			$this->enqueue_post( (int) $post_id );
		}
	}

	/**
	 * Отправляет пачку адресов. Вызывается расписанием WordPress.
	 */
	public function send(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$batch = $this->queue->take( self::BATCH );

		if ( array() === $batch ) {
			return;
		}

		$sent = $this->sender->send( home_url( '/' ), $this->key->value(), $batch );

		$this->log->add( count( $batch ), $sent, $this->sender->last_code(), $batch[0] );

		if ( ! $sent ) {
			$this->queue->restore( $batch );
			$this->schedule( self::RETRY_DELAY );

			return;
		}

		if ( $this->queue->count() > 0 ) {
			$this->schedule( self::DELAY );
		}
	}

	/**
	 * Ставит в очередь адрес записи, если у её типа есть страницы на сайте.
	 *
	 * @param int $post_id ID записи.
	 */
	private function enqueue_post( int $post_id ): void {
		if ( is_post_type_viewable( (string) get_post_type( $post_id ) ) ) {
			$this->enqueue( (string) get_permalink( $post_id ) );
		}
	}

	/**
	 * Ставит в очередь архив термина, если он открыт для индексации.
	 *
	 * @param int    $term_id  ID термина.
	 * @param string $taxonomy Таксономия.
	 */
	private function enqueue_term( int $term_id, string $taxonomy ): void {
		$url = get_term_link( $term_id, $taxonomy );

		if ( is_string( $url ) && $this->resolver->indexing( new Context( Context::TERM, $term_id, $taxonomy ) )['indexable'] ) {
			$this->enqueue( $url );
		}
	}

	/**
	 * Добавляет адрес в очередь и назначает отправку.
	 *
	 * @param string $url Адрес страницы.
	 */
	private function enqueue( string $url ): void {
		if ( '' === $url ) {
			return;
		}

		$this->queue->add( $url );
		$this->schedule( self::DELAY );
	}

	/**
	 * Назначает отправку, если она ещё не назначена.
	 *
	 * @param int $delay Через сколько секунд.
	 */
	private function schedule( int $delay ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}
}
