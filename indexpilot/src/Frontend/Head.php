<?php
/**
 * Вывод SEO-тегов в head страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Frontend;

use IndexPilot\Meta\PrimaryCategory;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\ContextFactory;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Resolver\ResolvedMeta;
use IndexPilot\Robots\Directives;
use IndexPilot\Settings\Settings;

/**
 * Единственное место, где плагин печатает title, description, canonical, robots и теги соцсетей.
 */
final class Head {

	/**
	 * Контекст текущей страницы.
	 *
	 * @var Context|null
	 */
	private ?Context $context = null;

	/**
	 * Посчитанные мета-данные текущей страницы.
	 *
	 * @var ResolvedMeta|null
	 */
	private ?ResolvedMeta $meta = null;

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver $resolver Резолвер мета-данных.
	 * @param Settings     $settings Настройки.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private Settings $settings
	) {
	}

	/**
	 * Подключает вывод. Вызывается на хуке template_redirect, то есть только на страницах сайта.
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
		// Темы, которые печатают <title> старой функцией wp_title() (Newspaper), получают тот же заголовок.
		add_filter( 'wp_title', array( $this, 'title' ), 99999 );
		add_filter( 'wp_robots', array( $this, 'robots' ), 999 );
		add_action( 'wp_head', array( $this, 'render' ), 1 );

		// Каноникал печатает плагин, штатный вывод WordPress убирается, чтобы не было двух тегов.
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Заголовок страницы. WordPress печатает его в теге title как есть, поэтому текст экранируется здесь.
	 * Уже экранированные сущности (&amp;) второй раз не кодируются.
	 *
	 * @param string $title Заголовок от WordPress или темы.
	 */
	public function title( $title ) {
		$resolved = $this->meta()->title;

		return '' !== $resolved ? esc_html( $resolved ) : $title;
	}

	/**
	 * Директивы robots в формате фильтра wp_robots.
	 *
	 * @param array<string, bool|string> $robots Директивы от WordPress.
	 * @return array<string, bool|string>
	 */
	public function robots( $robots ) {
		// Если в настройках чтения сайт закрыт от поисковиков, решение WordPress не трогаем.
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return $robots;
		}

		// Страница встраивания (/…/embed/) — служебная копия записи: штатный запрет WordPress остаётся в силе.
		$directives = is_embed() ? array( 'noindex', 'follow' ) : $this->meta()->robots;
		return Directives::merge( (array) $robots, $directives );
	}

	/**
	 * Печатает теги.
	 */
	public function render(): void {
		$meta = $this->meta();

		echo "\n<!-- IndexPilot " . esc_html( Plugin::VERSION ) . " -->\n";

		if ( '' !== $meta->description ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $meta->description ) );
		}

		if ( $this->settings->general( 'output_meta_keywords' ) && array() !== $meta->keywords ) {
			printf( '<meta name="keywords" content="%s">' . "\n", esc_attr( implode( ', ', $meta->keywords ) ) );
		}

		if ( '' !== $meta->canonical ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $meta->canonical ) );
		}

		$this->render_pagination();

		$context = $this->context();
		if ( Context::POST === $context->type && post_type_supports( $context->subtype, 'author' ) ) {
			$author = (string) get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $context->object_id ) );

			if ( '' !== $author ) {
				printf( '<meta name="author" content="%s">' . "\n", esc_attr( $author ) );
			}
		}

		$this->render_social( $meta );

		echo "<!-- /IndexPilot -->\n";
	}

	/**
	 * Теги Open Graph и Twitter.
	 *
	 * @param ResolvedMeta $meta Мета-данные страницы.
	 */
	private function render_social( ResolvedMeta $meta ): void {
		$image_id = $meta->og_image_id > 0 ? $meta->og_image_id : (int) $this->settings->general( 'default_og_image_id' );
		$image    = $image_id > 0 ? wp_get_attachment_image_src( $image_id, 'full' ) : false;

		$properties = array(
			'og:locale'      => get_locale(),
			'og:type'        => $meta->og_type,
			'og:title'       => $meta->og_title,
			'og:description' => $meta->og_description,
			'og:url'         => $meta->canonical,
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		if ( is_array( $image ) ) {
			$properties['og:image']        = $image[0];
			$properties['og:image:width']  = (string) $image[1];
			$properties['og:image:height'] = (string) $image[2];
			$properties['og:image:alt']    = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
		}

		$context = $this->context();
		if ( 'article' === $meta->og_type && Context::POST === $context->type ) {
			$properties['article:published_time'] = (string) get_post_time( 'c', true, $context->object_id );
			// Время изменения не раньше времени публикации — см. PostDates.
			$properties['article:modified_time'] = PostDates::modified( 'c', true, $context->object_id );
			$properties['og:updated_time']       = $properties['article:modified_time'];

			// Раздел статьи: основная рубрика из панели записи, иначе первая рубрика.
			$categories                    = PrimaryCategory::terms( $context->object_id );
			$properties['article:section'] = array() !== $categories ? (string) $categories[0]->name : '';
		}

		foreach ( $properties as $property => $content ) {
			if ( '' !== (string) $content ) {
				printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( (string) $content ) );
			}
		}

		// Метки статьи: по тегу на каждую. Термины записи уже загружены основным запросом страницы — запроса к базе нет.
		if ( 'article' === $meta->og_type && Context::POST === $context->type ) {
			$tags = get_the_terms( $context->object_id, 'post_tag' );

			foreach ( is_array( $tags ) ? array_slice( $tags, 0, 10 ) : array() as $tag ) {
				printf( '<meta property="article:tag" content="%s">' . "\n", esc_attr( $tag->name ) );
			}
		}

		$twitter = array(
			'twitter:card'        => is_array( $image ) ? 'summary_large_image' : 'summary',
			'twitter:title'       => $meta->og_title,
			'twitter:description' => $meta->og_description,
			'twitter:image'       => is_array( $image ) ? $image[0] : '',
		);

		foreach ( $twitter as $name => $content ) {
			if ( '' !== (string) $content ) {
				printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( (string) $content ) );
			}
		}
	}

	/**
	 * Теги rel="prev" и rel="next" на страницах пагинации: у архивов и у записей, разбитых на части.
	 * Число страниц архива уже посчитано основным запросом, текст записи уже загружен — запросов к базе нет.
	 */
	private function render_pagination(): void {
		$context = $this->context();
		$links   = array();

		if ( Context::POST === $context->type && ! is_home() ) {
			$post = get_post( $context->object_id );

			if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
				return;
			}

			$structure = (string) get_option( 'permalink_structure' );
			$permalink = (string) get_permalink( $post );
			$near      = Pagination::neighbours( (int) get_query_var( 'page' ), Pagination::post_parts( (string) $post->post_content ) );

			foreach ( $near as $rel => $page ) {
				if ( $page > 0 ) {
					$links[ $rel ] = Pagination::post_page_url( $permalink, $page, '' !== $structure, str_ends_with( $structure, '/' ) );
				}
			}
		} elseif ( is_archive() || is_home() || is_search() ) {
			global $wp_query;

			$total = $wp_query instanceof \WP_Query ? (int) $wp_query->max_num_pages : 0;

			foreach ( Pagination::neighbours( (int) get_query_var( 'paged' ), $total ) as $rel => $page ) {
				if ( $page > 0 ) {
					$links[ $rel ] = (string) get_pagenum_link( $page, false );
				}
			}
		}

		foreach ( $links as $rel => $url ) {
			printf( '<link rel="%s" href="%s">' . "\n", esc_attr( $rel ), esc_url( $url ) );
		}
	}

	/**
	 * Контекст текущей страницы.
	 */
	private function context(): Context {
		if ( null === $this->context ) {
			$this->context = ContextFactory::from_query();
		}

		return $this->context;
	}

	/**
	 * Мета-данные текущей страницы; считаются один раз за запрос.
	 */
	private function meta(): ResolvedMeta {
		if ( null === $this->meta ) {
			$this->meta = $this->resolver->resolve( $this->context() );
		}

		return $this->meta;
	}
}
