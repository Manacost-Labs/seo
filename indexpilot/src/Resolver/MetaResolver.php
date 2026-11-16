<?php
/**
 * Единый расчёт мета-данных страницы.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Resolver;

use Closure;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Settings\Settings;
use IndexPilot\Text\Cleaner;
use IndexPilot\Text\Truncator;
use IndexPilot\Variables\Renderer;

/**
 * Считает заголовок, описание, каноникал, robots и Open Graph по цепочке:
 * поле объекта → шаблон из настроек → автогенерация. У описания записи между полем и шаблоном стоит краткий ответ.
 *
 * Этим классом пользуются вывод в head, предпросмотр сниппета, анализ и sitemap, поэтому расхождений между ними нет.
 */
final class MetaResolver {

	/**
	 * Директивы для индексируемых страниц.
	 */
	private const PREVIEW_LIMITS = array( 'max-image-preview:large', 'max-snippet:-1', 'max-video-preview:-1' );

	/**
	 * Запасной шаблон заголовка.
	 */
	private const FALLBACK_TITLE = '%title% %sep% %sitename%';

	/**
	 * Генератор автоописаний.
	 *
	 * @var DescriptionGenerator
	 */
	private DescriptionGenerator $generator;

	/**
	 * Конструктор.
	 *
	 * @param Settings     $settings   Настройки.
	 * @param Renderer     $renderer   Рендерер шаблонов.
	 * @param DataSource   $data       Источник данных.
	 * @param string       $page_label Формат подписи страницы пагинации.
	 * @param Closure|null $filter     Функция fn( string $hook, mixed $value, Context $context ): mixed; в WordPress это apply_filters.
	 */
	public function __construct(
		private Settings $settings,
		private Renderer $renderer,
		private DataSource $data,
		private string $page_label = 'страница %d',
		private ?Closure $filter = null
	) {
		$this->generator = new DescriptionGenerator( $data );
	}

	/**
	 * Считает мета-данные страницы.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function resolve( Context $context ): ResolvedMeta {
		$config = $this->config( $context );

		list( $title, $title_source )             = $this->title( $context, $config );
		list( $description, $description_source ) = $this->description( $context, $config );

		$count     = $this->data->count( $context );
		$robots    = $this->robots( $context, $config, $count );
		$canonical = $this->canonical( $context, $count );

		$og_title       = $this->custom( $context, MetaKeys::OG_TITLE, 'og_title' );
		$og_description = $this->custom( $context, MetaKeys::OG_DESCRIPTION, 'og_description' );
		$og_image_id    = (int) $this->data->meta( $context, MetaKeys::OG_IMAGE_ID );

		return new ResolvedMeta(
			(string) $this->filter( 'indexpilot_title', $title, $context ),
			$title_source,
			(string) $this->filter( 'indexpilot_description', $description, $context ),
			$description_source,
			(string) $this->filter( 'indexpilot_canonical', $canonical, $context ),
			array_values( (array) $this->filter( 'indexpilot_robots', $robots, $context ) ),
			'' !== $og_title ? $this->renderer->render( $og_title, $context ) : $this->renderer->render( '%title%', $context ),
			'' !== $og_description ? $this->renderer->render( $og_description, $context ) : $description,
			$og_image_id > 0 ? $og_image_id : $this->data->image_id( $context ),
			Context::POST === $context->type && 'page' !== $context->subtype ? 'article' : 'website',
			$this->keywords( $context )
		);
	}

	/**
	 * Открыта ли страница для индексации и её канонический адрес — без расчёта заголовка и описания.
	 * Нужно там, где страниц много: в карте сайта и в отчётах.
	 *
	 * @param Context $context Контекст страницы.
	 * @return array{indexable: bool, canonical: string}
	 */
	public function indexing( Context $context ): array {
		$count  = $this->data->count( $context );
		$robots = (array) $this->filter( 'indexpilot_robots', $this->robots( $context, $this->config( $context ), $count ), $context );

		return array(
			'indexable' => ! in_array( 'noindex', $robots, true ),
			'canonical' => (string) $this->filter( 'indexpilot_canonical', $this->canonical( $context, $count ), $context ),
		);
	}

	/**
	 * Собственный адрес страницы с учётом номера страницы пагинации — без ручного каноникала и правил,
	 * которые переносят канонический адрес на другую страницу.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function own_url( Context $context ): string {
		return $this->data->paged_url( $context );
	}

	/**
	 * Переменные, которых нет в реестре, в шаблонах этой страницы: в полях записи (с учётом несохранённых значений
	 * из редактора) и в шаблонах из настроек, которые действуют, когда поле пустое. На сайт такие переменные
	 * не выводятся; редактор видит их в предпросмотре вместе с предупреждением.
	 *
	 * @param Context $context Контекст страницы.
	 * @return list<string> Имена без знаков процента.
	 */
	public function unknown_variables( Context $context ): array {
		$config      = $this->config( $context );
		$title       = $this->custom( $context, MetaKeys::TITLE, 'seo_title' );
		$description = $this->custom( $context, MetaKeys::DESCRIPTION, 'seo_description' );

		$templates = array(
			'' !== $title ? $title : (string) ( $config['title'] ?? '' ),
			'' !== $description ? $description : (string) ( $config['description'] ?? '' ) . ' ' . (string) ( $config['fallback_description'] ?? '' ),
			$this->custom( $context, MetaKeys::OG_TITLE, 'og_title' ),
			$this->custom( $context, MetaKeys::OG_DESCRIPTION, 'og_description' ),
		);

		return $this->renderer->unknown_tokens( implode( ' ', $templates ) );
	}

	/**
	 * Настройки для вида страницы.
	 *
	 * @param Context $context Контекст страницы.
	 * @return array<string, mixed>
	 */
	private function config( Context $context ): array {
		switch ( $context->type ) {
			case Context::POST:
				return $this->settings->post_type( $context->subtype );

			case Context::TERM:
				return $this->settings->taxonomy( $context->subtype );

			default:
				return $this->settings->special( $context->type );
		}
	}

	/**
	 * Заголовок и его источник.
	 *
	 * @param Context              $context Контекст страницы.
	 * @param array<string, mixed> $config  Настройки вида страницы.
	 * @return array{0: string, 1: string}
	 */
	private function title( Context $context, array $config ): array {
		$custom = $this->custom( $context, MetaKeys::TITLE, 'seo_title' );
		$source = '' !== $custom ? 'custom' : 'template';
		$title  = $this->renderer->render( '' !== $custom ? $custom : (string) ( $config['title'] ?? '' ), $context );

		if ( '' === $title ) {
			$title = $this->renderer->render( self::FALLBACK_TITLE, $context );
		}

		$title = PaginationRules::title( $title, $context, $this->page_label, $this->renderer->separator() );

		return array( $title, $source );
	}

	/**
	 * Описание и его источник.
	 *
	 * @param Context              $context Контекст страницы.
	 * @param array<string, mixed> $config  Настройки вида страницы.
	 * @return array{0: string, 1: string}
	 */
	private function description( Context $context, array $config ): array {
		// Закрытая запись не должна раскрывать свой текст в описании.
		if ( $this->data->is_private( $context ) ) {
			return array( '', 'none' );
		}

		$max    = (int) $this->settings->general( 'description_max' );
		$custom = $this->custom( $context, MetaKeys::DESCRIPTION, 'seo_description' );

		if ( '' !== $custom ) {
			// Ручное описание не обрезаем: длину редактор видит в панели.
			$description = $this->renderer->render( $custom, $context );
			$source      = 'custom';
		} else {
			// Краткий ответ написан человеком именно для этой записи: он точнее шаблона и выжимки из текста.
			$description = Truncator::by_sentence( $this->answer( $context ), $max );
			$source      = 'answer';

			if ( '' === $description ) {
				$description = Truncator::by_sentence( $this->renderer->render( (string) ( $config['description'] ?? '' ), $context ), $max );
				$source      = 'template';
			}

			if ( '' === $description ) {
				$description = $this->generator->generate( $context, (array) ( $config['description_sources'] ?? array() ), $max );
				$source      = 'auto';
			}

			if ( '' === $description ) {
				$description = Truncator::by_sentence( $this->renderer->render( (string) ( $config['fallback_description'] ?? '' ), $context ), $max );
				$source      = '' === $description ? 'none' : 'template';
			}
		}

		return array( PaginationRules::description( $description, $context, $this->page_label ), $source );
	}

	/**
	 * Краткий ответ записи обычным текстом в одну строку. Переменные шаблонов в нём не подставляются.
	 *
	 * @param Context $context Контекст страницы.
	 */
	private function answer( Context $context ): string {
		if ( Context::POST !== $context->type ) {
			return '';
		}

		return Cleaner::to_plain_text( $this->custom( $context, MetaKeys::ANSWER, 'answer' ), false );
	}

	/**
	 * Директивы robots.
	 *
	 * @param Context              $context Контекст страницы.
	 * @param array<string, mixed> $config  Настройки вида страницы.
	 * @param int                  $count   Число записей в архиве; -1, если неизвестно.
	 * @return list<string>
	 */
	private function robots( Context $context, array $config, int $count ): array {
		$noindex = ! empty( $config['noindex'] )
			|| $this->flag( $context, MetaKeys::NOINDEX )
			|| $this->data->is_private( $context )
			|| PaginationRules::should_noindex( $context, (bool) $this->settings->general( 'index_paged_archives' ) )
			|| ThinArchiveRule::is_thin( $context, $count, (int) $this->settings->general( 'thin_archive_threshold' ) );

		// Страницы пагинации и «тонкие» архивы не получают nofollow: по их ссылкам поисковик доходит до записей.
		$follow = $this->flag( $context, MetaKeys::NOFOLLOW ) ? 'nofollow' : 'follow';

		if ( $noindex ) {
			return array( 'noindex', $follow );
		}

		return array_merge( array( 'index', $follow ), self::PREVIEW_LIMITS );
	}

	/**
	 * Канонический адрес.
	 *
	 * @param Context $context Контекст страницы.
	 * @param int     $count   Число записей в архиве; -1, если неизвестно.
	 */
	private function canonical( Context $context, int $count ): string {
		// Архив автора без записей или несуществующего автора закрыт от индексации: канонического адреса у него нет.
		if ( Context::AUTHOR === $context->type && ThinArchiveRule::is_thin( $context, $count, 0 ) ) {
			return '';
		}

		$custom = trim( (string) $this->data->meta( $context, MetaKeys::CANONICAL ) );

		// Ручной каноникал относится к первой странице; у страниц пагинации каноникал свой.
		if ( '' !== $custom && ! PaginationRules::is_paged( $context ) ) {
			return $custom;
		}

		return $this->data->paged_url( $context );
	}

	/**
	 * Ключевые слова без повторов: сначала основное.
	 *
	 * @param Context $context Контекст страницы.
	 * @return list<string>
	 */
	private function keywords( Context $context ): array {
		$keywords = array_merge(
			array( $this->custom( $context, MetaKeys::FOCUS_KEYWORD, 'focus_keyword' ) ),
			(array) ( $context->override( 'keywords' ) ?? $this->data->meta( $context, MetaKeys::KEYWORDS ) )
		);

		$unique = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) $keyword );
			if ( '' !== $keyword ) {
				$unique[ mb_strtolower( $keyword ) ] ??= $keyword;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Значение поля: сначала несохранённое из редактора, затем мета.
	 *
	 * @param Context $context  Контекст страницы.
	 * @param string  $meta_key Ключ меты.
	 * @param string  $override Имя несохранённого поля.
	 */
	private function custom( Context $context, string $meta_key, string $override ): string {
		$value = $context->override( $override );

		if ( ! is_string( $value ) ) {
			$value = $this->data->meta( $context, $meta_key );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Включён ли флаг в мете объекта.
	 *
	 * @param Context $context  Контекст страницы.
	 * @param string  $meta_key Ключ меты.
	 */
	private function flag( Context $context, string $meta_key ): bool {
		return filter_var( $this->data->meta( $context, $meta_key ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Пропускает значение через фильтр, если он задан.
	 *
	 * @param string  $hook    Имя фильтра.
	 * @param mixed   $value   Значение.
	 * @param Context $context Контекст страницы.
	 * @return mixed
	 */
	private function filter( string $hook, $value, Context $context ) {
		return null === $this->filter ? $value : ( $this->filter )( $hook, $value, $context );
	}
}
