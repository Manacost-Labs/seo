<?php
/**
 * Значения настроек по умолчанию.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Settings;

/**
 * Значения живут в коде, а не в базе: новый тип записи работает сразу, без пересохранения настроек.
 */
final class Defaults {

	/**
	 * Общие настройки.
	 *
	 * @return array<string, mixed>
	 */
	public static function general(): array {
		return array(
			'separator'                => '—',
			'index_paged_archives'     => true,
			'thin_archive_threshold'   => 3,
			'output_meta_keywords'     => false,
			'title_max_px'             => 580,
			'description_min'          => 120,
			'description_max'          => 155,
			'default_og_image_id'      => 0,
			// Организация — владелец сайта в разметке JSON-LD. Пустое название означает название сайта.
			'organization_name'        => '',
			'organization_logo_id'     => 0,
			// Адреса страниц сайта в соцсетях через запятую.
			'social_profiles'          => '',
			// Сообщать поисковикам об изменённых страницах (IndexNow). Включается на боевом сайте, не на тестовом.
			'indexnow_enabled'         => false,
			// Запретить в robots.txt роботов, которые собирают тексты для обучения нейросетей.
			'block_ai_training'        => false,
			// Запретить Google-Extended. Отдельно от остальных: он управляет и обучением Gemini, и ответами Gemini.
			'block_google_extended'    => false,
			// Отдавать /llms.txt — путеводитель по сайту для AI-ассистентов.
			'llms_txt'                 => true,
			// Сообщить Яндексу в robots.txt, что рекламные метки в адресе не меняют страницу.
			'yandex_clean_param'       => true,
			// Коды подтверждения прав на сайт.
			'verify_google'            => '',
			'verify_yandex'            => '',
			'verify_bing'              => '',
			// Добавлять блок «Об авторе» под текстом записей с разметкой статьи.
			'author_box_auto'          => false,
			// Ставить блок «Коротко» с кратким ответом перед первым абзацем статьи.
			'answer_block_auto'        => true,
			// Добавлять блок «Вопросы и ответы» в конец статьи.
			'faq_block_auto'           => true,
			// Добавлять блок «Мы в соцсетях» и ссылки «поделиться» после текста записи.
			'promo_follow_auto'        => false,
			// Второй сайт владельца: адрес и общий ключ доступа для проверки одинаковых ключевых слов.
			// Старые версии колоды указывают каноническим адресом на актуальную версию.
			'deck_version_canonical'   => true,
			// Короткие ссылки: адреса вида /go/КОД.
			'shortlinks_enabled'       => true,
			'shortlink_prefix'         => 'go',
			'partner_site_url'         => '',
			'partner_site_key'         => '',
			// Свои синонимы для игрового словаря: по строке на группу, «колода = дека, сборка».
			'custom_synonyms'          => '',
			// Блок с лучшими колодами и фразой-ответом над списком на страницах классов и архетипов.
			'hub_auto'                 => true,
			// Текущий патч игры: номер и дата выхода (ГГГГ-ММ-ДД). Гайды и колоды, не обновлённые с этой даты, устарели.
			'patch_version'            => '',
			'patch_date'               => '',
			// World of Warcraft: корневая рубрика раздела (0 — раздела нет), патч, название в разметке, шаблоны рубрик.
			'wow_root_term'            => 0,
			'wow_patch_version'        => '',
			'wow_patch_date'           => '',
			'wow_schema_name'          => 'World of Warcraft',
			'wow_term_title'           => '{{%parent_term% }}%term% %sep% гайды %game% %sep% %page% %sep% %sitename%',
			'wow_term_description'     => '{{%parent_term% }}%term% в %game%: гайды, таланты, ротация и экипировка на сайте %sitename%.',
			// Отдавать любую открытую запись чистым Markdown по адресу с «.md» на конце.
			'llms_markdown'            => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Настройки страниц без собственного объекта: главная, поиск, 404, автор, архив по дате, архив типа записи.
	 *
	 * @param string $context_type Вид страницы — константа Context.
	 * @return array<string, mixed>
	 */
	public static function special( string $context_type ): array {
		$defaults = array(
			'title'                => '%title% %sep% %page% %sep% %sitename%',
			'description'          => '',
			'noindex'              => false,
			// Подставляется, когда описание не задано и собрать его не из чего.
			'fallback_description' => '',
		);

		switch ( $context_type ) {
			case 'author':
				// Роль автора (стример, игрок, редактор) выводится, если она указана в профиле.
				$defaults['title']                = '%title%{{, %author_role%}} %sep% %page% %sep% %sitename%';
				$defaults['fallback_description'] = '%title% %sep% автор сайта %sitename%: все его материалы.';
				break;
			case 'home':
				$defaults['title'] = '%sitename% %sep% %page% %sep% %tagline%';
				break;
			case 'search':
			case '404':
			case 'date':
				$defaults['noindex'] = true;
				break;
		}

		return $defaults;
	}

	/**
	 * Настройки типа записи.
	 *
	 * @param string $post_type Тип записи.
	 * @return array<string, mixed>
	 */
	public static function post_type( string $post_type ): array {
		$defaults = array(
			'title'               => '%title% %sep% %page% %sep% %sitename%',
			// Пустой шаблон описания означает автогенерацию из источников ниже.
			'description'         => '',
			'description_sources' => array( 'excerpt', 'content' ),
			'noindex'             => false,
			'sitemap'             => true,
			'schema_type'         => 'WebPage',
			'min_words'           => 0,
			// Таксономия, по которой строятся хлебные крошки; пусто — рубрики или первая древовидная таксономия.
			'breadcrumb_taxonomy' => '',
		);

		switch ( $post_type ) {
			case 'post':
				$defaults['schema_type'] = 'Article';
				$defaults['min_words']   = 300;
				break;
			case 'attachment':
				$defaults['noindex'] = true;
				$defaults['sitemap'] = false;
				break;
		}

		return $defaults;
	}

	/**
	 * Настройки таксономии.
	 *
	 * @param string $taxonomy Таксономия.
	 * @return array<string, mixed>
	 */
	public static function taxonomy( string $taxonomy ): array {
		$defaults = array(
			'title'                => '%term% %sep% %page% %sep% %sitename%',
			// Пустой шаблон означает: взять описание термина, а если его нет — собрать из названия.
			'description'          => '',
			// Подставляется, когда у термина нет ни ручного описания, ни описания в WordPress.
			'fallback_description' => '%term% %sep% все материалы раздела на сайте %sitename%.',
			'noindex'              => false,
			'sitemap'              => true,
		);

		if ( 'post_format' === $taxonomy ) {
			$defaults['noindex'] = true;
			$defaults['sitemap'] = false;
		}

		return $defaults;
	}
}
