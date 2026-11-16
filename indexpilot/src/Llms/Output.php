<?php
/**
 * Отдача файла llms.txt.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Llms;

use IndexPilot\Game\Hub;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\Module;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Settings\Settings;
use IndexPilot\Sitemap\Cache;

/**
 * Отдаёт /llms.txt: опорные страницы сайта (отмечены в панели записи), свежие мета-отчёты и тир-листы, страницы
 * классов и архетипов, основные рубрики и авторов. Закрытые от индексации страницы (в том числе почти пустые
 * архивы — их закрывает правило тонкого архива) в файл не попадают.
 */
final class Output {

	private const MAX_PAGES      = 100;
	private const MAX_TERMS      = 20;
	private const MAX_ARCHETYPES = 40;
	private const MAX_REPORTS    = 10;
	private const MAX_AUTHORS    = 20;

	/**
	 * Мета-отчёт считается свежим столько дней.
	 */
	private const REPORT_DAYS = 60;

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер: описание страницы и её индексация.
	 * @param Settings     $settings Настройки.
	 * @param Cache        $cache    Кэш: сбрасывается вместе с картой сайта при изменении записей.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private Settings $settings,
		private Cache $cache
	) {
	}

	/**
	 * Отдаёт файл, если запрошен его адрес. Вызывается на хуке parse_request.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- адрес только сравнивается с ожидаемым именем файла.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		if ( (string) strtok( $uri, '?' ) !== (string) wp_parse_url( home_url( '/llms.txt' ), PHP_URL_PATH ) || ! $this->settings->general( 'llms_txt' ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- обычный текст (Content-Type: text/plain), разметка HTML из значений убрана при сборке.
		echo $this->text();
		exit;
	}

	/**
	 * Текст файла.
	 */
	public function text(): string {
		return (string) $this->cache->remember(
			'llms.txt',
			fn(): string => LlmsTxt::build(
				get_bloginfo( 'name' ),
				get_bloginfo( 'description' ),
				array(
					__( 'Опорные материалы', 'indexpilot' ) => $this->cornerstone(),
					__( 'Свежие мета-отчёты', 'indexpilot' ) => $this->meta_reports(),
					__( 'Классы', 'indexpilot' )   => $this->terms( Hub::CLASS_TAXONOMY, self::MAX_TERMS ),
					__( 'Архетипы', 'indexpilot' ) => $this->terms( Module::ARCHETYPE, self::MAX_ARCHETYPES ),
					__( 'Разделы', 'indexpilot' )  => $this->sections(),
					__( 'Авторы', 'indexpilot' )   => $this->authors(),
				)
			)
		);
	}

	/**
	 * Опорные страницы: опубликованные и открытые для индексации записи с отметкой в панели.
	 *
	 * @return list<array{title: string, url: string, description: string}>
	 */
	private function cornerstone(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PAGES,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- выборка небольшая, результат кэшируется.
				'meta_key'       => MetaKeys::CORNERSTONE,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- см. выше.
				'meta_value'     => '1',
			)
		);

		$links = array();

		foreach ( $posts as $post ) {
			$meta = $this->resolver->resolve( new Context( Context::POST, (int) $post->ID, $post->post_type ) );

			if ( $meta->is_indexable() ) {
				$links[] = array(
					'title'       => get_the_title( $post ),
					'url'         => (string) get_permalink( $post ),
					// Краткий ответ из панели записи — готовое самостоятельное описание страницы; он идёт целиком.
					'description' => LlmsTxt::summary( (string) get_post_meta( $post->ID, MetaKeys::ANSWER, true ), $meta->description ),
				);
			}
		}

		return $links;
	}

	/**
	 * Основные рубрики: самые наполненные.
	 *
	 * @return list<array{title: string, url: string, description: string}>
	 */
	private function sections(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => self::MAX_TERMS,
			)
		);

		$links = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$meta = $this->resolver->resolve( new Context( Context::TERM, (int) $term->term_id, 'category' ) );
			$url  = get_term_link( $term );

			if ( $meta->is_indexable() && is_string( $url ) ) {
				$links[] = array(
					'title'       => $term->name,
					'url'         => $url,
					'description' => $meta->description,
				);
			}
		}

		return $links;
	}

	/**
	 * Страницы классов или архетипов: самые наполненные, открытые для индексации.
	 *
	 * @param string $taxonomy Таксономия.
	 * @param int    $limit    Сколько страниц взять.
	 * @return list<array{title: string, url: string, description: string}>
	 */
	private function terms( string $taxonomy, int $limit ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $limit,
			)
		);
		$links = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$meta = $this->resolver->resolve( new Context( Context::TERM, (int) $term->term_id, $taxonomy ) );
			$url  = get_term_link( $term );

			// Почти пустой архив закрыт правилом тонкого архива: в путеводитель он не идёт.
			if ( $meta->is_indexable() && is_string( $url ) ) {
				$links[] = array(
					'title'       => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'url'         => $url,
					'description' => $meta->description,
				);
			}
		}

		return $links;
	}

	/**
	 * Свежие мета-отчёты и тир-листы: записи последних недель с таким типом материала.
	 *
	 * @return list<array{title: string, url: string, description: string}>
	 */
	private function meta_reports(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'has_password'   => false,
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'after' => self::REPORT_DAYS . ' days ago',
					),
				),
			)
		);
		$links = array();

		foreach ( $posts as $post ) {
			if ( ! in_array( PostMaterial::detect( $post ), array( MaterialType::META, MaterialType::TIER_LIST ), true ) ) {
				continue;
			}

			$meta = $this->resolver->resolve( new Context( Context::POST, (int) $post->ID, $post->post_type ) );

			if ( $meta->is_indexable() ) {
				$links[] = array(
					'title'       => get_the_title( $post ),
					'url'         => (string) get_permalink( $post ),
					'description' => LlmsTxt::summary( (string) get_post_meta( $post->ID, MetaKeys::ANSWER, true ), $meta->description ),
				);
			}

			if ( count( $links ) >= self::MAX_REPORTS ) {
				break;
			}
		}

		return $links;
	}

	/**
	 * Авторы с опубликованными записями, если их страницы открыты для индексации.
	 *
	 * @return list<array{title: string, url: string, description: string}>
	 */
	private function authors(): array {
		$users = get_users(
			array(
				'has_published_posts' => array( 'post' ),
				'orderby'             => 'post_count',
				'order'               => 'DESC',
				'number'              => self::MAX_AUTHORS,
			)
		);
		$links = array();

		foreach ( $users as $user ) {
			$meta = $this->resolver->resolve( new Context( Context::AUTHOR, (int) $user->ID ) );

			if ( $meta->is_indexable() ) {
				$links[] = array(
					'title'       => $user->display_name,
					'url'         => get_author_posts_url( (int) $user->ID ),
					'description' => (string) get_user_meta( (int) $user->ID, 'description', true ),
				);
			}
		}

		return $links;
	}
}
