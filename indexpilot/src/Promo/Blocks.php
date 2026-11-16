<?php
/**
 * Блоки перехода в тексте записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Вставляет в текст записи блоки, зовущие на площадку: не больше двух на статью, по первым подходящим правилам.
 * Блок собирается на сервере — без скриптов и файлов стилей. Правила и площадки читаются из автозагружаемых
 * опций, мета и термины записи уже загружены вместе с ней, поэтому запросов к базе вывод не добавляет.
 */
final class Blocks {

	public const META_OFF = '_indexpilot_promo_off';

	/**
	 * Конструктор.
	 *
	 * @param Destinations $destinations Площадки.
	 * @param Rules        $rules        Правила блоков.
	 * @param ClickRouter  $router       Адреса перехода.
	 */
	public function __construct( private Destinations $destinations, private Rules $rules, private ClickRouter $router ) {
	}

	/**
	 * Подключает вставку блоков: после абзацев и шорткодов, перед блоком «Об авторе».
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'filter' ), 20 );
	}

	/**
	 * HTML одного блока. Текст и подпись кнопки — обычный текст, разметка в них не выполняется.
	 *
	 * @param string $text   Текст варианта.
	 * @param string $button Подпись кнопки.
	 * @param string $url    Адрес перехода.
	 */
	public static function html( string $text, string $button, string $url ): string {
		$escape = static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );

		return sprintf(
			'<aside class="indexpilot-promo" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid rgba(128,128,128,.45);border-radius:8px">%1$s<p style="margin:0"><a class="indexpilot-promo-button" rel="nofollow" href="%2$s" style="display:inline-block;padding:.45em 1.1em;border:1px solid currentColor;border-radius:6px;font-weight:600;text-decoration:none">%3$s</a></p></aside>',
			'' !== $text ? '<p style="margin:0 0 .75em">' . $escape( $text ) . '</p>' : '',
			$escape( $url ),
			$escape( $button )
		);
	}

	/**
	 * Выводится ли сейчас текст основной записи на её собственной странице: не лента, не REST, не админка, не список записей.
	 */
	public static function is_main_post(): bool {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return is_singular() && in_the_loop() && is_main_query() && get_the_ID() === get_queried_object_id();
	}

	/**
	 * Вставляет блоки в текст записи.
	 *
	 * @param string $content Текст записи.
	 * @return string
	 */
	public function filter( $content ) {
		if ( ! self::is_main_post() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || post_password_required( $post ) || '' !== (string) get_post_meta( $post->ID, self::META_OFF, true ) ) {
			return $content;
		}

		$blocks = $this->blocks( $post );

		return array() === $blocks ? $content : Placement::insert( (string) $content, $blocks );
	}

	/**
	 * Блоки для записи по первым подходящим правилам.
	 *
	 * @param \WP_Post $post Запись.
	 * @return list<array{place: string, html: string}>
	 */
	public function blocks( \WP_Post $post ): array {
		$rules = $this->rules->all();

		if ( array() === $rules ) {
			return array();
		}

		$destinations = array_column( $this->destinations->all(), null, 'code' );
		$day          = intdiv( time(), DAY_IN_SECONDS );
		$blocks       = array();

		foreach ( Rule::select( $rules, $this->facts( $post, $rules ) ) as $rule ) {
			$destination = $destinations[ $rule['destination'] ] ?? null;

			if ( null === $destination ) {
				continue;
			}

			$index   = Rule::variant( $rule, (int) $post->ID, $day );
			$variant = $rule['variants'][ $index ] ?? $rule['variants'][0];
			$button  = '' !== (string) $variant['button'] ? (string) $variant['button'] : (string) $destination['name'];

			$blocks[] = array(
				'place' => (string) $rule['place'],
				'html'  => self::html(
					(string) $variant['text'],
					'' !== $button ? $button : __( 'Перейти', 'indexpilot' ),
					$this->router->url( (string) $destination['code'], (int) $post->ID, (int) $rule['id'], $index )
				),
			);
		}

		return $blocks;
	}

	/**
	 * Факты о записи, по которым подбираются правила.
	 *
	 * @param \WP_Post                   $post  Запись.
	 * @param list<array<string, mixed>> $rules Правила: термины читаются только по таксономиям, которые в них названы.
	 * @return array{post_type: string, terms: array<string, list<int>>, game: string, material: string}
	 */
	private function facts( \WP_Post $post, array $rules ): array {
		$facts = array(
			'post_type' => (string) $post->post_type,
			'terms'     => array(),
			'game'      => '',
			'material'  => '',
		);

		foreach ( $rules as $rule ) {
			foreach ( array_keys( (array) $rule['terms'] ) as $taxonomy ) {
				if ( ! isset( $facts['terms'][ $taxonomy ] ) ) {
					$facts['terms'][ (string) $taxonomy ] = $this->term_ids( $post, (string) $taxonomy );
				}
			}
		}

		/**
		 * Факты о записи для подбора блоков перехода. Игру и тип материала сюда добавляет игровой модуль.
		 *
		 * @param array    $facts Тип записи, термины по таксономиям, игра, тип материала.
		 * @param \WP_Post $post  Запись.
		 */
		$filtered = apply_filters( 'indexpilot_promo_facts', $facts, $post );

		return is_array( $filtered ) ? $filtered + $facts : $facts;
	}

	/**
	 * ID терминов записи в таксономии.
	 *
	 * @param \WP_Post $post     Запись.
	 * @param string   $taxonomy Таксономия.
	 * @return list<int>
	 */
	private function term_ids( \WP_Post $post, string $taxonomy ): array {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}

		// Связи записи с терминами WordPress загружает вместе с самой записью. Нужны только ID,
		// поэтому они берутся из этой памяти напрямую: get_the_terms() ради объектов терминов сделал бы запрос.
		$ids = wp_cache_get( $post->ID, $taxonomy . '_relationships' );

		if ( ! is_array( $ids ) ) {
			$terms = get_the_terms( $post, $taxonomy );
			$ids   = is_array( $terms ) ? wp_list_pluck( $terms, 'term_id' ) : array();
		}

		return array_values( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) );
	}
}
