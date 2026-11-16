<?php
/**
 * Текст для описания ролика и стрима.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Text;

/**
 * Собирает из статьи готовое описание для YouTube, Twitch и Telegram: краткий ответ, пункты содержания
 * и короткая ссылка. Ассистенты ролики не смотрят — они читают текст вокруг них, поэтому в описании должна быть суть.
 * Результат — обычный текст без разметки.
 */
final class VideoBlurb {

	/**
	 * Сколько пунктов содержания попадает в описание.
	 */
	private const MAX_HEADINGS = 12;

	/**
	 * Собирает текст.
	 *
	 * @param string $summary    Краткий ответ (или описание записи, если ответа нет).
	 * @param string $content    HTML текста записи: из него берутся подзаголовки H2.
	 * @param string $url        Короткая ссылка записи.
	 * @param string $toc_label  Подпись списка разделов.
	 * @param string $link_label Подпись ссылки.
	 */
	public static function build( string $summary, string $content, string $url, string $toc_label = 'В статье:', string $link_label = 'Читать полностью:' ): string {
		$parts   = array();
		$summary = Cleaner::to_plain_text( $summary, false );

		if ( '' !== $summary ) {
			$parts[] = $summary;
		}

		$headings = self::headings( $content );

		if ( array() !== $headings ) {
			$parts[] = self::line( $toc_label ) . "\n— " . implode( "\n— ", $headings );
		}

		$url = trim( $url );

		if ( '' !== $url ) {
			$parts[] = trim( self::line( $link_label ) . ' ' . $url );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Подзаголовки H2 статьи обычным текстом.
	 *
	 * @param string $content HTML текста записи.
	 * @return list<string>
	 */
	public static function headings( string $content ): array {
		$headings = array();

		if ( preg_match_all( '#<h2\b[^>]*>(.*?)</h2>#isu', $content, $found ) ) {
			foreach ( $found[1] as $heading ) {
				$heading = Cleaner::to_plain_text( $heading, false );

				if ( '' !== $heading ) {
					$headings[] = $heading;
				}
			}
		}

		return array_slice( $headings, 0, self::MAX_HEADINGS );
	}

	/**
	 * Подпись в одну строку без разметки.
	 *
	 * @param string $text Подпись.
	 */
	private static function line( string $text ): string {
		return Cleaner::to_plain_text( $text, false );
	}
}
