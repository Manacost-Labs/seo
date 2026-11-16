<?php
/**
 * Запрос ключевых слов второго сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\CrossSite;

use IndexPilot\Settings\Settings;

/**
 * У владельца два сайта об одной игре. Если на обоих стоит один и тот же основной ключ,
 * страницы конкурируют между собой в поиске. Клиент спрашивает второй сайт, занят ли ключ.
 *
 * Ответ запоминается; недоступность второго сайта анализ не ломает.
 */
final class PartnerClient {

	private const CACHE_OK     = 12 * HOUR_IN_SECONDS;
	private const CACHE_FAILED = 10 * MINUTE_IN_SECONDS;

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки: адрес второго сайта и общий ключ доступа.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Настроена ли связь со вторым сайтом.
	 */
	public function configured(): bool {
		return '' !== $this->url() && strlen( (string) $this->settings->general( 'partner_site_key' ) ) >= KeywordAccess::MIN_KEY_LENGTH;
	}

	/**
	 * Страницы второго сайта с тем же основным ключом.
	 *
	 * @param string $keyword      Основной ключ.
	 * @param bool   $allow_remote Разрешён ли запрос ко второму сайту. В живом предпросмотре редактора — нет:
	 *                             там ключ меняется с каждой буквой, берётся только запомненный ответ.
	 * @return list<array{title: string, url: string}>|null null — связь не настроена или ответа нет.
	 */
	public function lookup( string $keyword, bool $allow_remote ): ?array {
		$keyword = mb_strtolower( trim( $keyword ) );

		if ( '' === $keyword || ! $this->configured() ) {
			return null;
		}

		$cache_key = 'indexpilot_partner_' . md5( $this->url() . '|' . $keyword );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached['matches'] ?? null;
		}

		if ( ! $allow_remote ) {
			return null;
		}

		$response = wp_safe_remote_get(
			add_query_arg( 'keyword', rawurlencode( $keyword ), $this->url() . '/wp-json/indexpilot/v1/keywords' ),
			array(
				'timeout'     => 3,
				// Перенаправление унесло бы общий ключ на чужой адрес: запрос идёт только по адресу из настроек.
				'redirection' => 0,
				'headers'     => array( KeywordAccess::HEADER => (string) $this->settings->general( 'partner_site_key' ) ),
			)
		);

		$matches = null;

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( is_array( $data ) ) {
				$matches = array();

				foreach ( array_slice( $data, 0, 5 ) as $item ) {
					if ( is_array( $item ) && isset( $item['url'], $item['title'] ) ) {
						$matches[] = array(
							'title' => sanitize_text_field( (string) $item['title'] ),
							'url'   => esc_url_raw( (string) $item['url'] ),
						);
					}
				}
			}
		}

		set_transient( $cache_key, array( 'matches' => $matches ), null === $matches ? self::CACHE_FAILED : self::CACHE_OK );

		return $matches;
	}

	/**
	 * Адрес второго сайта без слэша в конце. Только https: по http общий ключ ушёл бы открытым текстом.
	 */
	private function url(): string {
		return self::secure_url( untrailingslashit( esc_url_raw( trim( (string) $this->settings->general( 'partner_site_url' ) ) ) ) );
	}

	/**
	 * Адрес как есть, если он начинается с https://, иначе пустая строка.
	 *
	 * @param string $url Адрес второго сайта.
	 */
	public static function secure_url( string $url ): string {
		$url = trim( $url );

		return 1 === preg_match( '#^https://[^/?\#\s]+\.[^/?\#\s]+#i', $url ) ? $url : '';
	}
}
