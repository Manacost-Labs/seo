<?php
/**
 * Блок «Вопросы и ответы».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Promo\Blocks;
use IndexPilot\Settings\Settings;

/**
 * Выводит пары «вопрос — ответ» из панели записи видимым блоком: заголовок, вопросы подзаголовками, ответы абзацами.
 * Блок нужен читателю и ассистентам, которые ищут готовый ответ на вопрос; расширенных результатов в Google он не даёт.
 *
 * Вывод: шорткод [indexpilot_faq] или автоматически в конце статьи (настройка). Разметка FAQPage добавляется
 * только на той странице, где блок действительно выведен: правило размещения одно для блока и для разметки.
 */
final class FaqBlock {

	public const SHORTCODE = 'indexpilot_faq';
	public const MAX_ITEMS = 10;

	/**
	 * Разделитель страниц записи.
	 */
	private const PAGE_BREAK = '<!--nextpage-->';

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Приводит пары к виду для хранения: обрезает пробелы, убирает пустые строки, оставляет не больше десяти.
	 * Пара, в которой заполнена только одна половина, сохраняется — редактор допишет её позже.
	 *
	 * @param mixed         $value Значение из формы, REST или базы.
	 * @param callable|null $clean Очистка одной строки: fn( string $text, bool $multiline ): string.
	 * @return list<array{question: string, answer: string}>
	 */
	public static function sanitize( mixed $value, ?callable $clean = null ): array {
		$items = array();

		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = is_scalar( $item['question'] ?? null ) ? (string) $item['question'] : '';
			$answer   = is_scalar( $item['answer'] ?? null ) ? (string) $item['answer'] : '';

			if ( null !== $clean ) {
				$question = (string) $clean( $question, false );
				$answer   = (string) $clean( $answer, true );
			}

			$question = trim( (string) preg_replace( '/\s+/u', ' ', $question ) );
			$answer   = trim( $answer );

			if ( '' !== $question || '' !== $answer ) {
				$items[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}

		return array_slice( $items, 0, self::MAX_ITEMS );
	}

	/**
	 * Собирает пары из полей формы: два параллельных списка — вопросы и ответы.
	 *
	 * @param mixed $form Значение поля формы: array{question: list<string>, answer: list<string>}.
	 * @return list<array{question: mixed, answer: mixed}>
	 */
	public static function from_form( mixed $form ): array {
		if ( ! is_array( $form ) ) {
			return array();
		}

		$questions = array_values( (array) ( $form['question'] ?? array() ) );
		$answers   = array_values( (array) ( $form['answer'] ?? array() ) );
		$items     = array();

		for ( $i = 0, $count = max( count( $questions ), count( $answers ) ); $i < $count; $i++ ) {
			$items[] = array(
				'question' => $questions[ $i ] ?? '',
				'answer'   => $answers[ $i ] ?? '',
			);
		}

		return self::sanitize( $items );
	}

	/**
	 * Пары, в которых есть и вопрос, и ответ: только они выводятся на странице и попадают в разметку.
	 *
	 * @param mixed $items Сохранённые пары.
	 * @return list<array{question: string, answer: string}>
	 */
	public static function complete( mixed $items ): array {
		return array_values(
			array_filter(
				self::sanitize( $items ),
				static fn( array $item ): bool => '' !== $item['question'] && '' !== $item['answer']
			)
		);
	}

	/**
	 * HTML блока. Вопросы и ответы — обычный текст; переводы строк в ответе сохраняются.
	 *
	 * @param list<array{question: string, answer: string}> $items Пары.
	 * @param string                                        $title Заголовок блока.
	 */
	public static function html( array $items, string $title ): string {
		$escape = static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		$body   = '';

		foreach ( self::complete( $items ) as $item ) {
			$body .= '<h3>' . $escape( $item['question'] ) . '</h3><p>' . nl2br( $escape( $item['answer'] ), false ) . '</p>';
		}

		if ( '' === $body ) {
			return '';
		}

		return '<section class="indexpilot-faq" style="margin:2em 0 1.5em">' . ( '' !== trim( $title ) ? '<h2>' . $escape( $title ) . '</h2>' : '' ) . $body . '</section>';
	}

	/**
	 * Где блок выводится на заданной странице записи: «shortcode» — на месте шорткода, «auto» — в конце текста,
	 * пустая строка — блока на этой странице нет.
	 *
	 * @param string $content Исходный текст записи.
	 * @param int    $page    Номер страницы записи, разбитой на части.
	 * @param bool   $auto    Включена ли автоматическая вставка.
	 */
	public static function placement( string $content, int $page, bool $auto ): string {
		$parts   = explode( self::PAGE_BREAK, $content );
		$current = $parts[ max( 1, $page ) - 1 ] ?? '';
		$pattern = '/\[' . self::SHORTCODE . '(?=[\s\]\/])/';

		if ( 1 === preg_match( $pattern, $current ) ) {
			return 'shortcode';
		}

		// Шорткод стоит на другой странице записи: второй раз блок не выводится.
		if ( 1 === preg_match( $pattern, $content ) ) {
			return '';
		}

		return $auto && count( $parts ) === max( 1, $page ) ? 'auto' : '';
	}

	/**
	 * Регистрирует шорткод и автоматическую вставку. Вызывается на хуке init.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, fn(): string => $this->render( (int) get_the_ID() ) );

		// После обработки шорткодов (11) и до блоков перехода (20): вопросы — часть статьи.
		add_filter( 'the_content', array( $this, 'append' ), 12 );
	}

	/**
	 * Добавляет блок в конец текста записи, если он не вставлен шорткодом.
	 *
	 * @param string $content Текст записи.
	 * @return string
	 */
	public function append( $content ) {
		$content = (string) $content;

		if ( ! Blocks::is_main_post() ) {
			return $content;
		}

		global $page;

		$post_id = (int) get_the_ID();

		return 'auto' === $this->where( $post_id, max( 1, (int) $page ) ) ? $content . $this->render( $post_id ) : $content;
	}

	/**
	 * Пары, которые выведены на заданной странице записи. Пустой список — блока на странице нет, значит нет и разметки.
	 *
	 * @param int $post_id ID записи.
	 * @param int $page    Номер страницы записи.
	 * @return list<array{question: string, answer: string}>
	 */
	public function visible_items( int $post_id, int $page = 1 ): array {
		return '' !== $this->where( $post_id, $page ) ? $this->items( $post_id ) : array();
	}

	/**
	 * HTML блока для записи.
	 *
	 * @param int $post_id ID записи.
	 */
	public function render( int $post_id ): string {
		return self::html( $this->items( $post_id ), __( 'Вопросы и ответы', 'indexpilot' ) );
	}

	/**
	 * Размещение блока на странице записи с учётом настройки.
	 *
	 * @param int $post_id ID записи.
	 * @param int $page    Номер страницы записи.
	 */
	private function where( int $post_id, int $page ): string {
		if ( array() === $this->items( $post_id ) ) {
			return '';
		}

		return self::placement( (string) get_post_field( 'post_content', $post_id ), $page, (bool) $this->settings->general( 'faq_block_auto' ) );
	}

	/**
	 * Готовые к выводу пары записи; у записи под паролем их нет.
	 *
	 * @param int $post_id ID записи.
	 * @return list<array{question: string, answer: string}>
	 */
	private function items( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return array();
		}

		return self::complete( get_post_meta( $post->ID, MetaKeys::FAQ, true ) );
	}
}
