<?php
/**
 * Сборка данных страницы для анализа из WordPress.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Authors\AuthorData;
use IndexPilot\Authors\AuthorProfile;
use IndexPilot\CrossSite\PartnerClient;
use IndexPilot\Game\GameScope;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Game\SiteGames;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Redirects\LinkAudit;
use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Redirects\RedirectService;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Resolver\PostDates;
use IndexPilot\Schema\ArticleType;
use IndexPilot\Settings\Settings;

/**
 * Заголовок и описание берутся у резолвера — те же, что выводятся на сайте.
 * Несохранённые значения из редактора передаются через Context::$overrides.
 */
final class PaperFactory {

	/**
	 * Сколько страниц с тем же ключом показывать в предупреждении.
	 */
	private const MAX_DUPLICATES = 3;

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver         $resolver         Резолвер мета-данных.
	 * @param Settings             $settings         Настройки.
	 * @param RedirectService|null $redirects        Редиректы сайта.
	 * @param RedirectedPosts|null $redirected_posts Записи под редиректом.
	 * @param AuthorProfile|null   $profiles         Профили авторов.
	 * @param PartnerClient|null   $partner          Запрос ключей второго сайта.
	 * @param KeywordUsage|null    $usage            Поиск страниц с тем же ключом; null — проверки занятого ключа нет.
	 * @param SiteGames|null       $games            Игры сайта: ключ, словарь и типы материалов — в пределах игры записи.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private Settings $settings,
		private ?RedirectService $redirects = null,
		private ?RedirectedPosts $redirected_posts = null,
		private ?AuthorProfile $profiles = null,
		private ?PartnerClient $partner = null,
		private ?KeywordUsage $usage = null,
		private ?SiteGames $games = null
	) {
	}

	/**
	 * Собирает данные записи или термина.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function create( Context $context ): Paper {
		$meta    = $this->resolver->resolve( $context );
		$keyword = $meta->keywords[0] ?? '';
		$is_post = Context::POST === $context->type;

		$content = $context->override( 'content' );
		if ( ! is_string( $content ) ) {
			$content = $is_post
				? (string) get_post_field( 'post_content', $context->object_id )
				: (string) get_term_meta( $context->object_id, MetaKeys::INTRO, true ) . "\n" . (string) get_term_meta( $context->object_id, MetaKeys::OUTRO, true );
		}

		/**
		 * Текст страницы для анализа. Сюда можно добавить данные, которых нет в тексте записи,
		 * например состав колоды.
		 *
		 * @param string  $content HTML текста.
		 * @param Context $context Контекст страницы.
		 */
		$content = (string) apply_filters( 'indexpilot_analysis_content', $content, $context );

		$slug = $context->override( 'slug' );
		if ( ! is_string( $slug ) ) {
			$slug = $is_post ? (string) get_post_field( 'post_name', $context->object_id ) : $this->term_slug( $context );
		}

		$redirected_links = null;
		$redirect_target  = '';

		if ( null !== $this->redirects && $this->redirects->available() ) {
			$redirected_links = LinkAudit::find( $content, $this->redirects->resolver() );

			if ( $is_post && null !== $this->redirected_posts ) {
				$redirect_target = $this->redirected_posts->target( $context->object_id, false );
			}
		}

		$post     = $is_post ? get_post( $context->object_id ) : null;
		$title    = $context->override( 'title' );
		$material = $post instanceof \WP_Post ? PostMaterial::detect( $post, is_string( $title ) ? $title : null, $content ) : '';
		$game     = $post instanceof \WP_Post && null !== $this->games ? $this->games->post_game( $post ) : '';
		$missing  = '' !== $game && null !== $this->games ? GameScope::missing_names( $meta->title, $game, $this->games->registry() ) : array();

		$author_profile = null;
		$author_id      = $is_post ? (int) get_post_field( 'post_author', $context->object_id ) : 0;

		if ( null !== $this->profiles && $author_id > 0 && post_type_supports( $context->subtype, 'author' ) ) {
			$author_profile = AuthorData::is_complete( $this->profiles->get( $author_id ) );
		}

		$sponsored = $this->sponsored( $context );

		return new Paper(
			title: $meta->title,
			description: $meta->description,
			description_source: $meta->description_source,
			slug: rawurldecode( $slug ),
			content: $content,
			keyword: $keyword,
			keywords: array_slice( $meta->keywords, 1 ),
			site_host: (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			min_words: $is_post ? (int) $this->settings->post_type( $context->subtype )['min_words'] : 0,
			duplicates: $this->duplicates( $context, $keyword, $game ),
			title_px: (int) ( $context->override( 'title_px' ) ?? 0 ),
			title_max_px: (int) $this->settings->general( 'title_max_px' ),
			description_min: (int) $this->settings->general( 'description_min' ),
			description_max: (int) $this->settings->general( 'description_max' ),
			redirected_links: $redirected_links,
			redirect_target: $redirect_target,
			author_profile_complete: $author_profile,
			// Ко второму сайту обращаемся только при сохранении; в живом предпросмотре берётся запомненный ответ.
			partner_matches: null !== $this->partner && '' !== $keyword ? $this->partner->lookup( $keyword, array() === $context->overrides ) : null,
			answer: $is_post ? $this->answer( $context, $material ) : null,
			material: $material,
			// Картинка карточки: своя, миниатюра записи или общая из настроек — та же цепочка, что при выводе тегов.
			has_social_image: $is_post ? ( $meta->og_image_id > 0 || (int) $this->settings->general( 'default_og_image_id' ) > 0 ) : null,
			noindex: ! $meta->is_indexable(),
			url: $is_post ? (string) get_permalink( $context->object_id ) : '',
			canonical: $meta->canonical,
			days_since_update: $post instanceof \WP_Post ? self::days_since( $post ) : null,
			sponsored: $sponsored,
			// Свои площадки и второй сайт нужны только проверке рекламных ссылок.
			own_hosts: $sponsored ? PaperExtras::own_hosts( (string) $this->settings->general( 'partner_site_url' ) ) : array(),
			image_bytes: PaperExtras::image_bytes( $content ),
			featured_image: $is_post && post_type_supports( $context->subtype, 'thumbnail' ) ? PaperExtras::featured_image( $context->object_id ) : null,
			material_types: $post instanceof \WP_Post ? PostMaterial::types( $post ) : MaterialType::class,
			game: $game,
			missing_game_names: $missing
		);
	}

	/**
	 * Отмечено ли у записи оплаченное размещение: несохранённое значение из редактора, иначе из меты.
	 *
	 * @param Context $context Контекст страницы.
	 */
	private function sponsored( Context $context ): bool {
		if ( Context::POST !== $context->type ) {
			return false;
		}

		$value = $context->override( 'sponsored' );

		return is_string( $value ) ? '' !== $value : PaperExtras::sponsored( $context->object_id );
	}

	/**
	 * Сколько полных дней прошло с последней правки записи; null — дата неизвестна (запись ещё не сохранялась).
	 * Правка не может быть раньше публикации: у записи, вышедшей по плану, отсчёт идёт от публикации (см. PostDates).
	 *
	 * @param \WP_Post $post Запись.
	 */
	private static function days_since( \WP_Post $post ): ?int {
		$modified = PostDates::effective( (int) strtotime( $post->post_date_gmt . ' UTC' ), (int) strtotime( $post->post_modified_gmt . ' UTC' ) );

		return $modified <= 0 ? null : max( 0, (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Краткий ответ записи: несохранённый из редактора, иначе из меты. Проверяется у записей с разметкой статьи;
	 * у остальных (страницы без разметки статьи) возвращается null — проверки нет. У колод её тоже нет:
	 * текста у колоды нет, и красный пункт о кратком ответе там лишний.
	 *
	 * @param Context $context  Контекст страницы.
	 * @param string  $material Тип материала записи.
	 */
	private function answer( Context $context, string $material ): ?string {
		$type = ArticleType::resolve(
			(string) get_post_meta( $context->object_id, MetaKeys::SCHEMA_TYPE, true ),
			(string) ( $this->settings->post_type( $context->subtype )['schema_type'] ?? '' ),
			$material
		);

		if ( '' === $type || MaterialType::DECK === $material ) {
			return null;
		}

		$answer = $context->override( 'answer' );

		// Карты в ответе — шорткодом «[hs_card …]Название[/hs_card]»: анализ видит только название.
		return \IndexPilot\Text\InlineShortcodes::plain( trim( is_string( $answer ) ? $answer : (string) get_post_meta( $context->object_id, MetaKeys::ANSWER, true ) ) );
	}

	/**
	 * Слаг термина.
	 *
	 * @param Context $context Контекст страницы.
	 */
	private function term_slug( Context $context ): string {
		$term = get_term( $context->object_id, $context->subtype );

		return $term instanceof \WP_Term ? $term->slug : '';
	}

	/**
	 * Названия других опубликованных записей с тем же основным ключом. Ключи сравниваются с учётом форм слов
	 * и игровых синонимов: «дека приста» занимает ключ «колода жреца».
	 *
	 * @param Context $context Контекст страницы.
	 * @param string  $keyword Основной ключ.
	 * @param string  $game    Игра записи: записи других игр ключ не занимают; пусто — все игры.
	 * @return list<string>
	 */
	private function duplicates( Context $context, string $keyword, string $game = '' ): array {
		if ( '' === $keyword ) {
			return array();
		}

		global $wpdb;

		if ( null === $this->usage ) {
			$this->usage = new KeywordUsage( $wpdb, ( new Analyzer() )->matcher() );
		}

		// Ключ записи другой игры сравнивается её словарём: «шп» в World of Warcraft — жрец тьмы.
		$usage = null !== $this->games && '' !== $game && SiteGames::DEFAULT_GAME !== $game ? new KeywordUsage( $wpdb, $this->games->matcher( $game ) ) : $this->usage;
		$all   = null === $this->games || ! $this->games->active();

		$post_ids = $usage->posts( $keyword, Context::POST === $context->type ? $context->object_id : 0, self::MAX_DUPLICATES, false, $all ? '' : $game, SiteGames::DEFAULT_GAME );

		return array_map( static fn( int $post_id ): string => (string) get_the_title( $post_id ), $post_ids );
	}
}
