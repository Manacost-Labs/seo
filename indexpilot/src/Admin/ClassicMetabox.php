<?php
/**
 * Панель IndexPilot в редакторе записи.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Analysis\PaperExtras;
use IndexPilot\Frontend\AnswerBlock;
use IndexPilot\Frontend\FactBlocks;
use IndexPilot\Frontend\FaqBlock;
use IndexPilot\Game\MaterialType;
use IndexPilot\Game\PostMaterial;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Promo\PostSwitch;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Rest\AnalyzeController;
use IndexPilot\Schema\ArticleType;
use IndexPilot\Settings\Settings;
use IndexPilot\Variables\VariableRegistry;

/**
 * Метабокс под текстом записи: SEO-заголовок, описание, ключевые слова, предпросмотр сниппета и анализ,
 * а на вкладке «Содержание» — краткий ответ, вопросы и ответы, факты и цитаты, текст для ролика и тип материала.
 *
 * Поля — обычная HTML-форма и сохраняются вместе с записью, поэтому работают и без JavaScript.
 * Скрипт добавляет живой предпросмотр, счётчики и список проверок.
 */
final class ClassicMetabox {

	private const NONCE_ACTION = 'indexpilot_save_post';
	private const NONCE_FIELD  = 'indexpilot_post_nonce';

	/**
	 * Текстовые поля формы: имя поля => ключ меты.
	 */
	private const TEXT_FIELDS = array(
		'indexpilot_title'            => MetaKeys::TITLE,
		'indexpilot_description'      => MetaKeys::DESCRIPTION,
		'indexpilot_focus_keyword'    => MetaKeys::FOCUS_KEYWORD,
		'indexpilot_canonical'        => MetaKeys::CANONICAL,
		'indexpilot_og_title'         => MetaKeys::OG_TITLE,
		'indexpilot_og_description'   => MetaKeys::OG_DESCRIPTION,
		'indexpilot_og_image_id'      => MetaKeys::OG_IMAGE_ID,
		'indexpilot_answer'           => MetaKeys::ANSWER,
		'indexpilot_schema_type'      => MetaKeys::SCHEMA_TYPE,
		'indexpilot_breadcrumb_title' => MetaKeys::BREADCRUMB_TITLE,
		'indexpilot_primary_category' => MetaKeys::PRIMARY_CATEGORY,
	);

	/**
	 * Флажки формы: имя поля => ключ меты.
	 */
	private const FLAG_FIELDS = array(
		'indexpilot_noindex'     => MetaKeys::NOINDEX,
		'indexpilot_nofollow'    => MetaKeys::NOFOLLOW,
		'indexpilot_cornerstone' => MetaKeys::CORNERSTONE,
		PaperExtras::FIELD       => PaperExtras::SPONSORED,
	);

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver     $resolver  Резолвер мета-данных.
	 * @param Settings         $settings  Настройки.
	 * @param VariableRegistry $variables Реестр переменных шаблонов.
	 */
	public function __construct(
		private MetaResolver $resolver,
		private Settings $settings,
		private VariableRegistry $variables
	) {
	}

	/**
	 * Подключает метабокс, сохранение и ассеты.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 5, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет метабокс ко всем типам записей, у которых есть страница на сайте.
	 *
	 * @param string $post_type Тип записи.
	 */
	public function add( $post_type ): void {
		if ( ! is_post_type_viewable( (string) $post_type ) || 'attachment' === $post_type ) {
			return;
		}

		add_meta_box( 'indexpilot', __( 'IndexPilot — SEO', 'indexpilot' ), array( $this, 'render' ), (string) $post_type, 'normal', 'high' );
	}

	/**
	 * Подключает скрипт и стили панели только на экране редактирования записи.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) || 'attachment' === $post->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'indexpilot-panel', plugins_url( 'assets/css/panel.css', INDEXPILOT_FILE ), array(), Plugin::VERSION );
		wp_enqueue_script( 'indexpilot-snippet-utils', plugins_url( 'assets/js/snippet-utils.js', INDEXPILOT_FILE ), array(), Plugin::VERSION, true );
		wp_enqueue_script( 'indexpilot-panel', plugins_url( 'assets/js/panel.js', INDEXPILOT_FILE ), array( 'indexpilot-snippet-utils', 'wp-api-fetch' ), Plugin::VERSION, true );

		wp_enqueue_script( 'indexpilot-suggest', plugins_url( 'assets/js/suggest.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
		wp_localize_script(
			'indexpilot-suggest',
			'IndexPilotSuggest',
			array(
				'postId'  => (int) $post->ID,
				'loading' => __( 'Подбираю…', 'indexpilot' ),
				'empty'   => __( 'Подходящих страниц не найдено.', 'indexpilot' ),
				'failed'  => __( 'Не удалось подобрать ссылки. Попробуйте ещё раз.', 'indexpilot' ),
				'copy'    => __( 'скопировать адрес', 'indexpilot' ),
				'copied'  => __( 'адрес скопирован', 'indexpilot' ),
			)
		);

		$names = $this->variables->names();

		wp_localize_script(
			'indexpilot-panel',
			'IndexPilotPanel',
			array(
				'objectType'     => 'post',
				'objectId'       => (int) $post->ID,
				'path'           => '/indexpilot/v1/analyze',
				'siteName'       => get_bloginfo( 'name' ),
				'siteIcon'       => (string) get_site_icon_url( 32 ),
				'homeUrl'        => home_url( '/' ),
				'titleMaxPx'     => (int) $this->settings->general( 'title_max_px' ),
				'descriptionMin' => (int) $this->settings->general( 'description_min' ),
				'descriptionMax' => (int) $this->settings->general( 'description_max' ),
				'variables'      => array_merge( array( 'sep' ), $names['exact'] ),
				'analysis'       => json_decode( (string) get_post_meta( $post->ID, MetaKeys::ANALYSIS, true ), true ),
				'groups'         => array(
					'keyword'     => __( 'Ключевое слово', 'indexpilot' ),
					'snippet'     => __( 'Сниппет', 'indexpilot' ),
					'content'     => __( 'Текст', 'indexpilot' ),
					'readability' => __( 'Читаемость', 'indexpilot' ),
				),
				'strings'        => array(
					'noDescription' => __( 'Описания нет: поисковик сам выберет фрагмент текста.', 'indexpilot' ),
					'error'         => __( 'Не удалось обновить анализ. Проверьте соединение и повторите.', 'indexpilot' ),
					'chooseImage'   => __( 'Выберите картинку для соцсетей', 'indexpilot' ),
					'useImage'      => __( 'Использовать', 'indexpilot' ),
					'chars'         => __( 'символов', 'indexpilot' ),
					'words'         => __( 'слов', 'indexpilot' ),
					/* translators: 1: сколько вопросов добавлено, 2: сколько можно. */
					'faqCount'      => __( 'Вопросов: %1$d из %2$d', 'indexpilot' ),
					'inserted'      => __( 'Заготовка вставлена в текст — заполните значения в кавычках.', 'indexpilot' ),
					'copied'        => __( 'Скопировано.', 'indexpilot' ),
					'insertCopied'  => __( 'Заготовка скопирована — вставьте её в текст и заполните значения в кавычках.', 'indexpilot' ),
					'copyFailed'    => __( 'Не удалось скопировать — выделите текст и скопируйте вручную.', 'indexpilot' ),
					/* translators: %s: тип материала, например «Гайд по архетипу». */
					'material'      => __( 'Тип материала: %s', 'indexpilot' ),
					/* translators: %s: имена переменных через запятую, например %sitedesc%. */
					'unknown'       => __( 'Неизвестная переменная: %s. На сайт она не выводится — исправьте имя или уберите её.', 'indexpilot' ),
					'readability'   => __( 'Удобство чтения и готовность к цитированию. На оценку SEO эта группа не влияет.', 'indexpilot' ),
				),
			)
		);
	}

	/**
	 * Печатает панель.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Предпросмотр: неизвестная переменная шаблона остаётся в тексте и называется в предупреждении.
		$context  = new Context( Context::POST, (int) $post->ID, $post->post_type, 1, array(), true );
		$resolved = $this->resolver->resolve( $context );
		$fallback = $this->resolver->resolve(
			new Context(
				Context::POST,
				(int) $post->ID,
				$post->post_type,
				1,
				array(
					'seo_title'       => '',
					'seo_description' => '',
				),
				true
			)
		);
		$unknown  = array_map( static fn( string $name ): string => '%' . $name . '%', $this->resolver->unknown_variables( $context ) );
		$value    = static fn( string $key ): string => (string) get_post_meta( $post->ID, $key, true );
		$keywords = get_post_meta( $post->ID, MetaKeys::KEYWORDS, true );
		$image_id = (int) $value( MetaKeys::OG_IMAGE_ID );
		$score    = $value( MetaKeys::SCORE );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div id="indexpilot-panel" class="indexpilot-panel">
			<div class="indexpilot-tabs" role="tablist">
				<button type="button" class="indexpilot-tab is-active" role="tab" aria-selected="true" data-tab="snippet"><?php esc_html_e( 'Вид в поиске', 'indexpilot' ); ?></button>
				<button type="button" class="indexpilot-tab" role="tab" aria-selected="false" data-tab="content"><?php esc_html_e( 'Содержание', 'indexpilot' ); ?></button>
				<button type="button" class="indexpilot-tab" role="tab" aria-selected="false" data-tab="analysis">
					<?php esc_html_e( 'Анализ', 'indexpilot' ); ?>
					<span class="indexpilot-score" data-indexpilot-score><?php echo esc_html( '' !== $score ? $score : '—' ); ?></span>
				</button>
				<button type="button" class="indexpilot-tab" role="tab" aria-selected="false" data-tab="queries"><?php esc_html_e( 'Запросы', 'indexpilot' ); ?></button>
				<button type="button" class="indexpilot-tab" role="tab" aria-selected="false" data-tab="social"><?php esc_html_e( 'Соцсети', 'indexpilot' ); ?></button>
				<button type="button" class="indexpilot-tab" role="tab" aria-selected="false" data-tab="advanced"><?php esc_html_e( 'Дополнительно', 'indexpilot' ); ?></button>
			</div>

			<div class="indexpilot-pane is-active" role="tabpanel" data-pane="snippet">
				<div class="indexpilot-preview-head">
					<strong><?php esc_html_e( 'Так запись будет выглядеть в Google и Яндексе', 'indexpilot' ); ?></strong> <?php HelpTip::render( __( 'ничего, это просмотр. Он меняется сам, пока вы заполняете поля ниже.', 'indexpilot' ), __( 'по этим двум строкам человек решает, открыть вашу статью или соседнюю в выдаче.', 'indexpilot' ) ); ?>
					<span class="indexpilot-devices">
						<button type="button" class="button button-small is-active" data-device="desktop"><?php esc_html_e( 'Компьютер', 'indexpilot' ); ?></button>
						<button type="button" class="button button-small" data-device="mobile"><?php esc_html_e( 'Телефон', 'indexpilot' ); ?></button>
					</span>
				</div>
				<div class="indexpilot-serp" data-indexpilot-serp data-device="desktop">
					<div class="indexpilot-serp-site">
						<span class="indexpilot-serp-icon" data-indexpilot-icon></span>
						<span>
							<span class="indexpilot-serp-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
							<span class="indexpilot-serp-url" data-indexpilot-url><?php echo esc_html( $resolved->canonical ); ?></span>
						</span>
					</div>
					<div class="indexpilot-serp-title" data-indexpilot-serp-title><?php echo esc_html( $resolved->title ); ?></div>
					<div class="indexpilot-serp-description" data-indexpilot-serp-description><?php echo esc_html( $resolved->description ); ?></div>
				</div>
				<p class="indexpilot-warning" data-indexpilot-unknown role="alert" <?php echo array() === $unknown ? 'hidden' : ''; ?>>
					<?php
					/* translators: %s: имена переменных через запятую, например %sitedesc%. */
					echo esc_html( sprintf( __( 'Неизвестная переменная: %s. На сайт она не выводится — исправьте имя или уберите её.', 'indexpilot' ), implode( ', ', $unknown ) ) );
					?>
				</p>

				<p class="indexpilot-field">
					<label for="indexpilot_title"><?php esc_html_e( 'Заголовок в поиске', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'синюю строку, которую увидят в выдаче, — до 60 знаков, главный запрос ближе к началу. Пример: «Колода жреца на воскрешении — гайд и муллиган». Можно оставить пустым: заголовок соберётся из названия записи (показан серым).', 'indexpilot' ), __( 'это первое, что видит человек в поиске. Название статьи на сайте может быть длинным и живым, а здесь нужно коротко и по запросу. Полоса под полем показывает, поместится ли строка целиком.', 'indexpilot' ) ); ?>
					<input type="text" id="indexpilot_title" name="indexpilot_title" class="large-text" value="<?php echo esc_attr( $value( MetaKeys::TITLE ) ); ?>" placeholder="<?php echo esc_attr( $fallback->title ); ?>" autocomplete="off">
					<span class="indexpilot-meter"><span class="indexpilot-meter-fill" data-indexpilot-title-meter></span></span>
					<span class="indexpilot-variables" data-indexpilot-variables data-target="indexpilot_title"></span>
					<span class="description"><?php esc_html_e( 'Пусто — заголовок соберётся сам (показан серым). Зелёная полоса — помещается, красная — поисковик обрежет.', 'indexpilot' ); ?></span>
				</p>

				<p class="indexpilot-field">
					<label for="indexpilot_description"><?php esc_html_e( 'Описание в поиске', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'одно-два предложения, 120–155 знаков: что человек получит, открыв страницу. Пример: «Список карт, муллиган и план игры против агрессии. Колода проверена на легенде в патче 37.0».', 'indexpilot' ), __( 'это серый текст под заголовком в выдаче. Если поле пустое, поисковик возьмёт случайный кусок текста — обычно хуже. Хорошее описание добавляет переходов при том же месте в поиске.', 'indexpilot' ) ); ?>
					<textarea id="indexpilot_description" name="indexpilot_description" class="large-text" rows="3" placeholder="<?php echo esc_attr( $fallback->description ); ?>"><?php echo esc_textarea( $value( MetaKeys::DESCRIPTION ) ); ?></textarea>
					<span class="indexpilot-meter"><span class="indexpilot-meter-fill" data-indexpilot-description-meter></span></span>
					<span class="indexpilot-counter" data-indexpilot-description-counter></span>
				</p>

				<p class="indexpilot-field">
					<label for="indexpilot_focus_keyword"><?php esc_html_e( 'Главный запрос', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'одну фразу, которую человек вводит в поиск и по которой должна находиться эта статья: «колода жреца на воскрешении», а не просто «жрец». Его не видят читатели.', 'indexpilot' ), __( 'плагин проверяет по нему заголовок, описание, первый абзац и подзаголовки, а ещё предупреждает, если этот же запрос уже занят другой вашей статьёй — тогда две страницы мешают друг другу в поиске.', 'indexpilot' ) ); ?>
					<input type="text" id="indexpilot_focus_keyword" name="indexpilot_focus_keyword" class="regular-text" value="<?php echo esc_attr( $value( MetaKeys::FOCUS_KEYWORD ) ); ?>" autocomplete="off">
					<span class="description"><?php esc_html_e( 'Главный запрос, по которому страница должна находиться. Формы слова и игровые синонимы учитываются: «колоды мага» засчитается за «колода мага», «дека приста» — за «колода жреца».', 'indexpilot' ); ?></span>
				</p>

				<p class="indexpilot-field">
					<label for="indexpilot_keywords"><?php esc_html_e( 'Похожие запросы', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'другие формулировки той же темы через запятую, до четырёх: «дека приста, жрец воскрешение муллиган». Поле необязательное.', 'indexpilot' ), __( 'плагин учитывает их в проверках, а при импорте сюда переносятся ключевые слова из старого SEO-плагина. Наружу на сайт они не выводятся.', 'indexpilot' ) ); ?>
					<input type="text" id="indexpilot_keywords" name="indexpilot_keywords" class="large-text" value="<?php echo esc_attr( implode( ', ', is_array( $keywords ) ? $keywords : array() ) ); ?>" autocomplete="off">
					<span class="description">
						<?php
						/* translators: %d: наибольшее число ключевых слов. */
						echo esc_html( sprintf( __( 'Через запятую, не больше %d.', 'indexpilot' ), MetaKeys::MAX_KEYWORDS ) );
						?>
					</span>
				</p>
			</div>

			<?php $this->render_content_pane( $post, $resolved->description ); ?>

			<div class="indexpilot-pane" role="tabpanel" data-pane="analysis" hidden>
				<p class="indexpilot-material">
					<strong data-indexpilot-material>
						<?php
						/* translators: %s: тип материала, например «Гайд по архетипу». */
						echo esc_html( sprintf( __( 'Тип материала: %s', 'indexpilot' ), MaterialType::label( PostMaterial::detect( $post ) ) ) );
						?>
					</strong>
					<?php HelpTip::render( __( 'ничего — тип определяется сам по названию, рубрикам и подзаголовкам статьи.', 'indexpilot' ), __( 'от типа зависит, каких разделов плагин ждёт в тексте (у гайда: состав колоды, муллиган, стратегия, матчапы, замены), и как статья помечается для поисковиков, если на вкладке «Содержание» выбрано «Авто».', 'indexpilot' ) ); ?>
				</p>
				<div data-indexpilot-analysis>
					<p class="description"><?php esc_html_e( 'Анализ появится после первого сохранения записи.', 'indexpilot' ); ?></p>
				</div>

				<div data-indexpilot-suggest-root>
					<h4><?php esc_html_e( 'На какие статьи сослаться', 'indexpilot' ); ?> <?php HelpTip::render( __( 'ничего: нажмите кнопку, плагин найдёт ваши статьи, о которых говорится в тексте, но ссылки на них ещё нет. Адрес копируется одной кнопкой — вставьте его ссылкой на подходящие слова.', 'indexpilot' ), __( 'ссылки между статьями помогают поисковику найти и оценить страницы, а читателю — перейти к соседней теме и остаться на сайте.', 'indexpilot' ) ); ?></h4>
					<p class="description"><?php esc_html_e( 'Страницы сайта, о которых говорится в тексте и на которые он ещё не ссылается.', 'indexpilot' ); ?></p>
					<p>
						<button type="button" class="button" data-indexpilot-suggest><?php esc_html_e( 'Подобрать ссылки', 'indexpilot' ); ?></button>
						<span data-indexpilot-suggest-status aria-live="polite"></span>
					</p>
					<ul class="indexpilot-suggestions" data-indexpilot-suggestions></ul>
				</div>

				<?php
				/**
				 * Свои подсказки на вкладке «Анализ», например классы и архетипы без ссылки на их страницу.
				 *
				 * @param \WP_Post $post Запись.
				 */
				do_action( 'indexpilot_metabox_analysis', $post );
				?>
			</div>

			<div class="indexpilot-pane" role="tabpanel" data-pane="queries" hidden>
				<?php
				/**
				 * Вкладка «Запросы»: запросы из Google Search Console и Яндекс Вебмастера (модуль данных поиска).
				 *
				 * @param \WP_Post $post Запись.
				 */
				do_action( 'indexpilot_metabox_queries', $post );
				?>
			</div>

			<div class="indexpilot-pane" role="tabpanel" data-pane="social" hidden>
				<div class="indexpilot-card" data-indexpilot-card>
					<div class="indexpilot-card-image" data-indexpilot-card-image>
						<?php
						$card_image = $resolved->og_image_id > 0 ? wp_get_attachment_image_url( $resolved->og_image_id, 'large' ) : '';
						if ( $card_image ) {
							printf( '<img src="%s" alt="">', esc_url( $card_image ) );
						}
						?>
					</div>
					<div class="indexpilot-card-host"><?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
					<div class="indexpilot-card-title" data-indexpilot-card-title><?php echo esc_html( $resolved->og_title ); ?></div>
					<div class="indexpilot-card-description" data-indexpilot-card-description><?php echo esc_html( $resolved->og_description ); ?></div>
				</div>

				<p class="indexpilot-field">
					<label for="indexpilot_og_title"><?php esc_html_e( 'Заголовок для соцсетей', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'заголовок карточки, которая появляется, когда ссылку присылают в Telegram или ВКонтакте. Можно живее, чем в поиске. Пусто — возьмётся заголовок с вкладки «Вид в поиске».', 'indexpilot' ), __( 'хорошая карточка в ленте соцсети собирает больше переходов.', 'indexpilot' ) ); ?>
					<input type="text" id="indexpilot_og_title" name="indexpilot_og_title" class="large-text" value="<?php echo esc_attr( $value( MetaKeys::OG_TITLE ) ); ?>" placeholder="<?php echo esc_attr( $resolved->og_title ); ?>">
				</p>
				<p class="indexpilot-field">
					<label for="indexpilot_og_description"><?php esc_html_e( 'Описание для соцсетей', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'одно предложение под заголовком карточки. Пусто — возьмётся описание с вкладки «Вид в поиске».', 'indexpilot' ), __( 'дополняет заголовок карточки и подталкивает открыть ссылку.', 'indexpilot' ) ); ?>
					<textarea id="indexpilot_og_description" name="indexpilot_og_description" class="large-text" rows="2" placeholder="<?php echo esc_attr( $resolved->og_description ); ?>"><?php echo esc_textarea( $value( MetaKeys::OG_DESCRIPTION ) ); ?></textarea>
				</p>
				<p class="indexpilot-field">
					<input type="hidden" id="indexpilot_og_image_id" name="indexpilot_og_image_id" value="<?php echo esc_attr( $image_id > 0 ? (string) $image_id : '' ); ?>">
					<span class="indexpilot-label"><?php esc_html_e( 'Картинка для соцсетей', 'indexpilot' ); ?> <?php HelpTip::render( __( 'картинку 1200 × 630 пикселей с крупным понятным изображением: арт класса, скриншот колоды. Пусто — возьмётся миниатюра записи.', 'indexpilot' ), __( 'без картинки ссылка в Telegram и ВКонтакте выглядит пустой строкой, и по ней почти не переходят.', 'indexpilot' ) ); ?></span>
					<button type="button" class="button" data-indexpilot-pick-image><?php esc_html_e( 'Выбрать картинку', 'indexpilot' ); ?></button>
					<button type="button" class="button-link" data-indexpilot-clear-image><?php esc_html_e( 'Убрать', 'indexpilot' ); ?></button>
					<span class="description"><?php esc_html_e( 'Пусто — возьмётся миниатюра записи. Лучший размер — 1200 × 630.', 'indexpilot' ); ?></span>
				</p>
			</div>

			<div class="indexpilot-pane" role="tabpanel" data-pane="advanced" hidden>
				<?php $shortlink = 'publish' === $post->post_status ? (string) wp_get_shortlink( $post->ID ) : ''; ?>
				<?php if ( '' !== $shortlink ) : ?>
					<p class="indexpilot-field" data-indexpilot-shortlink>
						<strong><?php esc_html_e( 'Короткая ссылка', 'indexpilot' ); ?></strong> <?php HelpTip::render( __( 'ничего, ссылка создаётся сама. Скопируйте её и вставьте в описание ролика, стрима или в чат.', 'indexpilot' ), __( 'короткую ссылку легко продиктовать на стриме, она не ломается при смене адреса статьи, а переходы по ней считаются.', 'indexpilot' ) ); ?><br>
						<code><?php echo esc_html( $shortlink ); ?></code>
						<button type="button" class="button-link" data-indexpilot-copy="<?php echo esc_attr( $shortlink ); ?>"><?php esc_html_e( 'скопировать', 'indexpilot' ); ?></button>
						<span class="description"><?php esc_html_e( 'Постоянный короткий адрес этой записи: удобно вставлять в описание стрима, видео или в чат.', 'indexpilot' ); ?></span>
					</p>
				<?php endif; ?>
				<p class="indexpilot-field">
					<label for="indexpilot_canonical"><?php esc_html_e( 'Главный адрес материала', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'обычно ничего. Заполняйте, только если эта страница — копия или старая версия другой, и в поиске должна быть та, другая: впишите её полный адрес.', 'indexpilot' ), __( 'когда один текст лежит по двум адресам, поисковик сам выбирает, какой показывать, и может выбрать не тот. Этим полем вы указываете главный. Внимание: страница с чужим главным адресом из поиска пропадёт.', 'indexpilot' ) ); ?>
					<input type="url" id="indexpilot_canonical" name="indexpilot_canonical" class="large-text" value="<?php echo esc_attr( $value( MetaKeys::CANONICAL ) ); ?>" placeholder="<?php echo esc_attr( (string) get_permalink( $post ) ); ?>">
					<span class="description"><?php esc_html_e( 'Обычно пусто. В поисковых системах это называется canonical.', 'indexpilot' ); ?></span>
				</p>
				<p class="indexpilot-field">
					<label><input type="checkbox" name="indexpilot_noindex" value="1" <?php checked( (bool) $value( MetaKeys::NOINDEX ) ); ?>> <?php esc_html_e( 'Не показывать эту страницу в поиске', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'отметьте для служебных, временных и пустых страниц: правила конкурса, черновой список, страница «спасибо».', 'indexpilot' ), __( 'такие страницы ничего не дают в поиске и отнимают у поисковика время на обход сайта. Обычную статью отмечать нельзя — она исчезнет из Google и Яндекса. В поисковых системах это называется noindex.', 'indexpilot' ) ); ?>
				</p>
				<p class="indexpilot-field">
					<label><input type="checkbox" name="indexpilot_nofollow" value="1" <?php checked( (bool) $value( MetaKeys::NOFOLLOW ) ); ?>> <?php esc_html_e( 'Поисковикам не учитывать ссылки с этой страницы', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'почти никогда. Отметьте, если на странице много ссылок, за которые вы не ручаетесь: подборка чужих сайтов, страница партнёров.', 'indexpilot' ), __( 'ссылка для поисковика — рекомендация. С этим флажком поисковик не будет считать ссылки со страницы рекомендациями. На читателей не влияет. В поисковых системах это называется nofollow.', 'indexpilot' ) ); ?>
				</p>
				<p class="indexpilot-field">
					<label><input type="checkbox" name="indexpilot_cornerstone" value="1" <?php checked( (bool) $value( MetaKeys::CORNERSTONE ) ); ?>> <?php esc_html_e( 'Главный материал по своей теме', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'отметьте большие гайды и обзоры, на которые должны ссылаться остальные статьи темы: «Все колоды жреца», «Мета ноября».', 'indexpilot' ), __( 'плагин чаще предлагает ссылки на такие страницы, включает их в путеводитель для ИИ-ассистентов (llms.txt) и показывает в отчётах первыми.', 'indexpilot' ) ); ?>
				</p>
				<p class="indexpilot-field">
					<label><input type="checkbox" id="<?php echo esc_attr( PaperExtras::FIELD ); ?>" name="<?php echo esc_attr( PaperExtras::FIELD ); ?>" value="1" <?php checked( PaperExtras::sponsored( (int) $post->ID ) ); ?>> <?php esc_html_e( 'В статье есть оплаченное размещение', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'отметьте, если за статью или за ссылки в ней заплатили: обзор по заказу, партнёрская ссылка, рекламная вставка. Плагин проверит, что у ссылок на рекламодателя стоит rel="sponsored", а в тексте есть пометка «Реклама» или «Партнёрский материал». Ссылки на свои площадки и второй сайт рекламой не считаются.', 'indexpilot' ), __( 'поисковики требуют помечать оплаченные ссылки, а закон — рекламу. Без пометки сайт может получить санкции, а ссылки — потерять вес.', 'indexpilot' ) ); ?>
				</p>
				<?php $this->render_placement_fields( $post ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Сохраняет поля панели.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function save( $post_id, $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		foreach ( self::TEXT_FIELDS as $field => $meta_key ) {
			// Значение очищает sanitize_callback, заданный при регистрации меты (см. MetaRegistrar).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
			update_post_meta( (int) $post_id, $meta_key, is_string( $value ) ? $value : '' );
		}

		foreach ( self::FLAG_FIELDS as $field => $meta_key ) {
			update_post_meta( (int) $post_id, $meta_key, isset( $_POST[ $field ] ) ? '1' : '' );
		}

		$keywords = isset( $_POST['indexpilot_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['indexpilot_keywords'] ) ) : '';
		update_post_meta( (int) $post_id, MetaKeys::KEYWORDS, array_filter( array_map( 'trim', explode( ',', $keywords ) ), 'strlen' ) );

		// Пары «вопрос — ответ»: строк в форме нет — значит, редактор удалил все. Строки очищает sanitize_callback меты.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$faq = isset( $_POST['indexpilot_faq'] ) ? wp_unslash( $_POST['indexpilot_faq'] ) : array();
		update_post_meta( (int) $post_id, MetaKeys::FAQ, wp_slash( FaqBlock::from_form( $faq ) ) );

		( new PostSwitch() )->save( (int) $post_id, ! empty( $_POST[ PostSwitch::FIELD ] ) );
	}

	/**
	 * Вкладка «Содержание»: краткий ответ, вопросы и ответы, факты и цитаты, текст для ролика, тип материала.
	 *
	 * @param \WP_Post $post        Запись.
	 * @param string   $description Итоговое описание записи — запасной текст для описания ролика.
	 */
	private function render_content_pane( \WP_Post $post, string $description ): void {
		$answer = (string) get_post_meta( $post->ID, MetaKeys::ANSWER, true );
		$faq    = FaqBlock::sanitize( get_post_meta( $post->ID, MetaKeys::FAQ, true ) );
		$choice = ArticleType::sanitize( (string) get_post_meta( $post->ID, MetaKeys::SCHEMA_TYPE, true ) );
		$auto   = ArticleType::resolve( ArticleType::AUTO, (string) ( $this->settings->post_type( $post->post_type )['schema_type'] ?? '' ), PostMaterial::detect( $post ) );
		$types  = array(
			ArticleType::ARTICLE => __( 'Статья', 'indexpilot' ),
			ArticleType::NEWS    => __( 'Новость', 'indexpilot' ),
			ArticleType::GUIDE   => __( 'Гайд', 'indexpilot' ),
			ArticleType::NONE    => __( 'Без пометки', 'indexpilot' ),
		);

		// Что сейчас означает «Авто» для этой записи: новость и патч — «Новость», остальное — тип из настроек типа записи.
		$auto_label = $types[ $auto ] ?? ( '' === $auto ? $types[ ArticleType::NONE ] : $auto );

		// Без скриптов в форме есть одна пустая пара; со скриптами строки добавляет кнопка.
		$rows = count( $faq ) < FaqBlock::MAX_ITEMS
			? array_merge(
				$faq,
				array(
					array(
						'question' => '',
						'answer'   => '',
					),
				)
			)
			: $faq;
		?>
		<div class="indexpilot-pane" role="tabpanel" data-pane="content" hidden>
			<p class="indexpilot-field">
				<label for="indexpilot_answer"><?php esc_html_e( 'Краткий ответ', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'ответ на вопрос из заголовка в 40–60 словах, понятный без остального текста. Пример: «Лучшая колода жреца сейчас — Воскрешение: 56 % побед на легенде в патче 37.0. Она сильна против контроля, слабее против агро. Держите в руке…»', 'indexpilot' ), __( 'читатель сразу видит суть в блоке «Коротко» в начале статьи. Поисковики и ИИ-ассистенты (ChatGPT, Алиса) охотнее берут в ответ готовый короткий абзац и ставят ссылку на источник.', 'indexpilot' ) ); ?>
				<textarea id="indexpilot_answer" name="indexpilot_answer" class="large-text" rows="4"><?php echo esc_textarea( $answer ); ?></textarea>
				<span class="indexpilot-counter" data-indexpilot-answer-counter data-min="<?php echo esc_attr( (string) AnswerBlock::MIN_WORDS ); ?>" data-max="<?php echo esc_attr( (string) AnswerBlock::MAX_WORDS ); ?>"></span>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: наименьшее число слов, 2: наибольшее число слов. */
							__( 'Суть статьи в %1$d–%2$d словах — так, чтобы абзац был понятен без остального текста. Читатель видит его блоком «Коротко» в начале статьи вместе с датой обновления; поисковики и ассистенты берут его в ответ. Если метаописание не заполнено, ответ идёт и в него.', 'indexpilot' ),
							AnswerBlock::MIN_WORDS,
							AnswerBlock::MAX_WORDS
						)
					);
					echo ' ';
					echo esc_html(
						$this->settings->general( 'answer_block_auto' )
							? __( 'Блок встаёт перед первым абзацем сам; шорткод [indexpilot_answer] ставит его в другое место.', 'indexpilot' )
							: __( 'Автоматический вывод выключен в настройках: поставьте в текст шорткод [indexpilot_answer].', 'indexpilot' )
					);
					?>
				</span>
			</p>

			<div class="indexpilot-field" data-indexpilot-faq data-max="<?php echo esc_attr( (string) FaqBlock::MAX_ITEMS ); ?>">
				<span class="indexpilot-label"><?php esc_html_e( 'Вопросы и ответы', 'indexpilot' ); ?> <?php HelpTip::render( __( 'вопросы, которые люди задают про эту тему, теми же словами, как их пишут в поиск («Что брать в муллиган против охотника?»), и прямой ответ в одно-три предложения.', 'indexpilot' ), __( 'блок выводится в статье и помечается для поисковиков. Ассистенты ищут ответы именно на такие вопросы — так статья чаще попадает в их ответы.', 'indexpilot' ) ); ?></span>
				<div data-indexpilot-faq-rows>
					<?php
					foreach ( $rows as $row ) {
						$this->render_faq_row( $row['question'], $row['answer'] );
					}
					?>
				</div>
				<template data-indexpilot-faq-template><?php $this->render_faq_row( '', '' ); ?></template>
				<p class="indexpilot-actions" data-indexpilot-faq-actions hidden>
					<button type="button" class="button" data-indexpilot-faq-add><?php esc_html_e( 'Добавить вопрос', 'indexpilot' ); ?></button>
					<span class="indexpilot-status" data-indexpilot-faq-count></span>
				</p>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: наибольшее число вопросов. */
							__( 'До %d вопросов — теми словами, какими их задают в поиске, и прямой ответ в одно-три предложения. Строка без вопроса или без ответа на сайт не выводится.', 'indexpilot' ),
							FaqBlock::MAX_ITEMS
						)
					);
					echo ' ';
					echo esc_html(
						$this->settings->general( 'faq_block_auto' )
							? __( 'Блок встаёт в конец статьи сам; шорткод [indexpilot_faq] ставит его в другое место.', 'indexpilot' )
							: __( 'Автоматический вывод выключен в настройках: поставьте в текст шорткод [indexpilot_faq].', 'indexpilot' )
					);
					?>
				</span>
			</div>

			<div class="indexpilot-field">
				<span class="indexpilot-label"><?php esc_html_e( 'Факты и цитаты', 'indexpilot' ); ?> <?php HelpTip::render( __( 'поставьте курсор в текст статьи и нажмите кнопку — появится заготовка. В факте заполните число, что измерено, источник со ссылкой и дату. В цитате — слова игрока, его имя и кто он (стример, киберспортсмен).', 'indexpilot' ), __( 'конкретные числа с источником и датой и слова известных игроков делают статью заметно убедительнее для читателя, поисковиков и ИИ-ассистентов: их цитируют чаще всего.', 'indexpilot' ) ); ?></span>
				<p class="indexpilot-actions">
					<button type="button" class="button" data-indexpilot-insert="<?php echo esc_attr( sprintf( '[%s value="" label="" source="" url="" date="%s" sample=""]', FactBlocks::STAT, wp_date( 'd.m.Y' ) ) ); ?>"><?php esc_html_e( 'Вставить факт', 'indexpilot' ); ?></button>
					<button type="button" class="button" data-indexpilot-insert="<?php echo esc_attr( sprintf( '[%1$s name="" role=""]%2$s[/%1$s]', FactBlocks::QUOTE, __( 'Слова игрока', 'indexpilot' ) ) ); ?>"><?php esc_html_e( 'Вставить цитату', 'indexpilot' ); ?></button>
					<span class="indexpilot-status" data-indexpilot-insert-status aria-live="polite"></span>
				</p>
				<span class="description"><?php esc_html_e( 'В заготовке факта: value — число («54 %»), label — что измерено («побед»), source — источник, url — его адрес, date — дата, sample — сколько игр («312 игр»). В цитате: name — имя, role — кто это. Факт без источника или даты помечается — пометку видите только вы.', 'indexpilot' ); ?></span>
			</div>

			<div class="indexpilot-field">
				<span class="indexpilot-label"><?php esc_html_e( 'Текст для ролика и стрима', 'indexpilot' ); ?> <?php HelpTip::render( __( 'ничего: нажмите кнопку, скопируйте готовый текст и вставьте в описание ролика на YouTube, стрима на Twitch или в пост в Telegram.', 'indexpilot' ), __( 'ИИ-ассистенты и поисковики не смотрят видео, они читают текст под ним. Описание со ссылкой на статью приводит зрителей на сайт.', 'indexpilot' ) ); ?></span>
				<p class="indexpilot-actions">
					<button type="button" class="button" data-indexpilot-blurb><?php esc_html_e( 'Описание для ролика', 'indexpilot' ); ?></button>
					<button type="button" class="button-link" data-indexpilot-blurb-copy hidden><?php esc_html_e( 'скопировать', 'indexpilot' ); ?></button>
					<span class="indexpilot-status" data-indexpilot-blurb-status aria-live="polite"></span>
				</p>
				<textarea class="large-text" rows="8" readonly data-indexpilot-blurb-text hidden><?php echo esc_textarea( AnalyzeController::blurb( new Context( Context::POST, (int) $post->ID, $post->post_type ), $description ) ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Готовый текст для описания на YouTube, Twitch и в Telegram: краткий ответ, разделы статьи (подзаголовки H2) и короткая ссылка. Без разметки — можно вставлять как есть.', 'indexpilot' ); ?></span>
			</div>

			<p class="indexpilot-field">
				<label for="indexpilot_schema_type"><?php esc_html_e( 'Чем считать материал для поисковиков', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'обычно оставьте «Авто». «Новость» — для событий с датой: анонс, патч, турнир. «Гайд» — для руководства. «Без пометки» — для служебных страниц.', 'indexpilot' ), __( 'плагин передаёт поисковикам скрытую пометку, что это за страница. Новость с такой пометкой может попасть в блок свежих новостей Google.', 'indexpilot' ) ); ?>
				<select id="indexpilot_schema_type" name="indexpilot_schema_type">
					<option value="" <?php selected( $choice, ArticleType::AUTO ); ?>>
						<?php
						/* translators: %s: тип материала, который действует по умолчанию. */
						echo esc_html( sprintf( __( 'Авто (%s)', 'indexpilot' ), mb_strtolower( $auto_label ) ) );
						?>
					</option>
					<?php foreach ( $types as $type => $label ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $choice, $type ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( '«Авто» — новости и патчи плагин узнаёт сам, остальное помечает статьёй.', 'indexpilot' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Одна пара «вопрос — ответ» в форме.
	 *
	 * @param string $question Вопрос.
	 * @param string $answer   Ответ.
	 */
	private function render_faq_row( string $question, string $answer ): void {
		?>
		<div class="indexpilot-faq-row" data-indexpilot-faq-row>
			<input type="text" name="indexpilot_faq[question][]" class="large-text" value="<?php echo esc_attr( $question ); ?>" placeholder="<?php esc_attr_e( 'Вопрос', 'indexpilot' ); ?>" aria-label="<?php esc_attr_e( 'Вопрос', 'indexpilot' ); ?>" autocomplete="off">
			<textarea name="indexpilot_faq[answer][]" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Ответ', 'indexpilot' ); ?>" aria-label="<?php esc_attr_e( 'Ответ', 'indexpilot' ); ?>"><?php echo esc_textarea( $answer ); ?></textarea>
			<button type="button" class="button-link button-link-delete" data-indexpilot-faq-remove hidden><?php esc_html_e( 'Удалить', 'indexpilot' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Поля вкладки «Дополнительно», которые описывают место записи на сайте: название в хлебных крошках,
	 * основная рубрика и выключатель блоков перехода.
	 *
	 * @param \WP_Post $post Запись.
	 */
	private function render_placement_fields( \WP_Post $post ): void {
		?>
		<p class="indexpilot-field">
			<label for="indexpilot_breadcrumb_title"><?php esc_html_e( 'Короткое название в цепочке разделов', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'короткое название статьи в 2–4 слова: «Жрец на воскрешении» вместо «Колода жреца на воскрешении — полный гайд, муллиган и матчапы». Можно оставить пустым — возьмётся название записи.', 'indexpilot' ), __( 'над статьёй и в поиске показывается путь: Главная › Колоды › Жрец на воскрешении. Длинное название в этом пути обрезается и читается плохо. Такую цепочку ещё называют «хлебными крошками».', 'indexpilot' ) ); ?>
			<input type="text" id="indexpilot_breadcrumb_title" name="indexpilot_breadcrumb_title" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, MetaKeys::BREADCRUMB_TITLE, true ) ); ?>" placeholder="<?php echo esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ); ?>" autocomplete="off">
			<span class="description"><?php esc_html_e( 'Для пути «Главная › Рубрика › Статья» над статьёй и в поиске. Пусто — название записи.', 'indexpilot' ); ?></span>
		</p>
		<?php
		if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$categories = get_the_category( $post->ID );
			$primary    = (int) get_post_meta( $post->ID, MetaKeys::PRIMARY_CATEGORY, true );
			?>
			<p class="indexpilot-field" data-indexpilot-primary-category <?php echo count( $categories ) < 2 ? 'hidden' : ''; ?>>
				<label for="indexpilot_primary_category"><?php esc_html_e( 'Основная рубрика', 'indexpilot' ); ?></label> <?php HelpTip::render( __( 'рубрику, к которой статья относится прежде всего, если отмечено несколько. Пример: статья в «Гайдах» и «Новостях» — основная «Гайды».', 'indexpilot' ), __( 'основная рубрика показывается в пути «Главная › Рубрика › Статья» и сообщается поисковикам и соцсетям как раздел статьи.', 'indexpilot' ) ); ?>
				<select id="indexpilot_primary_category" name="indexpilot_primary_category">
					<option value="0"><?php esc_html_e( 'Авто — самая вложенная рубрика', 'indexpilot' ); ?></option>
					<?php foreach ( $categories as $category ) : ?>
						<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( $primary, (int) $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description">
					<?php
					/* translators: %s: имя переменной шаблонов. */
					echo esc_html( sprintf( __( 'У записи несколько рубрик: выбранная пойдёт в путь над статьёй, в переменную %s и в раздел статьи для поисковиков и соцсетей.', 'indexpilot' ), '%category%' ) );
					?>
				</span>
			</p>
			<?php
		}

		( new PostSwitch() )->render( $post );
	}
}
