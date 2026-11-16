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

		return Settings::changes( $input, $this->site, $this->names() );
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
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$names = $this->names();
		?>
		<div class="wrap" data-indexpilot-settings>
			<h1><?php esc_html_e( 'IndexPilot — настройки', 'indexpilot' ); ?></h1>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Общие', 'indexpilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					/* translators: %s: имя переменной шаблона. */
					$this->text( 'general', '', 'separator', __( 'Разделитель', 'indexpilot' ), sprintf( __( 'Подставляется вместо %s в шаблонах.', 'indexpilot' ), '%sep%' ), 'small-text' );
					$this->checkbox( 'general', '', 'index_paged_archives', __( 'Страницы 2 и далее', 'indexpilot' ), __( 'Оставлять открытыми для индексации страницы 2+ рубрик и архивов (каноникал — на саму страницу)', 'indexpilot' ) );
					$this->number( 'general', '', 'thin_archive_threshold', __( 'Порог пустого архива', 'indexpilot' ), __( 'Архив метки или рубрики, в котором записей меньше этого числа, закрывается от индексации. 0 — не закрывать.', 'indexpilot' ) );
					$this->number( 'general', '', 'title_max_px', __( 'Ширина заголовка, px', 'indexpilot' ), __( 'Предел, после которого поисковик обрезает заголовок.', 'indexpilot' ) );
					$this->number( 'general', '', 'description_min', __( 'Описание: минимум символов', 'indexpilot' ), '' );
					$this->number( 'general', '', 'description_max', __( 'Описание: максимум символов', 'indexpilot' ), __( 'Автоматическое описание обрезается по концу предложения в пределах этой длины.', 'indexpilot' ) );
					$this->image( 'default_og_image_id', __( 'Картинка для соцсетей по умолчанию', 'indexpilot' ), __( 'Используется, когда у страницы нет своей картинки. Рекомендуемый размер — 1200 × 630.', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'output_meta_keywords', __( 'Тег keywords', 'indexpilot' ), __( 'Выводить ключевые слова в теге meta keywords (Google его не учитывает)', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'delete_data_on_uninstall', __( 'Удаление плагина', 'indexpilot' ), __( 'При удалении плагина стереть все его данные и настройки', 'indexpilot' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Поисковики', 'indexpilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( 'general', '', 'indexnow_enabled', __( 'IndexNow', 'indexpilot' ), __( 'Сообщать Яндексу и Bing об опубликованных, изменённых и удалённых страницах. Включайте только на боевом сайте.', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'block_ai_training', __( 'Роботы нейросетей', 'indexpilot' ), __( 'Запретить в robots.txt роботов, которые собирают тексты для обучения нейросетей. Поисковые роботы и роботы поиска ассистентов (ChatGPT, Perplexity, Claude) остаются открытыми всегда: они приводят читателей.', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'block_google_extended', __( 'Google-Extended', 'indexpilot' ), __( 'Запретить в robots.txt использование страниц в Gemini. Внимание: это закрывает не только обучение, но и ответы Gemini со ссылкой на сайт. На обычный поиск Google не влияет.', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'yandex_clean_param', __( 'Метки в адресах', 'indexpilot' ), __( 'Добавить в robots.txt директиву Clean-param: Яндекс не будет считать адреса с рекламными метками (utm, yclid, ysclid, gclid, fbclid) отдельными страницами', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'llms_txt', __( 'Файл llms.txt', 'indexpilot' ), __( 'Отдавать /llms.txt — список опорных материалов и рубрик для AI-ассистентов. Опорные материалы отмечаются в панели записи.', 'indexpilot' ) );
					$this->checkbox(
						'general',
						'',
						'llms_markdown',
						__( 'Статьи в виде текста для ассистентов', 'indexpilot' ),
						__( 'Отдавать каждую открытую статью чистым текстом по адресу с «.md» на конце: https://сайт/statya.md', 'indexpilot' ),
						HelpTip::html( __( 'ничего — достаточно галочки. Закрытые от поиска, запароленные статьи и платные части текста в эту версию не попадают.', 'indexpilot' ), __( 'ассистенты (ChatGPT, Perplexity, Claude) читают такой текст без меню и рекламы и точнее пересказывают статью со ссылкой на сайт. В поиск эта версия не попадает.', 'indexpilot' ) )
					);
					$this->text( 'general', '', 'verify_google', __( 'Код Google Search Console', 'indexpilot' ), __( 'Код подтверждения или весь тег meta целиком.', 'indexpilot' ), 'regular-text' );
					$this->text( 'general', '', 'verify_yandex', __( 'Код Яндекс Вебмастера', 'indexpilot' ), '', 'regular-text' );
					$this->text( 'general', '', 'verify_bing', __( 'Код Bing Webmaster', 'indexpilot' ), '', 'regular-text' );
					?>
				</table>

				<h2><?php esc_html_e( 'Колоды', 'indexpilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
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
					?>
				</table>
				<p class="description"><?php esc_html_e( 'Блок со ссылками на архетип, гайды и другие колоды выводится на странице колоды шорткодом [indexpilot_deck_links] или функцией indexpilot_deck_links() в шаблоне темы.', 'indexpilot' ); ?></p>

				<h2><?php esc_html_e( 'Словарь игры', 'indexpilot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Анализ, проверка занятых ключей и подбор ссылок уже знают игровые синонимы и разговорные имена классов: «дека приста» засчитывается за ключ «колода жреца». Здесь можно добавить свои слова.', 'indexpilot' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->textarea( 'custom_synonyms', __( 'Свои синонимы', 'indexpilot' ), __( 'По строке на группу: слово, знак «=», синонимы через запятую. Например: колода = дека, сборка', 'indexpilot' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Короткие ссылки', 'indexpilot' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( 'general', '', 'shortlinks_enabled', __( 'Короткие ссылки', 'indexpilot' ), __( 'Включить короткие адреса: у каждой записи — свой, плюс свои ссылки на любые адреса (IndexPilot → Короткие ссылки)', 'indexpilot' ) );
					$this->text( 'general', '', 'shortlink_prefix', __( 'Префикс короткого адреса', 'indexpilot' ), __( 'Часть адреса перед кодом ссылки: латинские буквы и цифры, например go или s. После смены префикса старые короткие адреса перестанут открываться.', 'indexpilot' ), 'small-text' );
					?>
				</table>

				<h2><?php esc_html_e( 'Второй сайт', 'indexpilot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Если на двух ваших сайтах стоит один и тот же основной ключ, страницы конкурируют между собой. Укажите адрес второго сайта и общий ключ доступа — анализ записи предупредит о совпадении. На втором сайте заполните те же поля: его адрес — этот сайт, ключ — тот же самый.', 'indexpilot' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->text( 'general', '', 'partner_site_url', __( 'Адрес второго сайта', 'indexpilot' ), __( 'Только https, например: https://kolodahearthstone.com', 'indexpilot' ), 'regular-text' );
					$this->text( 'general', '', 'partner_site_key', __( 'Общий ключ доступа', 'indexpilot' ), __( 'Любая строка из букв и цифр не короче 24 символов, одинаковая на обоих сайтах. Никому её не передавайте.', 'indexpilot' ), 'regular-text code' );
					?>
				</table>

				<h2><?php esc_html_e( 'Авторы', 'indexpilot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Роль, площадки и фото каждый автор заполняет в своём профиле пользователя. Шаблон заголовка страницы автора — ниже, в разделе «Служебные страницы».', 'indexpilot' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( 'general', '', 'author_box_auto', __( 'Блок «Об авторе»', 'indexpilot' ), __( 'Добавлять блок под текстом записей с разметкой статьи. Вручную блок выводится шорткодом [indexpilot_author].', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'promo_follow_auto', __( 'Блок «Мы в соцсетях»', 'indexpilot' ), __( 'Добавлять после текста записи ссылки на соцсети из списка площадок и ссылки «поделиться» в Telegram и ВКонтакте. Вручную блок выводится шорткодом [indexpilot_follow].', 'indexpilot' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Блоки в тексте статьи', 'indexpilot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Краткий ответ и вопросы редактор заполняет в панели записи на вкладке «Содержание». Блоки выводятся без скриптов и файлов стилей.', 'indexpilot' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox( 'general', '', 'answer_block_auto', __( 'Блок «Коротко»', 'indexpilot' ), __( 'Ставить краткий ответ и дату обновления перед первым абзацем статьи. Вручную блок выводится шорткодом [indexpilot_answer].', 'indexpilot' ) );
					$this->checkbox( 'general', '', 'faq_block_auto', __( 'Блок «Вопросы и ответы»', 'indexpilot' ), __( 'Добавлять вопросы и ответы в конец статьи. Вручную блок выводится шорткодом [indexpilot_faq]. Разметка FAQPage добавляется только там, где блок выведен.', 'indexpilot' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Организация в разметке', 'indexpilot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Эти сведения попадают в разметку JSON-LD каждой страницы: по ним поисковик понимает, кому принадлежит сайт.', 'indexpilot' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$this->text( 'general', '', 'organization_name', __( 'Название', 'indexpilot' ), __( 'Пусто — берётся название сайта.', 'indexpilot' ), 'regular-text' );
					$this->image( 'organization_logo_id', __( 'Логотип', 'indexpilot' ), __( 'Пусто — берётся логотип темы или значок сайта.', 'indexpilot' ) );
					$this->text( 'general', '', 'social_profiles', __( 'Страницы в соцсетях', 'indexpilot' ), __( 'Полные адреса через запятую: группа ВКонтакте, Telegram, YouTube и другие.', 'indexpilot' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Переменные шаблонов', 'indexpilot' ); ?></h2>
				<p>
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

				<h2><?php esc_html_e( 'Типы записей', 'indexpilot' ); ?></h2>
				<?php
				foreach ( $names['post_types'] as $post_type ) {
					$object = get_post_type_object( $post_type );
					$this->group_heading( $object ? $object->labels->name : $post_type, $post_type );
					echo '<table class="form-table" role="presentation">';
					$this->text( 'post_types', $post_type, 'title', __( 'Шаблон заголовка', 'indexpilot' ), '' );
					$this->text( 'post_types', $post_type, 'description', __( 'Шаблон описания', 'indexpilot' ), __( 'Пусто — описание собирается из отрывка или начала текста записи.', 'indexpilot' ) );
					$this->number( 'post_types', $post_type, 'min_words', __( 'Минимум слов в тексте', 'indexpilot' ), __( 'Для проверки объёма текста в анализе. 0 — не проверять.', 'indexpilot' ) );
					$this->text( 'post_types', $post_type, 'breadcrumb_taxonomy', __( 'Таксономия для хлебных крошек', 'indexpilot' ), __( 'Системное имя таксономии, например category. Пусто — рубрики или первая древовидная таксономия.', 'indexpilot' ), 'regular-text' );
					$this->checkbox( 'post_types', $post_type, 'noindex', __( 'Индексация', 'indexpilot' ), __( 'Закрыть все записи этого типа от индексации (noindex)', 'indexpilot' ) );
					$this->checkbox( 'post_types', $post_type, 'sitemap', __( 'Карта сайта', 'indexpilot' ), __( 'Включать записи этого типа в sitemap.xml', 'indexpilot' ) );
					echo '</table>';
				}
				?>

				<h2><?php esc_html_e( 'Таксономии', 'indexpilot' ); ?></h2>
				<?php
				foreach ( $names['taxonomies'] as $taxonomy ) {
					$object = get_taxonomy( $taxonomy );
					$this->group_heading( $object ? $object->labels->name : $taxonomy, $taxonomy );
					echo '<table class="form-table" role="presentation">';
					$this->text( 'taxonomies', $taxonomy, 'title', __( 'Шаблон заголовка', 'indexpilot' ), '' );
					$this->text( 'taxonomies', $taxonomy, 'description', __( 'Шаблон описания', 'indexpilot' ), __( 'Пусто — берётся описание термина.', 'indexpilot' ) );
					$this->text( 'taxonomies', $taxonomy, 'fallback_description', __( 'Описание, если у термина его нет', 'indexpilot' ), '' );
					$this->checkbox( 'taxonomies', $taxonomy, 'noindex', __( 'Индексация', 'indexpilot' ), __( 'Закрыть архивы этой таксономии от индексации (noindex)', 'indexpilot' ) );
					$this->checkbox( 'taxonomies', $taxonomy, 'sitemap', __( 'Карта сайта', 'indexpilot' ), __( 'Включать архивы этой таксономии в sitemap.xml', 'indexpilot' ) );
					echo '</table>';
				}
				?>

				<h2><?php esc_html_e( 'Служебные страницы', 'indexpilot' ); ?></h2>
				<?php
				foreach ( $this->special_labels() as $type => $label ) {
					// Ключ «404» PHP превращает в число, поэтому приводим обратно к строке.
					$type = (string) $type;
					$this->group_heading( $label, '' );
					echo '<table class="form-table" role="presentation">';
					$this->text( 'special', $type, 'title', __( 'Шаблон заголовка', 'indexpilot' ), '' );
					$this->text( 'special', $type, 'description', __( 'Шаблон описания', 'indexpilot' ), '' );
					$this->checkbox( 'special', $type, 'noindex', __( 'Индексация', 'indexpilot' ), __( 'Закрыть от индексации (noindex)', 'indexpilot' ) );
					echo '</table>';
				}

				submit_button();
				?>
			</form>
		</div>
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
		echo '<h3>' . esc_html( $label );

		if ( '' !== $slug ) {
			echo ' <code>' . esc_html( $slug ) . '</code>';
		}

		echo '</h3>';
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
	 */
	private function number( string $section, string $name, string $key, string $label, string $help ): void {
		$field = $this->field_name( $section, $name, $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
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
				<img src="<?php echo esc_url( $url ); ?>" alt="" style="display:block;max-width:240px;height:auto;margin-bottom:8px" data-indexpilot-image-preview <?php echo '' === $url ? 'hidden' : ''; ?>>
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
		echo '<p class="description indexpilot-unknown-variable" role="alert" style="color:#b32d2e">' . esc_html( sprintf( __( 'Неизвестная переменная: %s. На сайт она не выводится — исправьте имя или уберите её.', 'indexpilot' ), implode( ', ', $names ) ) ) . '</p>';
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
