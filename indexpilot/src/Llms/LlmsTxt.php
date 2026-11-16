<?php
/**
 * Текст файла llms.txt.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Llms;

/**
 * Файл llms.txt — короткий путеводитель по сайту для AI-ассистентов: что это за сайт и какие страницы на нём главные.
 * Формат — Markdown: название, краткое описание и разделы со ссылками.
 */
final class LlmsTxt {

	/**
	 * Собирает текст файла.
	 *
	 * @param string                                                                          $name     Название сайта.
	 * @param string                                                                          $summary  Краткое описание сайта.
	 * @param array<string, list<array{title: string, url: string, description?: string}>> $sections Разделы: заголовок => ссылки.
	 */
	public static function build( string $name, string $summary, array $sections ): string {
		$text = '# ' . self::line( $name ) . "\n";

		if ( '' !== trim( $summary ) ) {
			$text .= "\n> " . self::line( $summary ) . "\n";
		}

		foreach ( $sections as $heading => $links ) {
			$items = '';

			foreach ( $links as $link ) {
				$title = self::line( (string) ( $link['title'] ?? '' ) );
				$url   = trim( (string) ( $link['url'] ?? '' ) );

				if ( '' === $title || '' === $url ) {
					continue;
				}

				$description = self::line( (string) ( $link['description'] ?? '' ) );
				$items      .= '- [' . str_replace( array( '[', ']' ), array( '(', ')' ), $title ) . '](' . str_replace( array( ' ', ')' ), array( '%20', '%29' ), $url ) . ')' . ( '' !== $description ? ': ' . $description : '' ) . "\n";
			}

			if ( '' !== $items ) {
				$text .= "\n## " . self::line( (string) $heading ) . "\n\n" . $items;
			}
		}

		return $text;
	}

	/**
	 * Описание страницы в списке: краткий ответ записи, а если его нет — её мета-описание.
	 *
	 * @param string $answer      Краткий ответ из панели записи.
	 * @param string $description Мета-описание страницы.
	 */
	public static function summary( string $answer, string $description ): string {
		$answer = self::line( $answer );

		return '' !== $answer ? $answer : self::line( $description );
	}

	/**
	 * Текст в одну строку без разметки HTML.
	 *
	 * @param string $text Текст.
	 */
	private static function line( string $text ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- класс не зависит от WordPress и проверяется модульными тестами.
		return trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) );
	}
}
