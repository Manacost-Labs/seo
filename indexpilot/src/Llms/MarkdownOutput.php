<?php
/**
 * Версия записи в Markdown по адресу с «.md» на конце.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Llms;

use IndexPilot\Frontend\AnswerBlock;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;

/**
 * Отдаёт любую открытую запись чистым Markdown: https://сайт/zapis.md — для AI-ассистентов, которые читают страницы
 * без меню, рекламы и скриптов. В заголовках ответа: noindex (в поиске эта версия не нужна) и канонический адрес
 * статьи. В head статьи печатается ссылка на эту версию.
 *
 * Не отдаются: записи под паролем, закрытые от индексации и неопубликованные. Платные и закрытые части текста
 * (шорткоды замков вроде Locker) вырезаются вместе с содержимым; шорткоды плагина разворачиваются в текст,
 * остальные шорткоды убираются, а их содержимое остаётся.
 */
final class MarkdownOutput {

	public const SUFFIX = '.md';

	/**
	 * Шорткоды, текст внутри которых закрыт от посторонних: он в Markdown не попадает.
	 */
	private const HIDDEN = array( 'locker', 'sociallocker', 'emaillocker', 'signinlocker', 'onp-sl', 'onp-sl-locker', 'content_locker', 'paywall', 'premium', 'members', 'members_only', 'restrict', 'private', 'boosty' );

	/**
	 * Шорткоды плагина, которые в Markdown не нужны: краткий ответ уже стоит под заголовком, а ссылки на соцсети —
	 * часть оформления страницы.
	 */
	private const PLUGIN_SKIP = array( 'indexpilot_answer', 'indexpilot_follow' );

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер: открыта ли запись для индексации и её канонический адрес.
	 * @param Settings     $settings Настройки.
	 */
	public function __construct( private MetaResolver $resolver, private Settings $settings ) {
	}

	/**
	 * Отдаёт Markdown, если запрошен адрес записи с «.md» на конце. Вызывается на хуке parse_request.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- адрес только разбирается и сравнивается с адресами записей.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) strtok( $uri, '?' );

		if ( ! str_ends_with( $path, self::SUFFIX ) || ! $this->settings->general( 'llms_markdown' ) ) {
			return;
		}

		$post = $this->post_for( substr( $path, 0, -strlen( self::SUFFIX ) ) );

		if ( null === $post ) {
			return;
		}

		$canonical = $this->canonical( $post );

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- обычный текст (Content-Type: text/markdown), разметка HTML убрана при сборке.
		echo $this->markdown( $post );
		exit;
	}

	/**
	 * Подключает ссылку на Markdown-версию в head записи. Вызывается на хуке template_redirect.
	 */
	public function register_head(): void {
		if ( ! is_singular() || ! $this->settings->general( 'llms_markdown' ) ) {
			return;
		}

		$post = get_queried_object();

		if ( $post instanceof \WP_Post && $this->eligible( $post ) ) {
			add_action(
				'wp_head',
				static function () use ( $post ): void {
					$url = self::url( $post );

					if ( '' !== $url ) {
						printf( '<link rel="alternate" type="text/markdown" href="%s">' . "\n", esc_url( $url ) );
					}
				},
				3
			);
		}
	}

	/**
	 * Адрес Markdown-версии записи: адрес записи без косой черты в конце плюс «.md». Пусто, если у записи нет
	 * красивого адреса (вида ?p=123).
	 *
	 * @param \WP_Post $post Запись.
	 */
	public static function url( \WP_Post $post ): string {
		$permalink = (string) get_permalink( $post );

		if ( '' === $permalink || str_contains( $permalink, '?' ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $permalink, PHP_URL_PATH );

		// Главная страница сайта, назначенная записью, своего .md-адреса не получает.
		if ( '' === trim( $path, '/' ) ) {
			return '';
		}

		return untrailingslashit( $permalink ) . self::SUFFIX;
	}

	/**
	 * Markdown записи: заголовок, краткий ответ, текст и адрес оригинала.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function markdown( \WP_Post $post ): string {
		global $wp_query;

		// Шорткоды плагина берут запись из глобального состояния, как на её странице.
		$previous = $GLOBALS['post'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- запись подставляется на время сборки текста и затем возвращается.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->queried_object    = $post;
			$wp_query->queried_object_id = (int) $post->ID;
		}

		$text = Markdown::document(
			html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			AnswerBlock::answer( (int) $post->ID ),
			$this->html( $post ),
			/* translators: %s: адрес статьи. */
			sprintf( __( 'Источник: %s', 'indexpilot' ), $this->canonical( $post ) )
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- см. выше.
		$GLOBALS['post'] = $previous;
		wp_reset_postdata();

		return $text;
	}

	/**
	 * HTML текста записи без закрытых частей: шорткоды плагина развёрнуты, остальные убраны.
	 *
	 * @param \WP_Post $post Запись.
	 */
	private function html( \WP_Post $post ): string {
		$content = self::without_hidden( (string) $post->post_content );
		$content = (string) preg_replace( '/<!--\s*(more|nextpage|noteaser)\b.*?-->/s', '', $content );

		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		return wpautop( $this->shortcodes( $content ) );
	}

	/**
	 * Вырезает закрытые шорткоды вместе с содержимым. Если закрывающего тега нет, отбрасывается всё после
	 * открывающего: лучше потерять конец статьи, чем выдать платный текст.
	 *
	 * @param string $content Текст записи.
	 */
	public static function without_hidden( string $content ): string {
		global $shortcode_tags;

		$hidden = self::HIDDEN;

		foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
			if ( 1 === preg_match( '/lock|paywall|premium|restrict|members|boosty/i', (string) $tag ) ) {
				$hidden[] = (string) $tag;
			}
		}

		/**
		 * Шорткоды, текст внутри которых не попадает в Markdown-версию записи.
		 *
		 * @param list<string> $hidden Имена шорткодов.
		 */
		$hidden = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'indexpilot_markdown_hidden_shortcodes', $hidden ) ) ) );

		if ( array() === $hidden ) {
			return $content;
		}

		$content = (string) preg_replace_callback(
			'/' . get_shortcode_regex( $hidden ) . '/',
			static fn( array $found ): string => '[' === $found[1] && ']' === $found[6] ? substr( $found[0], 1, -1 ) : $found[1] . $found[6],
			$content
		);

		$names = implode( '|', array_map( static fn( string $tag ): string => preg_quote( $tag, '/' ), $hidden ) );

		if ( 1 === preg_match( '/\[(?:' . $names . ')(?![\w-])/i', $content, $open, PREG_OFFSET_CAPTURE ) ) {
			$content = substr( $content, 0, (int) $open[0][1] );
		}

		return $content;
	}

	/**
	 * Разворачивает шорткоды плагина; у остальных оставляет только содержимое.
	 *
	 * @param string $content Текст записи.
	 */
	private function shortcodes( string $content ): string {
		global $shortcode_tags;

		if ( ! str_contains( $content, '[' ) || empty( $shortcode_tags ) ) {
			return $content;
		}

		$all  = (array) $shortcode_tags;
		$ours = array();

		foreach ( $all as $tag => $callback ) {
			if ( str_starts_with( (string) $tag, 'indexpilot_' ) && ! in_array( (string) $tag, self::PLUGIN_SKIP, true ) ) {
				$ours[ $tag ] = $callback;
			}
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- на время разбора остаются только шорткоды плагина; затем список возвращается.
		$shortcode_tags = $ours;
		$content        = do_shortcode( $content );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- см. выше.
		$shortcode_tags = $all;

		$others = array_values( array_diff( array_map( 'strval', array_keys( $all ) ), array_map( 'strval', array_keys( $ours ) ) ) );

		if ( array() === $others ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/' . get_shortcode_regex( $others ) . '/',
			static fn( array $found ): string => '[' === $found[1] && ']' === $found[6] ? substr( $found[0], 1, -1 ) : $found[1] . $found[5] . $found[6],
			$content
		);
	}

	/**
	 * Запись по адресу без «.md», если её можно отдать.
	 *
	 * @param string $path Путь адреса без «.md».
	 */
	private function post_for( string $path ): ?\WP_Post {
		if ( '' === trim( $path, '/' ) ) {
			return null;
		}

		$origin  = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$port    = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
		$origin .= null !== $port ? ':' . (int) $port : '';
		$post_id = url_to_postid( $origin . trailingslashit( $path ) );

		if ( 0 === $post_id ) {
			$post_id = url_to_postid( $origin . $path );
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		// Адрес должен совпадать с адресом записи: иначе один текст отдавался бы по многим адресам.
		$same = static fn( string $address ): string => mb_strtolower( rawurldecode( $address ), 'UTF-8' );

		if ( ! $post instanceof \WP_Post || ! $this->eligible( $post ) || $same( (string) wp_parse_url( self::url( $post ), PHP_URL_PATH ) ) !== $same( $path . self::SUFFIX ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Можно ли отдавать запись в Markdown: опубликована, тип записи открыт, нет пароля, страница открыта для индексации.
	 *
	 * @param \WP_Post $post Запись.
	 */
	private function eligible( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) || 'attachment' === $post->post_type ) {
			return false;
		}

		return (bool) $this->resolver->indexing( new Context( Context::POST, (int) $post->ID, $post->post_type ) )['indexable'];
	}

	/**
	 * Канонический адрес статьи.
	 *
	 * @param \WP_Post $post Запись.
	 */
	private function canonical( \WP_Post $post ): string {
		$canonical = (string) $this->resolver->indexing( new Context( Context::POST, (int) $post->ID, $post->post_type ) )['canonical'];

		return '' !== $canonical ? $canonical : (string) get_permalink( $post );
	}
}
