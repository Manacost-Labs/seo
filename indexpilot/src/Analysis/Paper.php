<?php
/**
 * Данные страницы для анализа.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Game\MaterialType;

/**
 * Всё, что анализатор знает о странице. Объект не зависит от WordPress: его собирают редактор, импорт и WP-CLI.
 */
final class Paper {

	/**
	 * Конструктор.
	 *
	 * @param string       $title              Итоговый SEO-заголовок (как на сайте).
	 * @param string       $description        Итоговое метаописание.
	 * @param string       $description_source Источник описания: custom, answer, template, auto или none.
	 * @param string       $slug               Слаг записи.
	 * @param string       $content            HTML текста записи.
	 * @param string       $keyword            Основное ключевое слово (Focus Keyword).
	 * @param list<string> $keywords           Дополнительные ключевые слова.
	 * @param string       $site_host          Хост сайта — чтобы отличать внутренние ссылки от внешних.
	 * @param int          $min_words          Наименьший объём текста для типа записи; 0 — не проверять.
	 * @param list<string> $duplicates         Названия других страниц с тем же основным ключом.
	 * @param int          $title_px           Ширина заголовка, измеренная браузером; 0 — оценить на сервере.
	 * @param int          $title_max_px       Наибольшая ширина заголовка в выдаче.
	 * @param int          $description_min    Наименьшая рекомендуемая длина описания.
	 * @param int          $description_max    Наибольшая рекомендуемая длина описания.
	 * @param list<array{href: string, final: string}>|null $redirected_links Ссылки текста на адреса с редиректом; null — редиректы сайта неизвестны.
	 * @param string       $redirect_target    Куда перенаправляет адрес самой страницы; пусто — редиректа нет.
	 * @param bool|null    $author_profile_complete Заполнен ли профиль автора; null — у страницы нет автора.
	 * @param list<array{title: string, url: string}>|null $partner_matches Страницы второго сайта с тем же ключом; null — связь не настроена или ответа нет.
	 * @param string|null  $answer             Краткий ответ из панели записи; null — у страницы такого поля нет.
	 * @param string       $material           Тип материала — константа MaterialType; пусто — не определялся (термин).
	 * @param bool|null    $has_social_image   Есть ли картинка для карточки в соцсетях (своя, миниатюра или общая из настроек); null — не проверять.
	 * @param bool         $noindex            Закрыта ли страница от индексации.
	 * @param string       $url                Собственный адрес страницы.
	 * @param string       $canonical          Канонический адрес страницы — как он выводится на сайте.
	 * @param int|null     $days_since_update  Сколько дней назад запись менялась; null — неизвестно.
	 * @param bool         $sponsored          Отмечено ли в записи оплаченное размещение.
	 * @param list<string> $own_hosts          Хосты своих площадок и сайтов: ссылки на них рекламой не считаются.
	 * @param array<int, int> $image_bytes     Вес картинок медиатеки в байтах: ID вложения => размер файла, который выводится в тексте.
	 * @param array{width: int, height: int}|array{}|null $featured_image Размеры миниатюры записи; пустой массив — миниатюры нет; null — не проверять.
	 * @param string       $material_types     Класс типов материалов игры записи: MaterialType или WowMaterialType.
	 * @param string       $game               Игра записи; пусто — не определялась (словарь игры сайта по умолчанию).
	 * @param list<string> $missing_game_names Названия игры, которых не хватает в заголовке о классе, общем для двух игр.
	 */
	public function __construct(
		public string $title = '',
		public string $description = '',
		public string $description_source = 'none',
		public string $slug = '',
		public string $content = '',
		public string $keyword = '',
		public array $keywords = array(),
		public string $site_host = '',
		public int $min_words = 0,
		public array $duplicates = array(),
		public int $title_px = 0,
		public int $title_max_px = 580,
		public int $description_min = 120,
		public int $description_max = 155,
		public ?array $redirected_links = null,
		public string $redirect_target = '',
		public ?bool $author_profile_complete = null,
		public ?array $partner_matches = null,
		public ?string $answer = null,
		public string $material = '',
		public ?bool $has_social_image = null,
		public bool $noindex = false,
		public string $url = '',
		public string $canonical = '',
		public ?int $days_since_update = null,
		public bool $sponsored = false,
		public array $own_hosts = array(),
		public array $image_bytes = array(),
		public ?array $featured_image = null,
		public string $material_types = MaterialType::class,
		public string $game = '',
		public array $missing_game_names = array()
	) {
	}
}
