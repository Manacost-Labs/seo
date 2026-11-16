<?php
/**
 * Тексты рубрики над и под списком записей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

use IndexPilot\Meta\MetaKeys;

/**
 * Выводит вступление и нижний текст термина на первой странице его архива.
 *
 * Вступление подменяет описание термина, которое тема показывает над списком.
 * Нижний текст печатается после основного цикла записей; если тема цикл не использует,
 * его можно вывести функцией indexpilot_archive_outro().
 */
final class ArchiveText {

	/**
	 * Нижний текст уже напечатан на этой странице.
	 *
	 * @var bool
	 */
	private bool $outro_printed = false;

	/**
	 * Подключает вывод. Вызывается на хуке template_redirect.
	 */
	public function register(): void {
		if ( null === $this->term() ) {
			return;
		}

		add_filter( 'term_description', array( $this, 'intro' ), 20, 2 );
		add_action( 'loop_end', array( $this, 'print_outro_after_loop' ) );
	}

	/**
	 * Подменяет описание термина вступлением.
	 *
	 * @param string $description Описание термина.
	 * @param int    $term_id     ID термина, для которого запрошено описание.
	 */
	public function intro( $description, $term_id = 0 ) {
		$term = $this->term();

		if ( null === $term || (int) $term_id !== (int) $term->term_id ) {
			return $description;
		}

		$intro = $this->text( MetaKeys::INTRO );
		$intro = '' !== $intro ? $intro : (string) $description;

		if ( is_paged() ) {
			return $intro;
		}

		/**
		 * Текст над списком записей на первой странице архива: сюда добавляются свои блоки, например лучшие колоды
		 * на странице класса.
		 *
		 * @param string   $intro Вступление или описание термина.
		 * @param \WP_Term $term  Открытый термин.
		 */
		return (string) apply_filters( 'indexpilot_archive_top', $intro, $term );
	}

	/**
	 * Печатает нижний текст после основного цикла записей.
	 *
	 * @param \WP_Query $query Запрос, цикл которого закончился.
	 */
	public function print_outro_after_loop( $query ): void {
		if ( $query instanceof \WP_Query && $query->is_main_query() ) {
			$this->print_outro();
		}
	}

	/**
	 * Печатает нижний текст один раз за страницу.
	 */
	public function print_outro(): void {
		if ( $this->outro_printed ) {
			return;
		}

		$outro = $this->text( MetaKeys::OUTRO );
		if ( '' === $outro ) {
			return;
		}

		$this->outro_printed = true;

		echo '<div class="indexpilot-archive-text indexpilot-archive-text--bottom">' . wp_kses_post( $outro ) . '</div>';
	}

	/**
	 * Текст термина, готовый к выводу; пустая строка, если текста нет или это не первая страница.
	 *
	 * @param string $meta_key Ключ меты: вступление или нижний текст.
	 */
	private function text( string $meta_key ): string {
		$term = $this->term();

		if ( null === $term || is_paged() ) {
			return '';
		}

		$text = trim( (string) get_term_meta( $term->term_id, $meta_key, true ) );

		// Шорткоды работают и здесь: например, [indexpilot_hub] ставит блок лучших колод в нужное место текста.
		return '' === $text ? '' : do_shortcode( shortcode_unautop( wpautop( wp_kses_post( $text ) ) ) );
	}

	/**
	 * Термин, архив которого сейчас открыт.
	 */
	private function term(): ?\WP_Term {
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return null;
		}

		$term = get_queried_object();

		return $term instanceof \WP_Term ? $term : null;
	}
}
