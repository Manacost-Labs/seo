<?php
/**
 * Факты о тексте, извлечённые один раз.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Text\Cleaner;

/**
 * Разбор HTML записи: текст, первый абзац, подзаголовки, картинки, ссылки. Проверки читают готовые поля.
 */
final class Facts {

	/**
	 * Видимый текст записи вместе с подзаголовками.
	 *
	 * @var string
	 */
	public string $text;

	/**
	 * Число слов в тексте.
	 *
	 * @var int
	 */
	public int $word_count;

	/**
	 * Текст первого абзаца.
	 *
	 * @var string
	 */
	public string $first_paragraph;

	/**
	 * Тексты подзаголовков h2–h4.
	 *
	 * @var list<string>
	 */
	public array $subheadings = array();

	/**
	 * Атрибуты alt всех картинок (пустая строка, если alt не задан).
	 *
	 * @var list<string>
	 */
	public array $image_alts = array();

	/**
	 * Число внутренних ссылок.
	 *
	 * @var int
	 */
	public int $internal_links = 0;

	/**
	 * Число внешних ссылок.
	 *
	 * @var int
	 */
	public int $external_links = 0;

	/**
	 * Число заголовков h1 внутри текста записи.
	 *
	 * @var int
	 */
	public int $h1_count;

	/**
	 * Метрики удобства чтения — см. Readability::measure().
	 *
	 * @var array<string, mixed>
	 */
	public array $readability;

	/**
	 * Конструктор.
	 *
	 * @param Paper          $paper   Данные страницы.
	 * @param KeywordMatcher $matcher Поиск ключа (нужен для подсчёта слов).
	 */
	public function __construct( Paper $paper, KeywordMatcher $matcher ) {
		$html = $paper->content;

		$this->text       = Cleaner::to_plain_text( $html, false );
		$this->word_count = count( $matcher->words( $this->text ) );
		$this->h1_count   = (int) preg_match_all( '#<h1[\s>]#i', $html );

		$this->readability = Readability::measure( $html );

		$this->first_paragraph = '';
		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#isu', $html, $paragraphs ) ) {
			foreach ( $paragraphs[1] as $paragraph ) {
				$plain = Cleaner::to_plain_text( $paragraph, false );
				if ( '' !== $plain ) {
					$this->first_paragraph = $plain;
					break;
				}
			}
		}

		if ( '' === $this->first_paragraph ) {
			// Текст без тегов абзаца: берём начало текста.
			$this->first_paragraph = implode( ' ', array_slice( explode( ' ', Cleaner::to_plain_text( $html ) ), 0, 60 ) );
		}

		if ( preg_match_all( '#<h[2-4]\b[^>]*>(.*?)</h[2-4]>#isu', $html, $headings ) ) {
			foreach ( $headings[1] as $heading ) {
				$this->subheadings[] = Cleaner::to_plain_text( $heading, false );
			}
		}

		if ( preg_match_all( '#<img\b[^>]*>#isu', $html, $images ) ) {
			foreach ( $images[0] as $image ) {
				$this->image_alts[] = preg_match( '#\balt=(["\'])(.*?)\1#isu', $image, $alt ) ? html_entity_decode( $alt[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			}
		}

		if ( preg_match_all( '#<a\b[^>]*\bhref=(["\'])(.*?)\1#isu', $html, $links ) ) {
			foreach ( $links[2] as $href ) {
				$this->count_link( trim( $href ), $paper->site_host );
			}
		}
	}

	/**
	 * Относит ссылку к внутренним или внешним.
	 *
	 * @param string $href      Адрес ссылки.
	 * @param string $site_host Хост сайта.
	 */
	private function count_link( string $href, string $site_host ): void {
		if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '#^(mailto|tel|javascript):#i', $href ) ) {
			return;
		}

		$host = (string) parse_url( $href, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress.

		if ( '' === $host || 0 === strcasecmp( preg_replace( '/^www\./i', '', $host ), preg_replace( '/^www\./i', '', $site_host ) ) ) {
			++$this->internal_links;
		} else {
			++$this->external_links;
		}
	}
}
