<?php
/**
 * Данные страницы для разметки JSON-LD.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Schema;

use IndexPilot\Authors\AuthorProfile;
use IndexPilot\Breadcrumbs\WpBreadcrumbData;
use IndexPilot\Breadcrumbs\Trail;
use IndexPilot\Frontend\FactBlocks;
use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Meta\PrimaryCategory;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Settings\Settings;
use IndexPilot\Sitemap\EntryPolicy;

/**
 * Собирает из WordPress всё, что нужно GraphBuilder. Заголовок, описание и адрес берутся у резолвера —
 * в разметке они те же, что в тегах страницы.
 *
 * Страница, чей канонический адрес ведёт на другой адрес (старая версия колоды, ручной каноникал), описывается
 * только как страница под собственным адресом: с крошками, сайтом и организацией, но без узлов статьи и вопросов.
 * Каноникал говорит, что самостоятельный материал — та, другая страница; узел Article с датами и автором этой
 * страницы под чужим адресом описывал бы один @id двумя разными способами, а под своим — объявлял бы отдельной
 * статьёй то, что сам сайт просит считать копией.
 */
final class PageData {

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver  $resolver Резолвер мета-данных.
	 * @param Settings      $settings Настройки.
	 * @param Trail         $trail    Хлебные крошки.
	 * @param AuthorProfile $profiles Профили авторов.
	 * @param FaqBlock|null $faq      Блок вопросов и ответов: по нему видно, выведен ли блок на странице.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private Settings $settings,
		private Trail $trail,
		private AuthorProfile $profiles,
		private ?FaqBlock $faq = null
	) {
	}

	/**
	 * Данные страницы.
	 *
	 * @param Context $context Контекст страницы.
	 * @return array<string, mixed>
	 */
	public function for_context( Context $context ): array {
		$meta    = $this->resolver->resolve( $context );
		$is_post = Context::POST === $context->type;
		$post    = $is_post ? get_post( $context->object_id ) : null;

		$image_id = $meta->og_image_id > 0 ? $meta->og_image_id : (int) $this->settings->general( 'default_og_image_id' );

		// Каноникал ведёт на другую страницу: узлы получают собственный адрес страницы, узла статьи нет.
		$own     = $this->resolver->own_url( $context );
		$foreign = '' !== $own && '' !== $meta->canonical && ! EntryPolicy::same_address( $own, $meta->canonical );

		return array(
			'site'         => array(
				'url'         => home_url( '/' ),
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'language'    => get_bloginfo( 'language' ),
			),
			'organization' => array(
				'name'    => (string) $this->settings->general( 'organization_name' ),
				'logo'    => $this->image( $this->logo_id() ),
				'same_as' => preg_split( '/[\s,]+/', (string) $this->settings->general( 'social_profiles' ), -1, PREG_SPLIT_NO_EMPTY ),
			),
			'page'         => array(
				'type'        => $this->page_type( $context ),
				'url'         => $foreign ? $own : $meta->canonical,
				'title'       => $meta->title,
				'description' => $meta->description,
				'published'   => $post instanceof \WP_Post ? (string) get_post_time( 'c', false, $post ) : '',
				'modified'    => $post instanceof \WP_Post ? PostDates::modified( 'c', false, $post ) : '',
				'image'       => $this->image( $image_id ),
			),
			'breadcrumbs'  => $this->trail->for_context( $context ),
			'article'      => $post instanceof \WP_Post && ! $foreign ? $this->article( $post ) : null,
			'profile'      => Context::AUTHOR === $context->type ? $this->profiles->get( $context->object_id ) : null,
			// Вопросы попадают в разметку, только если блок с ними выведен на этой странице записи.
			'faq'          => $post instanceof \WP_Post && ! $foreign && null !== $this->faq ? $this->faq->visible_items( (int) $post->ID, max( 1, $context->page ) ) : array(),
			// Ролики собраны при сохранении записи (VideoMeta); мета уже в кеше WordPress — запроса к базе нет.
			'videos'       => $post instanceof \WP_Post && ! $foreign && ! post_password_required( $post ) ? (array) get_post_meta( (int) $post->ID, VideoData::META, true ) : array(),
		);
	}

	/**
	 * Тип узла страницы.
	 *
	 * @param Context $context Контекст страницы.
	 */
	private function page_type( Context $context ): string {
		switch ( $context->type ) {
			case Context::POST:
				return 'WebPage';
			case Context::SEARCH:
				return 'SearchResultsPage';
			case Context::AUTHOR:
				return 'ProfilePage';
			default:
				return 'CollectionPage';
		}
	}

	/**
	 * Данные статьи, если у записи или для её типа выбрана разметка статьи.
	 *
	 * @param \WP_Post $post Запись.
	 * @return array<string, mixed>|null
	 */
	private function article( \WP_Post $post ): ?array {
		$choice   = (string) get_post_meta( $post->ID, MetaKeys::SCHEMA_TYPE, true );
		$fallback = (string) ( $this->settings->post_type( $post->post_type )['schema_type'] ?? 'WebPage' );
		// Тип материала нужен только выбору «Авто»: новость и патч получают NewsArticle.
		$material = ArticleType::AUTO === ArticleType::sanitize( $choice ) ? PostMaterial::detect( $post ) : '';
		$type     = ArticleType::resolve( $choice, $fallback, $material );

		if ( '' === $type ) {
			return null;
		}

		$categories = PrimaryCategory::terms( (int) $post->ID );
		$words      = preg_split( '/\s+/u', trim( wp_strip_all_tags( $post->post_content ) ), -1, PREG_SPLIT_NO_EMPTY );
		$author     = array();

		if ( post_type_supports( $post->post_type, 'author' ) && (int) $post->post_author > 0 ) {
			$author = $this->profiles->get( (int) $post->post_author );
		}

		return array(
			'type'       => $type,
			// Гайд — это Article с жанром: так пункт «Гайд» отличается от «Статьи», а тип остаётся понятным Google.
			'genre'      => ArticleType::is_guide( $choice, $fallback, $material ) ? __( 'Гайд', 'indexpilot' ) : '',
			// Без приставки «Защищено:» у записи под паролем.
			'headline'   => WpBreadcrumbData::plain_title( $post ),
			'section'    => array() !== $categories ? (string) $categories[0]->name : '',
			'word_count' => is_array( $words ) ? count( $words ) : 0,
			'author'     => $author,
			// Источники фактов из текста; у записи под паролем текст закрыт, и источников нет.
			'citations'  => post_password_required( $post ) ? array() : FactBlocks::citations( $post->post_content ),
		);
	}

	/**
	 * Логотип организации: из настроек плагина, иначе логотип темы, иначе значок сайта.
	 */
	private function logo_id(): int {
		$logo_id = (int) $this->settings->general( 'organization_logo_id' );

		if ( $logo_id <= 0 ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
		}

		return $logo_id > 0 ? $logo_id : (int) get_option( 'site_icon' );
	}

	/**
	 * Адрес и размеры картинки из медиатеки.
	 *
	 * @param int $image_id ID вложения.
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function image( int $image_id ): ?array {
		$image = $image_id > 0 ? wp_get_attachment_image_src( $image_id, 'full' ) : false;

		return is_array( $image )
			? array(
				'url'    => (string) $image[0],
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
			)
			: null;
	}
}
