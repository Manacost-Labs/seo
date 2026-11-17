<?php
/**
 * ИИ-помощник: SEO-поля и alt картинок по тексту статьи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai\Writer;

use IndexPilot\Settings\Settings;
use IndexPilot\Text\InlineShortcodes;

/**
 * Собирает статью из редактора (ещё не сохранённый текст), картинки без alt и отдаёт модели.
 * Картинки из медиатеки уходят уменьшенной копией прямо с диска — модели не нужно скачивать их с сайта.
 * Ничего не сохраняет: значения возвращаются в панель, редактор проверяет их и сохраняет запись сам.
 */
final class Writer {

	/**
	 * Самая большая копия картинки, которая отправляется с диска, байт.
	 */
	private const MAX_FILE = 1500000;

	/**
	 * Конструктор.
	 *
	 * @param Account  $account  Подключение.
	 * @param Settings $settings Настройки: длина описания.
	 */
	public function __construct( private Account $account, private Settings $settings ) {
	}

	/**
	 * Значения полей для статьи.
	 *
	 * @param string $title       Заголовок.
	 * @param string $content     Текст из редактора.
	 * @param string $slug        Адрес страницы (слаг).
	 * @param string $description Текущее описание из формы.
	 * @param string $focus       Текущий главный запрос из формы.
	 * @return array<string, mixed>
	 * @throws WriterException Нет ключа, сервис отказал или ответил не по формату.
	 */
	public function fill( string $title, string $content, string $slug = '', string $description = '', string $focus = '' ): array {
		$client = $this->account->client();
		$images = array();
		$urls   = array();

		// Картинка, которую не удалось прочитать ни с диска, ни по адресу, модели не отдаётся и alt не получает.
		foreach ( Draft::without_alt( Draft::images( $content ) ) as $image ) {
			$url = $this->image_url( $image );

			if ( '' !== $url ) {
				$images[] = $image;
				$urls[]   = $url;
			}
		}

		$max  = (int) $this->settings->general( 'description_max' );
		$text = self::text( $content );

		if ( mb_strlen( $text ) < 300 ) {
			throw new WriterException( 'В статье слишком мало текста: допишите её и нажмите кнопку снова.' );
		}

		$messages = Draft::messages(
			array(
				'site'            => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'about'           => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'title'           => wp_strip_all_tags( $title ),
				'text'            => $text,
				'description_min' => (int) $this->settings->general( 'description_min' ),
				'description_max' => $max,
				'slug'            => urldecode( $slug ),
				'description'     => wp_strip_all_tags( $description ),
				'focus'           => wp_strip_all_tags( $focus ),
			),
			$urls
		);

		$result = Draft::parse( $client->complete( $messages ), count( $images ), $max > 0 ? $max : 160, wp_strip_all_tags( $title ), $text, wp_strip_all_tags( $focus ) );

		$alts = array();

		foreach ( $result['alts'] as $index => $alt ) {
			$alts[] = array(
				'src' => $images[ $index ]['src'],
				'id'  => $images[ $index ]['id'],
				'alt' => $alt,
			);
		}

		$result['alts']  = $alts;
		$result['model'] = $this->account->model();

		return $result;
	}

	/**
	 * Текст статьи без разметки: у шорткодов карт остаются названия карт.
	 *
	 * @param string $content Текст из редактора.
	 */
	public static function text( string $content ): string {
		$text = InlineShortcodes::plain( (string) preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', $content ) );
		$text = (string) preg_replace( '#</(p|h[1-6]|li|tr|div|blockquote)>|<br\s*/?>#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s*\n\s*/', "\n", $text ) );
	}

	/**
	 * Картинка для модели как data:-адрес: уменьшенная копия с диска, иначе — скачанная по адресу.
	 * Пустая строка — картинку прочитать не удалось.
	 *
	 * @param array{src: string, id: int, alt: string} $image Картинка.
	 */
	private function image_url( array $image ): string {
		if ( $image['id'] > 0 ) {
			$file = (string) get_attached_file( $image['id'] );

			foreach ( array( 'medium_large', 'large', 'medium' ) as $size ) {
				$copy = image_get_intermediate_size( $image['id'], $size );

				if ( is_array( $copy ) && ! empty( $copy['file'] ) ) {
					$file = path_join( dirname( $file ), wp_basename( (string) $copy['file'] ) );
					break;
				}
			}

			$type = wp_check_filetype( $file );

			if ( '' !== $file && is_readable( $file ) && filesize( $file ) <= self::MAX_FILE && is_string( $type['type'] ) && str_starts_with( $type['type'], 'image/' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- файл медиатеки этого сайта.
				return 'data:' . $type['type'] . ';base64,' . base64_encode( (string) file_get_contents( $file ) );
			}
		}

		$url      = str_starts_with( $image['src'], '//' ) ? 'https:' . $image['src'] : ( str_starts_with( $image['src'], '/' ) ? home_url( $image['src'] ) : $image['src'] );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'limit_response_size' => self::MAX_FILE,
			)
		);
		$type     = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		$body     = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! in_array( $type, array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ), true ) || '' === $body || strlen( $body ) >= self::MAX_FILE ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- картинка передаётся модели внутри запроса.
		return 'data:' . $type . ';base64,' . base64_encode( $body );
	}
}
