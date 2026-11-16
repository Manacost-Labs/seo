# IndexPilot

SEO-плагин WordPress для сайтов о Hearthstone и World of Warcraft: [hs-manacost.ru](https://hs-manacost.ru) и [kolodahearthstone.com](https://kolodahearthstone.com).

Заменяет All in One SEO, свой SEO-модуль и Redirection. Знает словарь игры: классы, архетипы, колоды, патчи и разговорные названия. Добавляет на страницы не больше 4 запросов к базе.

[![Проверки](https://github.com/Manacost-Labs/seo/actions/workflows/tests.yml/badge.svg)](https://github.com/Manacost-Labs/seo/actions/workflows/tests.yml)

## Возможности

- **Панель в статье.** Заголовок и описание в поиске с предпросмотром сниппета, главный запрос, краткий ответ, вопросы и ответы, соцсети. У каждого поля есть подсказка «что вписать и зачем».
- **Анализ текста.** Ключ в заголовке и первом абзаце, читаемость, полнота разделов по типу материала, ссылки, картинки и видео. Есть проверка «ключ уже занят другой статьёй» с учётом синонимов игры.
- **Мета-теги и разметка.** title, description, canonical, robots, Open Graph и Twitter. Граф schema.org: статья, колода, архетип, автор, крошки, платные части.
- **Карта сайта.** Файлы по типам записей и таксономиям, новости и авторы. Старые адреса карт перенаправляются; есть `robots.txt`, `llms.txt` и Markdown-версии записей.
- **Колоды.** Заголовки и описания по шаблонам; у старых версий колоды canonical ведёт на актуальную.
- **Редиректы.** Свой менеджер правил и журнал 404. Адрес записи при смене сразу получает редирект, правила Redirection переносятся.
- **Аудит.** Похожие тексты, мало текста, дубли заголовков и описаний, устаревшие после патча материалы, глубина страниц, мёртвые внешние ссылки.
- **Данные поиска.** Запросы из Search Console и Вебмастера в панели статьи, карта тем, страницы без трафика, проверка индексации.
- **Переходы.** Блоки перехода на площадки, счётчик кликов и короткие ссылки с QR-кодом.
- **Импорт** из AIOSEO, Rank Math и `svl-seo`: сначала пробный прогон, потом перенос без потерь.
- **IndexNow** и WP-CLI: `wp indexpilot import|audit|doctor|links|sitemap|redirects|health|…`.

## Требования

- WordPress 6.4 или новее, классический редактор.
- PHP 8.1 или новее.

## Установка

1. Скачайте архив из [выпусков](https://github.com/Manacost-Labs/seo/releases) или соберите сами (см. ниже).
2. «Плагины» → «Добавить новый» → «Загрузить плагин», затем активируйте.
3. Если на сайте есть другой SEO-плагин, IndexPilot свои теги не печатает. Сначала перенесите данные: «IndexPilot» → «Импорт», пробный прогон, затем перенос. Потом отключите старый плагин.

Порядок переключения и отката описан в [памятке администратора](docs/ADMIN.md). Работа с панелью статьи — в [памятке редактора](docs/EDITORS.md).

## Разработка

```bash
cd indexpilot
composer install
composer test    # PHPUnit
composer lint    # WordPress Coding Standards
node --test tests/js/*.test.js
```

Проверки внутри настоящего WordPress — [WordPress Playground](https://github.com/WordPress/wordpress-playground) (CLI 3.1.64):

```bash
cd indexpilot
npx @wp-playground/cli@3.1.64 server --port=9401 \
  --blueprint=tests/smoke/site-blueprint.json \
  --mount-dir "$(pwd)" /wordpress/wp-content/plugins/indexpilot
bash tests/smoke/run-all.sh
```

Сборка архива плагина без тестов и зависимостей разработки:

```bash
bash tools/build.sh   # dist/indexpilot-ВЕРСИЯ.zip
```

Сравнение SEO-тегов сайта до и после переключения: `php tools/compare-head.php sample|snapshot|diff`. Описание — в шапке файла.

## Структура

```
indexpilot/            плагин
  src/                 код (PSR-4, пространство имён IndexPilot)
  assets/              стили и скрипты админки
  tests/php/           модульные тесты
  tests/js/            тесты скриптов
  tests/smoke/         проверки внутри WordPress Playground
tools/                 сборка и сравнение тегов
docs/                  памятки администратора и редактора, принципы SEO
```

## Принципы

Чем плагин руководствуется и как каждое правило проверено — в [docs/SEO-PRINCIPLES.md](docs/SEO-PRINCIPLES.md). История изменений — в [CHANGELOG](indexpilot/CHANGELOG.md).

## Лицензия

[GPL-2.0-or-later](LICENSE) © Manacost Labs
