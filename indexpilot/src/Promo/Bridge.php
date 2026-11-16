<?php
/**
 * Мост между сайтами владельца.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Promo;

/**
 * Блок «Подробный разбор на втором сайте» / «Все колоды архетипа». Ответ второго сайта запрашивается по расписанию
 * и хранится в мете записи; страница блок только печатает. Если второй сайт не ответил, блока нет,
 * а страница ничего не ждёт: на её открытии сеть не используется.
 */
final class Bridge {

	public const META = '_indexpilot_bridge';

	/**
	 * Сколько ссылок в блоке.
	 */
	private const LIMIT = 3;

	/**
	 * Ссылки блока из ответа второго сайта.
	 *
	 * @param array<string, mixed> $response Ответ: keyword — страницы с тем же ключом, archetype — страница архетипа.
	 * @param string               $host     Имя второго сайта.
	 * @return list<array{label: string, title: string, url: string}>
	 */
	public static function items( array $response, string $host ): array {
		$items = array();

		foreach ( (array) ( $response['keyword'] ?? array() ) as $page ) {
			$item = self::item( $page, 'Подробный разбор на ' . $host );

			if ( null !== $item ) {
				$items[] = $item;
				break;
			}
		}

		$archetype = $response['archetype'] ?? null;

		if ( is_array( $archetype ) ) {
			$item = self::item( $archetype, 'Все колоды архетипа «' . self::text( (string) ( $archetype['title'] ?? '' ) ) . '» на ' . $host );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return array_slice( $items, 0, self::LIMIT );
	}

	/**
	 * HTML блока из сохранённых данных; пустая строка — показывать нечего или второй сайт недоступен.
	 *
	 * @param mixed $saved Сохранённые данные: items и failed.
	 */
	public static function html( $saved ): string {
		if ( ! is_array( $saved ) || ! empty( $saved['failed'] ) || empty( $saved['items'] ) || ! is_array( $saved['items'] ) ) {
			return '';
		}

		$links = '';

		foreach ( $saved['items'] as $item ) {
			$item = is_array( $item ) ? self::item( $item, (string) ( $item['label'] ?? '' ) ) : null;

			if ( null === $item ) {
				continue;
			}

			$links .= sprintf(
				'<p><strong>%1$s:</strong> <a href="%2$s">%3$s</a></p>',
				htmlspecialchars( $item['label'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( $item['url'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( $item['title'], ENT_QUOTES, 'UTF-8' )
			);
		}

		return '' === $links ? '' : '<aside class="indexpilot-bridge">' . $links . '</aside>';
	}

	/**
	 * Проверенная ссылка: адрес http(s), название без разметки.
	 *
	 * @param mixed  $page  Страница: title и url.
	 * @param string $label Подпись.
	 * @return array{label: string, title: string, url: string}|null
	 */
	private static function item( $page, string $label ): ?array {
		if ( ! is_array( $page ) ) {
			return null;
		}

		$url   = trim( (string) ( $page['url'] ?? '' ) );
		$title = self::text( (string) ( $page['title'] ?? '' ) );

		if ( '' === $title || 1 !== preg_match( '#^https?://[^\s"<>]+$#i', $url ) ) {
			return null;
		}

		return array(
			'label' => $label,
			'title' => $title,
			'url'   => $url,
		);
	}

	/**
	 * Текст без разметки и лишних пробелов.
	 *
	 * @param string $text Текст.
	 */
	private static function text( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}
}
