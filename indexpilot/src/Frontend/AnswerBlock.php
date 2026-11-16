<?php
/**
 * Блок «Коротко» — краткий ответ в начале статьи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

use IndexPilot\Game\PostMaterial;
use IndexPilot\Game\SiteGames;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Promo\Blocks;
use IndexPilot\Settings\Settings;
use IndexPilot\Text\InlineShortcodes;

/**
 * Показывает в начале статьи краткий ответ из панели записи и дату обновления. Читатель сразу видит суть,
 * а поисковик и ассистент получают готовый самостоятельный абзац для цитаты.
 *
 * Вывод: автоматически перед первым абзацем (настройка) или шорткодом [indexpilot_answer]. Блок выводится один раз.
 * Оформление — атрибутами style: на страницах сайта плагин не подключает ни скриптов, ни файлов стилей.
 */
final class AnswerBlock {

	public const SHORTCODE = 'indexpilot_answer';
	public const MIN_WORDS = 40;
	public const MAX_WORDS = 60;

	/**
	 * Класс блока: по нему же определяется, что блок уже есть в тексте.
	 */
	private const MARKER = 'indexpilot-answer';

	/**
	 * Блок целиком. Вложенных details в нём нет, поэтому первый закрывающий тег — его конец.
	 */
	private const BLOCK_PATTERN = '#<details class="indexpilot-spoiler indexpilot-answer"[^>]*>.*?</details>#su';

	/**
	 * Теги без закрывающей пары: вложенность не меняют.
	 */
	private const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Число слов в тексте: всё, что разделено пробелами и содержит букву или цифру.
	 *
	 * @param string $text Текст.
	 */
	public static function word_count( string $text ): int {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return count( array_filter( is_array( $words ) ? $words : array(), static fn( string $word ): bool => 1 === preg_match( '/[\p{L}\p{N}]/u', $word ) ) );
	}

	/**
	 * Укладывается ли ответ в рекомендуемый объём.
	 *
	 * @param string $text Краткий ответ.
	 */
	public static function fits( string $text ): bool {
		$words = self::word_count( $text );

		return $words >= self::MIN_WORDS && $words <= self::MAX_WORDS;
	}

	/**
	 * HTML блока. Ответ — обычный текст, разметка в нём не выполняется.
	 *
	 * @param string $answer   Краткий ответ.
	 * @param string $heading  Заголовок блока.
	 * @param string $updated  Подпись даты: «Обновлено».
	 * @param string $date     Дата обновления в том виде, в каком её видит читатель.
	 * @param string $datetime Та же дата в машинном виде.
	 * @param callable( string ): string|null $shortcode Выполнение шорткода (do_shortcode): карты в ответе становятся подсказками.
	 */
	public static function html( string $answer, string $heading, string $updated = '', string $date = '', string $datetime = '', ?callable $shortcode = null ): string {
		$escape = static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		$answer = trim( (string) preg_replace( '/\s+/u', ' ', $answer ) );

		if ( '' === $answer ) {
			return '';
		}

		$stamp = '';

		if ( '' !== $date ) {
			$stamp = sprintf(
				'<p class="indexpilot-answer-date" style="font-size:.85em;opacity:.7">%s<time datetime="%s">%s</time></p>',
				'' !== $updated ? $escape( $updated ) . ' ' : '',
				$escape( '' !== $datetime ? $datetime : $date ),
				$escape( $date )
			);
		}

		// Спойлер в стиле сайта: свёрнут, текст в HTML и виден поисковикам.
		return Spoiler::html(
			self::MARKER,
			$escape( $heading ),
			'<p class="indexpilot-answer-text">' . InlineShortcodes::html( $answer, $shortcode ) . '</p>' . $stamp
		);
	}

	/**
	 * Ставит блок в текст записи: один раз и перед первым абзацем. Если блок уже есть в тексте (вставлен шорткодом),
	 * он остаётся на своём месте, а повторы убираются.
	 *
	 * @param string $content Текст записи после обработки шорткодов.
	 * @param string $block   HTML блока.
	 */
	public static function insert( string $content, string $block ): string {
		if ( str_contains( $content, 'class="indexpilot-spoiler ' . self::MARKER . '"' ) ) {
			$seen = 0;

			return (string) preg_replace_callback(
				self::BLOCK_PATTERN,
				static function ( array $found ) use ( &$seen ): string {
					return 1 === ++$seen ? $found[0] : '';
				},
				$content
			);
		}

		if ( '' === $block ) {
			return $content;
		}

		$offset = self::first_paragraph( $content );

		return substr( $content, 0, $offset ) . $block . substr( $content, $offset );
	}

	/**
	 * Позиция первого абзаца верхнего уровня: абзац внутри подписи к картинке или цитаты началом статьи не считается.
	 * Если такого абзаца нет, блок встаёт в самое начало.
	 *
	 * @param string $content Текст записи.
	 */
	private static function first_paragraph( string $content ): int {
		if ( ! preg_match_all( '#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', $content, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return 0;
		}

		$depth = 0;

		foreach ( $tags as $tag ) {
			$name = strtolower( $tag[2][0] );

			if ( in_array( $name, self::VOID_TAGS, true ) || '/' === $tag[3][0] ) {
				continue;
			}

			if ( '/' === $tag[1][0] ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}

			if ( 'p' === $name && 0 === $depth ) {
				return (int) $tag[0][1];
			}

			++$depth;
		}

		return 0;
	}

	/**
	 * Регистрирует шорткод и автоматическую вставку. Вызывается на хуке init.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, fn(): string => $this->render( (int) get_the_ID() ) );
		// Стиль спойлера — в head (один раз, даже если подключён обоими блоками).
		add_action( 'wp_head', array( Spoiler::class, 'print_style' ) );

		// После обработки шорткодов (11) и до блоков перехода (20): «первый абзац» — это абзац самой статьи.
		add_filter( 'the_content', array( $this, 'filter' ), 12 );
	}

	/**
	 * Оставляет в тексте один блок и, если включено, сам ставит его перед первым абзацем.
	 *
	 * @param string $content Текст записи.
	 * @return string
	 */
	public function filter( $content ) {
		$content = (string) $content;

		if ( ! Blocks::is_main_post() ) {
			return $content;
		}

		global $page;

		// Автоматически блок выводится только на первой странице записи, разбитой на части.
		$auto = $this->settings->general( 'answer_block_auto' ) && (int) $page <= 1;

		return self::insert( $content, $auto ? $this->render( (int) get_the_ID() ) : '' );
	}

	/**
	 * Краткий ответ записи; пустая строка, если его нет или запись закрыта паролем.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function answer( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return '';
		}

		return trim( (string) get_post_meta( $post->ID, MetaKeys::ANSWER, true ) );
	}

	/**
	 * Подпись перед датой: «Обновлено», а у гайда и колоды, обновлённых после текущего патча, —
	 * «Актуально для патча 37.0, обновлено».
	 *
	 * @param int $post_id ID записи.
	 */
	private function updated_label( int $post_id ): string {
		// Запись раздела другой игры сверяется с патчем своей игры; поле игры читается из кэша полей записи.
		$patch = SiteGames::patch_for( $this->settings, (string) get_post_meta( $post_id, SiteGames::META, true ) );
		$post  = get_post( $post_id );

		if ( $patch->active() && $post instanceof \WP_Post && $patch->current( PostMaterial::detect( $post ), PostDates::modified( 'Y-m-d H:i:s', false, $post ) ) ) {
			/* translators: %s: номер патча. После подписи идёт дата обновления. */
			return sprintf( __( 'Актуально для патча %s, обновлено', 'indexpilot' ), $patch->version );
		}

		return __( 'Обновлено', 'indexpilot' );
	}

	/**
	 * HTML блока для записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public function render( int $post_id ): string {
		$answer = self::answer( $post_id );

		if ( '' === $answer ) {
			return '';
		}

		return self::html(
			$answer,
			__( 'Коротко', 'indexpilot' ),
			$this->updated_label( $post_id ),
			// Дата изменения не раньше даты публикации — та же, что в тегах и разметке (см. PostDates).
			PostDates::modified( (string) get_option( 'date_format' ), false, $post_id, true ),
			PostDates::modified( 'c', false, $post_id ),
			'do_shortcode'
		);
	}
}
