<?php
/**
 * Платное и закрытое содержимое в разметке.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

/**
 * Запись с закрытой частью (шорткод замка вроде Locker, платный доступ) помечается в разметке:
 * у узла статьи isAccessibleForFree: false и hasPart с cssSelector закрытого блока. Без пометки поисковик может
 * счесть разницу между тем, что видит он, и тем, что видит читатель, обманом.
 *
 * Закрытый блок узнаётся по классу: вывод шорткода замка оборачивается в элемент с классом indexpilot-locked
 * (фильтр WordPress do_shortcode_tag). Если замок сделан не шорткодом (скриптом в браузере, блоком редактора),
 * обёртки нет — тогда в разметку попадает только isAccessibleForFree, без hasPart.
 */
final class Paywall {

	/**
	 * Класс элемента вокруг закрытого блока.
	 */
	public const CSS_CLASS = 'indexpilot-locked';

	/**
	 * Известные шорткоды замков. Тот же список, что вырезается из Markdown-версии и описания.
	 */
	public const SHORTCODES = array( 'locker', 'sociallocker', 'emaillocker', 'signinlocker', 'onp-sl', 'onp-sl-locker', 'content_locker', 'paywall', 'premium', 'members', 'members_only', 'restrict', 'private', 'boosty' );

	/**
	 * Части имени, по которым шорткод считается замком (как в Text\Cleaner).
	 */
	private const PATTERN = '/lock|paywall|premium|restrict|members|boosty/i';

	/**
	 * Шорткод — замок закрытого текста. Имена с «block» (блоки редактора, цитаты) замками не считаются.
	 *
	 * @param string $tag Имя шорткода.
	 */
	public static function is_lock( string $tag ): bool {
		$tag = strtolower( $tag );

		if ( in_array( $tag, self::SHORTCODES, true ) ) {
			return true;
		}

		return ! str_contains( $tag, 'block' ) && 1 === preg_match( self::PATTERN, $tag );
	}

	/**
	 * Есть ли в тексте записи закрытая часть.
	 *
	 * @param string $content Текст записи.
	 */
	public static function has_locked( string $content ): bool {
		if ( ! str_contains( $content, '[' ) ) {
			return false;
		}

		preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_\-]*)(?=[\s\]\/])/', $content, $found );

		foreach ( $found[1] as $tag ) {
			if ( self::is_lock( (string) $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Узел статьи с пометкой платного содержимого.
	 *
	 * @param array<string, mixed> $node    Узел Article (или его подтип).
	 * @param bool                 $wrapped Обёрнут ли закрытый блок элементом с классом.
	 * @return array<string, mixed>
	 */
	public static function mark( array $node, bool $wrapped ): array {
		$node['isAccessibleForFree'] = false;

		if ( $wrapped ) {
			$node['hasPart'] = array(
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => '.' . self::CSS_CLASS,
			);
		}

		return $node;
	}

	/**
	 * Вывод шорткода замка в обёртке с классом.
	 *
	 * @param string $output Вывод шорткода.
	 */
	public static function wrap( string $output ): string {
		return '<div class="' . self::CSS_CLASS . '">' . $output . '</div>';
	}

	/**
	 * Подключает обёртку замков и пометку в разметке. Вызывается на template_redirect.
	 * Текст записи уже загружен основным запросом — запросов к базе нет.
	 */
	public function register(): void {
		if ( ! is_singular() ) {
			return;
		}

		add_filter(
			'do_shortcode_tag',
			static function ( $output, $tag ) {
				return is_string( $output ) && self::is_lock( (string) $tag ) && ! str_contains( $output, self::CSS_CLASS ) ? self::wrap( $output ) : $output;
			},
			20,
			2
		);
		add_action( 'indexpilot_schema_graph', array( $this, 'schema' ), 20, 2 );
	}

	/**
	 * Помечает узел статьи, если у записи есть закрытая часть.
	 *
	 * @param mixed $graph   Граф страницы.
	 * @param mixed $context Контекст страницы.
	 */
	public function schema( $graph, $context ): void {
		if ( ! $graph instanceof Graph || ! $context instanceof \IndexPilot\Resolver\Context || \IndexPilot\Resolver\Context::POST !== $context->type ) {
			return;
		}

		$post = get_post( $context->object_id );

		if ( ! $post instanceof \WP_Post || ! self::has_locked( (string) $post->post_content ) ) {
			return;
		}

		global $shortcode_tags;

		// Обёртка появится, только если замок зарегистрирован как шорткод: иначе селектору нечего найти.
		$wrapped = false;

		foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
			if ( self::is_lock( (string) $tag ) && has_shortcode( (string) $post->post_content, (string) $tag ) ) {
				$wrapped = true;
				break;
			}
		}

		foreach ( $graph->nodes() as $node ) {
			if ( str_ends_with( (string) $node['@id'], '#article' ) ) {
				$graph->add( self::mark( $node, $wrapped ) );
			}
		}
	}
}
