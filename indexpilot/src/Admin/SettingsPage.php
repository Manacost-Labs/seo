<?php
/**
 * Страница настроек IndexPilot.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Game\Patch;
use IndexPilot\Meta\MetaRegistrar;
use IndexPilot\Plugin;
use IndexPilot\Resolver\Context;
use IndexPilot\Settings\Settings;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;

/**
 * Общие настройки и шаблоны заголовков и описаний для типов записей, таксономий и служебных страниц.
 *
 * Форма сохраняется штатным механизмом WordPress (options.php): он проверяет nonce и право manage_options.
 */
final class SettingsPage {

	public const SLUG = 'indexpilot';

	private const GROUP = 'indexpilot';

	/**
	 * Конструктор.
	 *
	 * @param Settings             $settings  Текущие настройки.
	 * @param array<string, mixed> $site      Значения по умолчанию для этого сайта.
	 * @param VariableRegistry     $variables Реестр переменных шаблонов.
	 */
	public function __construct(
		private Settings $settings,
		private array $site,
		private VariableRegistry $variables
	) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет раздел меню.
	 */
	public function add(): void {
		add_menu_page(
			__( 'IndexPilot — настройки', 'indexpilot' ),
			__( 'IndexPilot', 'indexpilot' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-chart-line',
			81
		);

		add_submenu_page(
			self::SLUG,
			__( 'IndexPilot — настройки', 'indexpilot' ),
			__( 'Настройки', 'indexpilot' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Регистрирует опцию и её очистку.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Очищает данные формы и оставляет только отличия от значений по умолчанию.
	 *
	 * @param mixed $input Данные формы.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		// Свои синонимы — единственное многострочное поле: переводы строк в нём разделяют группы.
		$synonyms = $input['general']['custom_synonyms'] ?? null;

		$input = map_deep(
			$input,
			static fn( $value ): string => is_scalar( $value ) ? MetaRegistrar::template_text( (string) $value ) : ''
		);

		if ( is_string( $synonyms ) ) {
			$input['general']['custom_synonyms'] = MetaRegistrar::template_text( $synonyms, true );
		}

		// Адрес второго сайта — только https: по нему уходит общий ключ доступа.
		if ( isset( $input['general']['partner_site_url'] ) ) {
			$input['general']['partner_site_url'] = \IndexPilot\CrossSite\PartnerClient::secure_url( (string) $input['general']['partner_site_url'] );
		}

		// Дата патча хранится в одном виде — ГГГГ-ММ-ДД; неверная дата не сохраняется.
		if ( isset( $input['general']['patch_date'] ) ) {
			$input['general']['patch_date'] = Patch::normalize_date( (string) $input['general']['patch_date'] );
		}

		return Settings::changes( GameSettings::sanitize( $input ), $this->site, $this->names() );
	}

	/**
	 * Подключает выбор картинки из медиатеки на странице настроек.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media();
		// Стили значков подсказки «?» у полей.
		wp_enqueue_style( 'indexpilot-panel', plugins_url( 'assets/css/panel.css', INDEXPILOT_FILE ), array(), Plugin::VERSION );
		wp_enqueue_script( 'indexpilot-settings', plugins_url( 'assets/js/settings.js', INDEXPILOT_FILE ), array(), Plugin::VERSION, true );
	}

	/**
	 * Вкладки страницы: адрес вкладки => название.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array {
		return array(
			'basic'    => __( 'Основное', 'indexpilot' ),
			'titles'   => __( 'Заголовки и описания', 'indexpilot' ),
			'indexing' => __( 'Индексация', 'indexpilot' ),
			'schema'   => __( 'Разметка и соцсети', 'indexpilot' ),
			'games'    => __( 'Игры и колоды', 'indexpilot' ),
			'services' => __( 'Связи и сервисы', 'indexpilot' ),
		);
	}

	/**
	 * Печатает страницу.
	 *
	 * Все вкладки лежат в одной форме и сохраняются вместе: переключение вкладок только прячет разделы,
	 * поэтому значения других вкладок при сохранении не теряются. Без скриптов разделы идут подряд.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$names = $this->names();
		$tabs  = $this->tabs();
		$first = (string) array_key_first( $tabs );
		?>
		<div class="wrap indexpilot-settings" data-indexpilot-settings>
			<h1><?php esc_html_e( 'IndexPilot — настройки', 'indexpilot' ); ?></h1>
			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper indexpilot-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Разделы настроек', 'indexpilot' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="#tab-<?php echo esc_attr( $slug ); ?>" class="nav-tab<?php echo $first === $slug ? ' nav-tab-active' : ''; ?>" id="indexpilot-tab-<?php echo esc_attr( $slug ); ?>" role="tab" aria-controls="indexpilot-section-<?php echo esc_attr( $slug ); ?>" aria-selected="<?php echo $first === $slug ? 'true' : 'false'; ?>" data-indexpilot-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="indexpilot-settings-form" data-indexpilot-settings-form>
				<?php
				settings_fields( self::GROUP );

				foreach ( $tabs as $slug => $label ) {
					printf(
						'<section class="indexpilot-settings-section%1$s" id="indexpilot-section-%2$s" role="tabpanel" aria-labelledby="indexpilot-tab-%2$s" data-indexpilot-section="%2$s"><h2 class="indexpilot-section-title">%3$s</h2>',
						$first === $slug ? ' is-active' : '',
						esc_attr( $slug ),
						esc_html( $label )
					);

					switch ( $slug ) {
						case 'basic':
							$this->render_basic( $names );
							break;
						case 'titles':
							$this->render_titles( $names );
							break;
						case 'indexing':
							$this->render_indexing( $names );
							break;
						case 'schema':
							$this->render_schema( $names );
							break;
						case 'games':
							$this->render_games();
							break;
						default:
							$this->render_services();
					}

					echo '</section>';
				}
				?>

				<div class="indexpilot-settings-submit">
					<?php submit_button( __( 'Сохранить изменения', 'indexpilot' ), 'primary', 'submit', false ); ?>
					<span class="description"><?php esc_html_e( 'Сохраняются все вкладки сразу.', 'indexpilot' ); ?></span>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Вкладка «Основное»: подтверждение сайта, сообщения поисковикам, анализ текста, удаление плагина.
	 *
	 * @param array{post_types: list<string>, taxonomies: list<string>, special: list<string>} $names Имена настроек.
	 */
	private function render_basic( array $names ): void {
		$this->subheading( __( 'Подтверждение прав на сайт', 'indexpilot' ), __( 'Коды из панелей вебмастера. Вставьте код подтверждения или весь тег meta целиком — плагин сам выделит код.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->text( 'general', '', 'verify_google', __( 'Google Search Console', 'indexpilot' ), '', 'regular-text code' );
		$this->text( 'general', '', 'verify_yandex', __( 'Яндекс Вебмастер', 'indexpilot' ), '', 'regular-text code' );
		$this->text( 'general', '', 'verify_bing', __( 'Bing Webmaster', 'indexpilot' ), '', 'regular-text code' );
		echo '</table>';

		$this->subheading( __( 'Сообщения поисковикам', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox(
			'general',
			'',
			'indexnow_enabled',
			__( 'IndexNow', 'indexpilot' ),
			__( 'Сообщать Яндексу и Bing об опубликованных, изменённых и удалённых страницах. Включайте только на боевом сайте.', 'indexpilot' ),
			HelpTip::html( __( 'ничего — достаточно галочки.', 'indexpilot' ), __( 'поисковик узнаёт о новой или обновлённой статье за минуты, а не ждёт, пока робот сам зайдёт на сайт.', 'indexpilot' ) )
		);
		echo '</table>';

		$this->subheading( __( 'Анализ текста', 'indexpilot' ), __( 'Минимальный объём текста, который проверяет анализ в панели записи. 0 — не проверять.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		foreach ( $names['post_types'] as $post_type ) {
			$this->number(
				'post_types',
				$post_type,
				'min_words',
				/* translators: %s: название типа записей, например «Записи». */
				sprintf( __( 'Минимум слов: %s', 'indexpilot' ), $this->post_type_label( $post_type ) ),
				''
			);
		}
		echo '</table>';

		$this->subheading( __( 'Удаление плагина', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'delete_data_on_uninstall', __( 'Данные плагина', 'indexpilot' ), __( 'При удалении плагина стереть все его данные и настройки', 'indexpilot' ) );
		echo '</table>';
	}

	/**
	 * Вкладка «Заголовки и описания»: длины, разделитель и шаблоны для всех видов страниц.
	 *
	 * @param array{post_types: list<string>, taxonomies: list<string>, special: list<string>} $names Имена настроек.
	 */
	private function render_titles( array $names ): void {
		$this->subheading( __( 'Длина и оформление', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		/* translators: %s: имя переменной шаблона. */
		$this->text( 'general', '', 'separator', __( 'Разделитель', 'indexpilot' ), sprintf( __( 'Подставляется вместо %s в шаблонах.', 'indexpilot' ), '%sep%' ), 'small-text', HelpTip::html( __( 'знак между частями заголовка: «—», «|» или «·».', 'indexpilot' ), __( 'по нему в выдаче отделяется название статьи от названия сайта.', 'indexpilot' ) ) );
		$this->number( 'general', '', 'title_max_px', __( 'Ширина заголовка, px', 'indexpilot' ), __( 'Предел, после которого поисковик обрезает заголовок.', 'indexpilot' ), HelpTip::html( __( 'обычно 580. Меньше — строже проверка длины.', 'indexpilot' ), __( 'по этой ширине панель записи показывает, поместится ли заголовок в выдаче целиком.', 'indexpilot' ) ) );
		$this->number( 'general', '', 'description_min', __( 'Описание: минимум символов', 'indexpilot' ), '' );
		$this->number( 'general', '', 'description_max', __( 'Описание: максимум символов', 'indexpilot' ), __( 'Автоматическое описание обрезается по концу предложения в пределах этой длины.', 'indexpilot' ) );
		$this->checkbox( 'general', '', 'output_meta_keywords', __( 'Тег keywords', 'indexpilot' ), __( 'Выводить ключевые слова в теге meta keywords (Google его не учитывает)', 'indexpilot' ) );
		echo '</table>';

		$this->variables_reference();

		$this->subheading( __( 'Типы записей', 'indexpilot' ), __( 'Пустой шаблон описания — описание собирается из отрывка или начала текста записи.', 'indexpilot' ) );
		foreach ( $names['post_types'] as $post_type ) {
			$this->group_heading( $this->post_type_label( $post_type ), $post_type );
			echo '<table class="form-table" role="presentation">';
			$this->text( 'post_types', $post_type, 'title', __( 'Шаблон заголовка', 'indexpilot' ), '' );
			$this->text( 'post_types', $post_type, 'description', __( 'Шаблон описания', 'indexpilot' ), '' );
			echo '</table>';
		}

		$this->subheading( __( 'Таксономии', 'indexpilot' ), __( 'Пустой шаблон описания — берётся описание термина.', 'indexpilot' ) );
		foreach ( $names['taxonomies'] as $taxonomy ) {
			$this->group_heading( $this->taxonomy_label( $taxonomy ), $taxonomy );
			echo '<table class="form-table" role="presentation">';
			$this->text( 'taxonomies', $taxonomy, 'title', __( 'Шаблон заголовка', 'indexpilot' ), '' );
			$this->text( 'taxonomies', $taxonomy, 'description', __( 'Шаблон описания', 'indexpilot' ), '' );
			$this->text( 'taxonomies', $taxonomy, 'fallback_description', __( 'Описание, если у термина его нет', 'indexpilot' ), '' );
			echo '</table>';
		}

		$this->subheading( __( 'Служебные страницы', 'indexpilot' ), '' );
		foreach ( $this->special_labels() as $type => $label ) {
			// Ключ «404» PHP превращает в число, поэтому приводим обратно к строке.
			$type = (string) $type;
			$this->group_heading( $label, '' );
			echo '<table class="form-table" role="presentation">';
			$this->text( 'special', $type, 'title', __( 'Шаблон заголовка', 'indexpilot' ), '' );
			$this->text( 'special', $type, 'description', __( 'Шаблон описания', 'indexpilot' ), '' );
			echo '</table>';
		}
	}

	/**
	 * Вкладка «Индексация»: что открыто поисковикам, карта сайта, robots.txt и файлы для ассистентов.
	 *
	 * @param array{post_types: list<string>, taxonomies: list<string>, special: list<string>} $names Имена настроек.
	 */
	private function render_indexing( array $names ): void {
		$this->subheading( __( 'Архивы', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'index_paged_archives', __( 'Страницы 2 и далее', 'indexpilot' ), __( 'Оставлять открытыми для индексации страницы 2+ рубрик и архивов (каноникал — на саму страницу)', 'indexpilot' ) );
		$this->number( 'general', '', 'thin_archive_threshold', __( 'Порог пустого архива', 'indexpilot' ), __( 'Архив метки или рубрики, в котором записей меньше этого числа, закрывается от индексации. 0 — не закрывать.', 'indexpilot' ), HelpTip::html( __( 'число записей, например 3.', 'indexpilot' ), __( 'почти пустые архивы меток поисковик считает малополезными страницами; закрытые, они не тянут вниз оценку сайта.', 'indexpilot' ) ) );
		echo '</table>';

		$this->subheading( __( 'Типы записей', 'indexpilot' ), __( 'Закрытые от индексации записи не попадают в поиск и в карту сайта.', 'indexpilot' ) );
		echo '<table class="form-table indexpilot-flags" role="presentation">';
		foreach ( $names['post_types'] as $post_type ) {
			$this->index_flags( 'post_types', $post_type, $this->post_type_label( $post_type ), true );
		}
		echo '</table>';

		$this->subheading( __( 'Таксономии', 'indexpilot' ), '' );
		echo '<table class="form-table indexpilot-flags" role="presentation">';
		foreach ( $names['taxonomies'] as $taxonomy ) {
			$this->index_flags( 'taxonomies', $taxonomy, $this->taxonomy_label( $taxonomy ), true );
		}
		echo '</table>';

		$this->subheading( __( 'Служебные страницы', 'indexpilot' ), '' );
		echo '<table class="form-table indexpilot-flags" role="presentation">';
		foreach ( $this->special_labels() as $type => $label ) {
			$this->index_flags( 'special', (string) $type, $label, false );
		}
		echo '</table>';

		$this->subheading( __( 'Роботы и robots.txt', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'block_ai_training', __( 'Роботы нейросетей', 'indexpilot' ), __( 'Запретить в robots.txt роботов, которые собирают тексты для обучения нейросетей. Поисковые роботы и роботы поиска ассистентов (ChatGPT, Perplexity, Claude) остаются открытыми всегда: они приводят читателей.', 'indexpilot' ) );
		$this->checkbox( 'general', '', 'block_google_extended', __( 'Google-Extended', 'indexpilot' ), __( 'Запретить в robots.txt использование страниц в Gemini. Внимание: это закрывает не только обучение, но и ответы Gemini со ссылкой на сайт. На обычный поиск Google не влияет.', 'indexpilot' ) );
		$this->checkbox( 'general', '', 'yandex_clean_param', __( 'Метки в адресах', 'indexpilot' ), __( 'Добавить в robots.txt директиву Clean-param: Яндекс не будет считать адреса с рекламными метками (utm, yclid, ysclid, gclid, fbclid) отдельными страницами', 'indexpilot' ) );
		echo '</table>';

		$this->subheading( __( 'Для AI-ассистентов', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'llms_txt', __( 'Файл llms.txt', 'indexpilot' ), __( 'Отдавать /llms.txt — список опорных материалов и рубрик для AI-ассистентов. Опорные материалы отмечаются в панели записи.', 'indexpilot' ) );
		$this->checkbox(
			'general',
			'',
			'llms_markdown',
			__( 'Статьи в виде текста для ассистентов', 'indexpilot' ),
			__( 'Отдавать каждую открытую статью чистым текстом по адресу с «.md» на конце: https://сайт/statya.md', 'indexpilot' ),
			HelpTip::html( __( 'ничего — достаточно галочки. Закрытые от поиска, запароленные статьи и платные части текста в эту версию не попадают.', 'indexpilot' ), __( 'ассистенты (ChatGPT, Perplexity, Claude) читают такой текст без меню и рекламы и точнее пересказывают статью со ссылкой на сайт. В поиск эта версия не попадает.', 'indexpilot' ) )
		);
		echo '</table>';
	}

	/**
	 * Вкладка «Разметка и соцсети»: организация, картинка для соцсетей, хлебные крошки, блоки в статье.
	 *
	 * @param array{post_types: list<string>, taxonomies: list<string>, special: list<string>} $names Имена настроек.
	 */
	private function render_schema( array $names ): void {
		$this->subheading( __( 'Организация в разметке', 'indexpilot' ), __( 'Эти сведения попадают в разметку JSON-LD каждой страницы: по ним поисковик понимает, кому принадлежит сайт.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->text( 'general', '', 'organization_name', __( 'Название', 'indexpilot' ), __( 'Пусто — берётся название сайта.', 'indexpilot' ), 'regular-text' );
		$this->image( 'organization_logo_id', __( 'Логотип', 'indexpilot' ), __( 'Пусто — берётся логотип темы или значок сайта.', 'indexpilot' ) );
		$this->text( 'general', '', 'social_profiles', __( 'Страницы в соцсетях', 'indexpilot' ), __( 'Полные адреса через запятую: группа ВКонтакте, Telegram, YouTube и другие.', 'indexpilot' ), 'large-text', HelpTip::html( __( 'адреса официальных страниц сайта через запятую.', 'indexpilot' ), __( 'поисковик связывает эти страницы с сайтом и может показать их в карточке сайта.', 'indexpilot' ) ) );
		echo '</table>';

		$this->subheading( __( 'Соцсети', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->image( 'default_og_image_id', __( 'Картинка по умолчанию', 'indexpilot' ), __( 'Используется, когда у страницы нет своей картинки. Рекомендуемый размер — 1200 × 630.', 'indexpilot' ) );
		echo '</table>';

		$this->subheading( __( 'Хлебные крошки', 'indexpilot' ), __( 'По какой таксономии строится цепочка «Главная → Раздел → Запись» в разметке и в блоке хлебных крошек.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		foreach ( $names['post_types'] as $post_type ) {
			$this->breadcrumb_select( $post_type );
		}
		echo '</table>';

		$this->subheading( __( 'Авторы', 'indexpilot' ), __( 'Роль, площадки и фото каждый автор заполняет в своём профиле пользователя. Шаблон заголовка страницы автора — на вкладке «Заголовки и описания».', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'author_box_auto', __( 'Блок «Об авторе»', 'indexpilot' ), __( 'Добавлять блок под текстом записей с разметкой статьи. Вручную блок выводится шорткодом [indexpilot_author].', 'indexpilot' ) );
		$this->checkbox( 'general', '', 'promo_follow_auto', __( 'Блок «Мы в соцсетях»', 'indexpilot' ), __( 'Добавлять после текста записи ссылки на соцсети из списка площадок и ссылки «поделиться» в Telegram и ВКонтакте. Вручную блок выводится шорткодом [indexpilot_follow].', 'indexpilot' ) );
		echo '</table>';

		$this->subheading( __( 'Блоки в тексте статьи', 'indexpilot' ), __( 'Краткий ответ и вопросы редактор заполняет в панели записи на вкладке «Содержание». Блоки выводятся без скриптов и файлов стилей.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'answer_block_auto', __( 'Блок «Коротко»', 'indexpilot' ), __( 'Ставить краткий ответ и дату обновления перед первым абзацем статьи. Вручную блок выводится шорткодом [indexpilot_answer].', 'indexpilot' ) );
		$this->checkbox( 'general', '', 'faq_block_auto', __( 'Блок «Вопросы и ответы»', 'indexpilot' ), __( 'Добавлять вопросы и ответы в конец статьи. Вручную блок выводится шорткодом [indexpilot_faq]. Разметка FAQPage добавляется только там, где блок выведен.', 'indexpilot' ) );
		echo '</table>';
	}

	/**
	 * Вкладка «Игры и колоды»: колоды и патч Hearthstone, раздел World of Warcraft, словарь игры.
	 */
	private function render_games(): void {
		$this->subheading( __( 'Колоды и патч Hearthstone', 'indexpilot' ), __( 'Блок со ссылками на архетип, гайды и другие колоды выводится на странице колоды шорткодом [indexpilot_deck_links] или функцией indexpilot_deck_links() в шаблоне темы.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'deck_version_canonical', __( 'Версии колоды', 'indexpilot' ), __( 'Старые версии колоды (тот же архетип, класс и режим) указывают каноническим адресом на самую новую неархивную версию', 'indexpilot' ) );
		$this->checkbox(
			'general',
			'',
			'hub_auto',
			__( 'Лучшие колоды на странице класса', 'indexpilot' ),
			__( 'Над списком колод класса и архетипа показывать лучшие актуальные колоды, фразу-ответ «Лучшая колода жреца на … — …» и ссылки на гайды. Вручную блок ставится шорткодом [indexpilot_hub] во вступление или нижний текст рубрики.', 'indexpilot' ),
			HelpTip::html( __( 'ничего — достаточно галочки. Колоды выбираются сами: по проценту побед, если сыграно не меньше 30 игр, иначе — самые свежие. Архивные колоды не показываются.', 'indexpilot' ), __( 'такая страница прямо отвечает на вопрос «какая колода лучшая для жреца» — поисковик и ассистент берут ответ отсюда и ведут читателя к колодам и гайдам.', 'indexpilot' ) )
		);
		$this->text(
			'general',
			'',
			'patch_version',
			__( 'Номер текущего патча', 'indexpilot' ),
			__( 'Например: 37.0. Пусто — проверка после патча выключена.', 'indexpilot' ),
			'small-text',
			HelpTip::html( __( 'номер последнего патча игры, как его пишут разработчики: 37.0, 37.2.', 'indexpilot' ), __( 'гайды и колоды, которые не обновлялись после патча, попадут в список «Контент → После патча» и получат отметку в списке записей; в обновлённых статьях рядом с датой появится «Актуально для патча 37.0».', 'indexpilot' ) )
		);
		$this->date_field(
			'patch_date',
			__( 'Дата выхода патча', 'indexpilot' ),
			__( 'Всё, что изменено раньше этого дня, считается устаревшим. Новости не устаревают никогда.', 'indexpilot' ),
			HelpTip::html( __( 'день, когда патч вышел в игре.', 'indexpilot' ), __( 'от этой даты плагин отсчитывает, какие гайды и колоды пора проверить.', 'indexpilot' ) )
		);
		echo '</table>';

		GameSettings::render( $this->settings );

		$this->subheading( __( 'Словарь игры', 'indexpilot' ), __( 'Анализ, проверка занятых ключей и подбор ссылок уже знают игровые синонимы и разговорные имена классов: «дека приста» засчитывается за ключ «колода жреца». Здесь можно добавить свои слова.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->textarea( 'custom_synonyms', __( 'Свои синонимы', 'indexpilot' ), __( 'По строке на группу: слово, знак «=», синонимы через запятую. Например: колода = дека, сборка', 'indexpilot' ) );
		echo '</table>';
	}

	/**
	 * Вкладка «Связи и сервисы»: короткие ссылки и второй сайт.
	 */
	private function render_services(): void {
		$this->subheading( __( 'Короткие ссылки', 'indexpilot' ), '' );
		echo '<table class="form-table" role="presentation">';
		$this->checkbox( 'general', '', 'shortlinks_enabled', __( 'Короткие ссылки', 'indexpilot' ), __( 'Включить короткие адреса: у каждой записи — свой, плюс свои ссылки на любые адреса (IndexPilot → Техника → Короткие ссылки)', 'indexpilot' ) );
		$this->text( 'general', '', 'shortlink_prefix', __( 'Префикс короткого адреса', 'indexpilot' ), __( 'Часть адреса перед кодом ссылки: латинские буквы и цифры, например go или s. После смены префикса старые короткие адреса перестанут открываться.', 'indexpilot' ), 'small-text', HelpTip::html( __( 'одно короткое слово латиницей: go, s, l.', 'indexpilot' ), __( 'короткий адрес выглядит как https://сайт/go/abc — его удобно вставлять в описание стрима или видео.', 'indexpilot' ) ) );
		echo '</table>';

		$this->subheading( __( 'Второй сайт', 'indexpilot' ), __( 'Если на двух ваших сайтах стоит один и тот же основной ключ, страницы конкурируют между собой. Укажите адрес второго сайта и общий ключ доступа — анализ записи предупредит о совпадении. На втором сайте заполните те же поля: его адрес — этот сайт, ключ — тот же самый.', 'indexpilot' ) );
		echo '<table class="form-table" role="presentation">';
		$this->text( 'general', '', 'partner_site_url', __( 'Адрес второго сайта', 'indexpilot' ), __( 'Только https, например: https://kolodahearthstone.com', 'indexpilot' ), 'regular-text' );
		$this->text( 'general', '', 'partner_site_key', __( 'Общий ключ доступа', 'indexpilot' ), __( 'Любая строка из букв и цифр не короче 24 символов, одинаковая на обоих сайтах. Никому её не передавайте.', 'indexpilot' ), 'regular-text code' );
		echo '</table>';
	}

	/**
	 * Свёрнутая справка о переменных шаблонов.
	 */
	private function variables_reference(): void {
		?>
		<details class="indexpilot-variables-help">
			<summary><?php esc_html_e( 'Какие переменные можно использовать в шаблонах', 'indexpilot' ); ?></summary>
			<p class="indexpilot-variable-list">
				<?php
				foreach ( $this->variables->names()['exact'] as $name ) {
					echo '<code>%' . esc_html( $name ) . '%</code> ';
				}
				?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: переменная произвольного поля, 2: переменная таксономии. */
						__( 'Произвольное поле записи: %1$s. Термины таксономии записи: %2$s. Фрагмент в двойных фигурных скобках {{ … }} выводится, только если все переменные внутри него не пустые.', 'indexpilot' ),
						'%cf_ИМЯ_ПОЛЯ%',
						'%tax_ТАКСОНОМИЯ%'
					)
				);
				?>
			</p>
		</details>
		<?php
	}

	/**
	 * Название типа записей для подписи.
	 *
	 * @param string $post_type Тип записи.
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return $object ? (string) $object->labels->name : $post_type;
	}

	/**
	 * Название таксономии для подписи.
	 *
	 * @param string $taxonomy Таксономия.
	 */
	private function taxonomy_label( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );

		return $object ? (string) $object->labels->name : $taxonomy;
	}

	/**
	 * Заголовок раздела внутри вкладки с необязательным пояснением.
	 *
	 * @param string $title Заголовок.
	 * @param string $help  Пояснение; пусто — без него.
	 */
	private function subheading( string $title, string $help ): void {
		echo '<h3 class="indexpilot-subheading">' . esc_html( $title ) . '</h3>';
		$this->help( $help );
	}

	/**
	 * Строка с флажками индексации и карты сайта для типа записей, таксономии или служебной страницы.
	 *
	 * @param string $section     Раздел: post_types, taxonomies или special.
	 * @param string $name        Тип записи, таксономия или вид страницы.
	 * @param string $label       Подпись строки.
	 * @param bool   $has_sitemap Есть ли у этого вида страниц карта сайта.
	 */
	private function index_flags( string $section, string $name, string $label, bool $has_sitemap ): void {
		$flags = array( 'noindex' => __( 'Закрыть от поиска (noindex)', 'indexpilot' ) );

		if ( $has_sitemap ) {
			$flags['sitemap'] = __( 'Включать в карту сайта', 'indexpilot' );
		}
		?>
		<tr>
			<th scope="row">
				<?php echo esc_html( $label ); ?>
				<?php if ( 'special' !== $section ) : ?>
					<code><?php echo esc_html( $name ); ?></code>
				<?php endif; ?>
			</th>
			<td>
				<?php
				foreach ( $flags as $key => $text ) {
					$field = $this->field_name( $section, $name, $key );
					?>
					<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="0">
					<label class="indexpilot-flag-option">
						<input type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( (bool) $this->value( $section, $name, $key ) ); ?>>
						<?php echo esc_html( $text ); ?>
					</label>
					<?php
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка выбора таксономии хлебных крошек для типа записей. Значение сохраняется тем же системным именем, что и раньше.
	 *
	 * @param string $post_type Тип записи.
	 */
	private function breadcrumb_select( string $post_type ): void {
		$field   = $this->field_name( 'post_types', $post_type, 'breadcrumb_taxonomy' );
		$current = (string) $this->value( 'post_types', $post_type, 'breadcrumb_taxonomy' );
		$options = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->public && 'post_format' !== $taxonomy->name ) {
				$options[ (string) $taxonomy->name ] = (string) $taxonomy->labels->name;
			}
		}

		// Значение, которого нет среди таксономий типа (например, таксономию отключили), не теряется.
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $this->post_type_label( $post_type ) ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>">
					<option value=""><?php esc_html_e( '— автоматически (рубрики или первая древовидная таксономия) —', 'indexpilot' ); ?></option>
					<?php foreach ( $options as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $label . ' (' . $slug . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Имена, для которых есть настройки: типы записей и таксономии со страницами на сайте, служебные страницы.
	 *
	 * @return array{post_types: list<string>, taxonomies: list<string>, special: list<string>}
	 */
	private function names(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( 'attachment' !== $post_type && is_post_type_viewable( $post_type ) ) {
				$post_types[] = (string) $post_type;
			}
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( 'post_format' !== $taxonomy && is_taxonomy_viewable( $taxonomy ) ) {
				$taxonomies[] = (string) $taxonomy;
			}
		}

		return array(
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
			'special'    => array_map( 'strval', array_keys( $this->special_labels() ) ),
		);
	}

	/**
	 * Служебные страницы: вид страницы => подпись.
	 *
	 * @return array<string, string>
	 */
	private function special_labels(): array {
		return array(
			Context::HOME              => __( 'Главная страница (лента записей)', 'indexpilot' ),
			Context::AUTHOR            => __( 'Страницы авторов', 'indexpilot' ),
			Context::POST_TYPE_ARCHIVE => __( 'Архивы типов записей', 'indexpilot' ),
			Context::DATE              => __( 'Архивы по дате', 'indexpilot' ),
			Context::SEARCH            => __( 'Поиск по сайту', 'indexpilot' ),
			Context::NOT_FOUND         => __( 'Страница 404', 'indexpilot' ),
		);
	}

	/**
	 * Текущее значение настройки.
	 *
	 * @param string $section Раздел: general, post_types, taxonomies или special.
	 * @param string $name    Тип записи, таксономия или вид страницы; для общего раздела — пусто.
	 * @param string $key     Имя настройки.
	 */
	private function value( string $section, string $name, string $key ): mixed {
		switch ( $section ) {
			case 'post_types':
				return $this->settings->post_type( $name )[ $key ] ?? '';
			case 'taxonomies':
				return $this->settings->taxonomy( $name )[ $key ] ?? '';
			case 'special':
				return $this->settings->special( $name )[ $key ] ?? '';
			default:
				return $this->settings->general( $key );
		}
	}

	/**
	 * Имя поля формы в формате опции.
	 *
	 * @param string $section Раздел.
	 * @param string $name    Тип записи, таксономия или вид страницы.
	 * @param string $key     Имя настройки.
	 */
	private function field_name( string $section, string $name, string $key ): string {
		return Settings::OPTION . '[' . $section . ']' . ( '' !== $name ? '[' . $name . ']' : '' ) . '[' . $key . ']';
	}

	/**
	 * Подзаголовок группы настроек.
	 *
	 * @param string $label Название.
	 * @param string $slug  Системное имя; пусто — не показывать.
	 */
	private function group_heading( string $label, string $slug ): void {
		echo '<h4 class="indexpilot-group-heading">' . esc_html( $label );

		if ( '' !== $slug ) {
			echo ' <code>' . esc_html( $slug ) . '</code>';
		}

		echo '</h4>';
	}

	/**
	 * Строка с текстовым полем.
	 *
	 * @param string $section Раздел.
	 * @param string $name    Тип записи, таксономия или вид страницы.
	 * @param string $key     Имя настройки.
	 * @param string $label   Подпись.
	 * @param string $help    Пояснение.
	 * @param string $css     Класс поля.
	 * @param string $tip     Готовый HTML значка подсказки (HelpTip::html) или пусто.
	 */
	private function text( string $section, string $name, string $key, string $label, string $help, string $css = 'large-text', string $tip = '' ): void {
		$field = $this->field_name( $section, $name, $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label> <?php echo $tip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран и экранирован в HelpTip::html(). ?></th>
			<td>
				<input type="text" class="<?php echo esc_attr( $css ); ?>" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $this->value( $section, $name, $key ) ); ?>">
				<?php
				if ( 'general' !== $section ) {
					$this->unknown_variables( (string) $this->value( $section, $name, $key ) );
				}

				$this->help( $help );
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка с многострочным полем общей настройки.
	 *
	 * @param string $key   Имя общей настройки.
	 * @param string $label Подпись.
	 * @param string $help  Пояснение.
	 */
	private function textarea( string $key, string $label, string $help ): void {
		$field = $this->field_name( 'general', '', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea class="large-text" rows="5" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"><?php echo esc_textarea( (string) $this->value( 'general', '', $key ) ); ?></textarea>
				<?php $this->help( $help ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка с числовым полем.
	 *
	 * @param string $section Раздел.
	 * @param string $name    Тип записи, таксономия или вид страницы.
	 * @param string $key     Имя настройки.
	 * @param string $label   Подпись.
	 * @param string $help    Пояснение.
	 * @param string $tip     Готовый HTML значка подсказки (HelpTip::html) или пусто.
	 */
	private function number( string $section, string $name, string $key, string $label, string $help, string $tip = '' ): void {
		$field = $this->field_name( $section, $name, $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label> <?php echo $tip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран и экранирован в HelpTip::html(). ?></th>
			<td>
				<input type="number" min="0" step="1" class="small-text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) (int) $this->value( $section, $name, $key ) ); ?>">
				<?php $this->help( $help ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка с флажком. Скрытое поле передаёт «выключено»: снятый флажок браузер не отправляет.
	 *
	 * @param string $section Раздел.
	 * @param string $name    Тип записи, таксономия или вид страницы.
	 * @param string $key     Имя настройки.
	 * @param string $label   Подпись строки.
	 * @param string $text    Текст у флажка.
	 * @param string $tip     Готовый HTML значка подсказки (HelpTip::html) или пусто.
	 */
	private function checkbox( string $section, string $name, string $key, string $label, string $text, string $tip = '' ): void {
		$field = $this->field_name( $section, $name, $key );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?> <?php echo $tip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран и экранирован в HelpTip::html(). ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="0">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( (bool) $this->value( $section, $name, $key ) ); ?>>
					<?php echo esc_html( $text ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка с полем даты общей настройки.
	 *
	 * @param string $key   Имя общей настройки.
	 * @param string $label Подпись.
	 * @param string $help  Пояснение.
	 * @param string $tip   Готовый HTML значка подсказки (HelpTip::html) или пусто.
	 */
	private function date_field( string $key, string $label, string $help, string $tip = '' ): void {
		$field = $this->field_name( 'general', '', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label> <?php echo $tip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран и экранирован в HelpTip::html(). ?></th>
			<td>
				<input type="date" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $this->value( 'general', '', $key ) ); ?>">
				<?php $this->help( $help ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Строка выбора картинки из медиатеки.
	 *
	 * @param string $key   Имя общей настройки с ID картинки.
	 * @param string $label Подпись.
	 * @param string $help  Пояснение.
	 */
	private function image( string $key, string $label, string $help ): void {
		$field    = $this->field_name( 'general', '', $key );
		$image_id = (int) $this->value( 'general', '', $key );
		$url      = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td data-indexpilot-image>
				<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $image_id ); ?>" data-indexpilot-image-id>
				<img src="<?php echo esc_url( $url ); ?>" alt="" class="indexpilot-image-preview" data-indexpilot-image-preview <?php echo '' === $url ? 'hidden' : ''; ?>>
				<button type="button" class="button" data-indexpilot-image-select><?php esc_html_e( 'Выбрать картинку', 'indexpilot' ); ?></button>
				<button type="button" class="button-link" data-indexpilot-image-remove><?php esc_html_e( 'Убрать', 'indexpilot' ); ?></button>
				<?php $this->help( $help ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Предупреждение под шаблоном, в котором есть переменная, неизвестная плагину: опечатка или остаток другого плагина.
	 * Сам шаблон остаётся как написан; на сайт такая переменная не выводится.
	 *
	 * @param string $template Шаблон заголовка или описания.
	 */
	private function unknown_variables( string $template ): void {
		$unknown = ( new Renderer( $this->variables ) )->unknown_tokens( $template );

		if ( array() === $unknown ) {
			return;
		}

		$names = array_map( static fn( string $name ): string => '%' . $name . '%', $unknown );

		/* translators: %s: имена переменных через запятую, например %sitedesc%. */
		echo '<p class="description indexpilot-unknown-variable" role="alert">' . esc_html( sprintf( __( 'Неизвестная переменная: %s. На сайт она не выводится — исправьте имя или уберите её.', 'indexpilot' ), implode( ', ', $names ) ) ) . '</p>';
	}

	/**
	 * Пояснение под полем.
	 *
	 * @param string $help Текст.
	 */
	private function help( string $help ): void {
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}
}
