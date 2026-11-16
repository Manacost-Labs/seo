<?php
/**
 * REST-маршрут: страницы сайта по основному ключу.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Rest;

use IndexPilot\Analysis\KeywordMatcher;
use IndexPilot\Analysis\KeywordUsage;
use IndexPilot\CrossSite\KeywordAccess;
use IndexPilot\Settings\Settings;

/**
 * Отвечает второму сайту владельца, есть ли здесь опубликованные страницы с таким же основным ключом.
 * Доступ — по общему ключу в заголовке запроса. Отдаются только название и адрес опубликованных страниц.
 */
final class KeywordsController {

	/**
	 * Конструктор.
	 *
	 * @param Settings       $settings Настройки: общий ключ доступа.
	 * @param KeywordMatcher $matcher  Сравнение ключей с учётом форм слов.
	 */
	public function __construct( private Settings $settings, private KeywordMatcher $matcher ) {
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function register(): void {
		register_rest_route(
			'indexpilot/v1',
			'/keywords',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'find' ),
				'permission_callback' => fn( \WP_REST_Request $request ): bool => KeywordAccess::allows(
					(string) $this->settings->general( 'partner_site_key' ),
					(string) $request->get_header( KeywordAccess::HEADER )
				),
				'args'                => array(
					'keyword' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Страницы с тем же основным ключом: совпадение с учётом форм слов («колода мага» и «колоды мага»)
	 * и игровых синонимов («дека мага»).
	 *
	 * @param \WP_REST_Request $request Запрос.
	 * @return \WP_REST_Response
	 */
	public function find( \WP_REST_Request $request ) {
		global $wpdb;

		$keyword = trim( (string) $request['keyword'] );
		$matches = array();

		// Все ключи опубликованных записей сравниваются с учётом форм слов и игровых синонимов.
		foreach ( ( new KeywordUsage( $wpdb, $this->matcher ) )->posts( $keyword, 0, 5, true ) as $post_id ) {
			$matches[] = array(
				'title' => wp_strip_all_tags( get_the_title( $post_id ) ),
				'url'   => (string) get_permalink( $post_id ),
			);
		}

		return rest_ensure_response( $matches );
	}
}
