<?php
/**
 * Команды WP-CLI.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Cli;

use IndexPilot\Doctor\Checkup;
use IndexPilot\Doctor\QueryLog;
use IndexPilot\Import\ImportState;
use IndexPilot\Plugin;
use IndexPilot\Sitemap\Cache as SitemapCache;

/**
 * Обслуживание плагина из командной строки: то же, что делают кнопки в админке, но без ограничений браузера.
 * Удобно для первого прохода по тысячам записей.
 */
final class Commands {

	private const BATCH = 100;

	/**
	 * Конструктор.
	 *
	 * @param Plugin $plugin Плагин.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Пересчитывает оценку SEO у опубликованных записей.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Все типы записей, у которых есть страницы на сайте. Без параметров — то же самое.
	 *
	 * [--post_type=<type>]
	 * : Только один тип записи.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot analyze --all
	 *     wp indexpilot analyze --post_type=hs_deck
	 *
	 * @param list<string>          $args       Позиционные аргументы.
	 * @param array<string, string> $assoc_args Именованные аргументы.
	 */
	public function analyze( $args, $assoc_args ): void {
		unset( $args );

		$types = isset( $assoc_args['post_type'] ) ? array( (string) $assoc_args['post_type'] ) : $this->plugin->link_scanner()->types();
		$types = array_values( array_filter( $types, 'is_post_type_viewable' ) );

		if ( array() === $types ) {
			\WP_CLI::error( 'Нет типов записей со страницами на сайте.' );
		}

		$updater = $this->plugin->score_updater();
		$last_id = 0;
		$count   = 0;

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		do {
			// Пачки берутся по диапазону ID: так проход не замедляется на тысячах записей.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу типов записей.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- разовый проход из командной строки.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( {$placeholders} ) AND ID > %d ORDER BY ID ASC LIMIT %d",
					array_merge( $types, array( $last_id, self::BATCH ) )
				)
			);
			// phpcs:enable

			foreach ( $ids as $id ) {
				$updater->update_post( (int) $id, get_post( (int) $id ) );
				$last_id = (int) $id;
				++$count;
			}

			$full_batch = count( $ids ) === self::BATCH;

			$this->free_memory();
		} while ( $full_batch );

		\WP_CLI::success( sprintf( 'Оценка пересчитана, записей: %d.', $count ) );
	}

	/**
	 * Переносит поля из другого SEO-плагина.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Источник: aioseo, svl_seo или rank_math.
	 *
	 * [--dry-run]
	 * : Только показать, сколько будет перенесено. Ничего не записывается.
	 *
	 * [--overwrite]
	 * : Заменять поля, уже заполненные в IndexPilot.
	 *
	 * [--restart]
	 * : Начать сначала, а не с места прошлой остановки.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot import aioseo --dry-run
	 *     wp indexpilot import aioseo
	 *
	 * @param list<string>          $args       Позиционные аргументы.
	 * @param array<string, string> $assoc_args Именованные аргументы.
	 */
	public function import( $args, $assoc_args ): void {
		$sources = $this->plugin->import_sources();
		$source  = $sources[ (string) ( $args[0] ?? '' ) ] ?? null;

		if ( null === $source || ! $source->available() ) {
			\WP_CLI::error( 'Источник не найден или его данных на сайте нет. Доступные: ' . implode( ', ', array_keys( $sources ) ) );
		}

		$dry_run   = isset( $assoc_args['dry-run'] );
		$overwrite = isset( $assoc_args['overwrite'] );
		$importer  = $this->plugin->importer();
		$state     = new ImportState();

		if ( isset( $assoc_args['restart'] ) && ! $dry_run ) {
			$state->reset( $source->id() );
		}

		$after = $dry_run ? 0 : $state->get( $source->id() )['last_id'];
		$total = array(
			'processed' => 0,
			'imported'  => 0,
			'skipped'   => 0,
			'fields'    => 0,
		);

		do {
			$result = $importer->run( $source, $after, self::BATCH, $dry_run, $overwrite );
			$after  = $result['last_id'];

			foreach ( array_keys( $total ) as $key ) {
				$total[ $key ] += $result[ $key ];
			}

			if ( ! $dry_run ) {
				$state->add( $source->id(), $result );
			}

			$this->free_memory();
		} while ( ! $result['done'] );

		// Закрытые от индексации виды страниц (метки, авторы, даты, поиск) AIOSEO хранит в общих настройках.
		if ( 'aioseo' === $source->id() ) {
			foreach ( \IndexPilot\Import\AioseoSettings::apply( $dry_run ) as $section => $names ) {
				\WP_CLI::log( sprintf( 'Закрыто от индексации (%s): %s', $section, implode( ', ', $names ) ) );
			}
		}

		\WP_CLI::success(
			sprintf(
				'%s: обработано строк — %d, записей с переносом — %d, полей — %d, пропущено — %d.',
				$dry_run ? 'Пробный прогон (ничего не записано)' : 'Импорт завершён',
				$total['processed'],
				$total['imported'],
				$total['fields'],
				$total['skipped']
			)
		);
	}

	/**
	 * Строит граф ссылок между записями.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: scan.
	 *
	 * [--restart]
	 * : Обойти записи заново, а не с места прошлой остановки.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot links scan --restart
	 *
	 * @param list<string>          $args       Позиционные аргументы.
	 * @param array<string, string> $assoc_args Именованные аргументы.
	 */
	public function links( $args, $assoc_args ): void {
		if ( 'scan' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Неизвестное действие. Доступно: scan.' );
		}

		$scanner = $this->plugin->link_scanner();
		$restart = isset( $assoc_args['restart'] );

		do {
			$result  = $scanner->scan_batch( $restart );
			$restart = false;

			$this->free_memory();
		} while ( ! $result['done'] );

		\WP_CLI::success( sprintf( 'Граф ссылок построен, записей: %d.', $result['processed'] ) );
	}

	/**
	 * Проводит аудит всех опубликованных записей и печатает сводку.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot audit
	 */
	public function audit(): void {
		$runner  = $this->plugin->audit();
		$restart = true;

		do {
			$result  = $runner->run_batch( $restart );
			$restart = false;

			$this->free_memory();
		} while ( ! $result['done'] );

		\WP_CLI::log( sprintf( 'Проверено записей: %d.', $result['processed'] ) );

		foreach ( $runner->summary() as $problem => $count ) {
			\WP_CLI::log( sprintf( '%s: %d', $problem, $count ) );
		}

		\WP_CLI::success( 'Аудит завершён. Подробности — в админке: IndexPilot → Аудит.' );
	}

	/**
	 * Работа с колодами.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: versions — пересчитать, какая версия каждой колоды актуальная.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot decks versions
	 *
	 * @param list<string> $args Позиционные аргументы.
	 */
	public function decks( $args ): void {
		if ( 'versions' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Неизвестное действие. Доступно: versions.' );
		}

		$map     = $this->plugin->game()->rebuild_versions();
		$current = count( array_filter( $map, static fn( int $current_id, int $deck_id ): bool => $current_id === $deck_id, ARRAY_FILTER_USE_BOTH ) );

		( new SitemapCache() )->flush();

		\WP_CLI::success( sprintf( 'Колод: %d, из них актуальных версий: %d. Кэш карты сайта сброшен.', count( $map ), $current ) );
	}

	/**
	 * Работа с картой сайта.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: flush — сбросить сохранённые файлы карты.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot sitemap flush
	 *
	 * @param list<string> $args Позиционные аргументы.
	 */
	public function sitemap( $args ): void {
		if ( 'flush' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Неизвестное действие. Доступно: flush.' );
		}

		( new SitemapCache() )->flush();

		\WP_CLI::success( 'Кэш карты сайта сброшен.' );
	}

	/**
	 * Работа с IndexNow.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: send — отправить поисковикам накопленные адреса, не дожидаясь расписания.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot indexnow send
	 *
	 * @param list<string> $args Позиционные аргументы.
	 */
	public function indexnow( $args ): void {
		if ( 'send' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Неизвестное действие. Доступно: send.' );
		}

		$notifier = $this->plugin->indexnow();

		if ( ! $notifier->enabled() ) {
			\WP_CLI::error( 'IndexNow выключен в настройках плагина или сайт закрыт от поисковиков.' );
		}

		$notifier->send();

		\WP_CLI::success( 'Пачка адресов отправлена.' );
	}

	/**
	 * Замеряет, сколько запросов к базе и времени плагин добавляет на страницу записи, рубрики и на экран админки.
	 *
	 * Страницы открываются внутренним запросом с подписанным одноразовым параметром. Обычные запросы посетителей
	 * замер не затрагивает. Предел для страницы записи и рубрики — 4 запроса.
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : Дополнительные страницы этого сайта, которые нужно замерить.
	 *
	 * [--queries]
	 * : Показать запросы плагина на каждой странице, даже если предел не превышен.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot doctor
	 *     wp indexpilot doctor https://example.com/deck/blood-dk/ --queries
	 *
	 * @param list<string>          $args       Позиционные аргументы.
	 * @param array<string, string> $assoc_args Именованные аргументы.
	 */
	public function doctor( $args, $assoc_args ): void {
		$checkup = new Checkup();
		$targets = $checkup->targets();

		foreach ( $args as $index => $url ) {
			$targets[ 'url_' . $index ] = array(
				'label'   => (string) $url,
				'url'     => (string) $url,
				'limited' => true,
			);
		}

		$rows   = array();
		$errors = 0;
		$failed = false;

		foreach ( $targets as $key => $target ) {
			$result = 'admin' === $key ? $checkup->admin_page( $target['url'], $checkup->admin_user() ) : $checkup->page( $target['url'] );

			if ( isset( $result['error'] ) ) {
				\WP_CLI::warning( $target['label'] . ' (' . $target['url'] . '): ' . $result['error'] );
				++$errors;

				continue;
			}

			$over = $target['limited'] && ! QueryLog::within_limit( (int) $result['plugin'] );

			$rows[] = array(
				'Страница'         => $target['label'],
				'Запросов плагина' => (int) $result['plugin'],
				'Их время, мс'     => $result['plugin_ms'],
				'Всего запросов'   => (int) $result['total'],
				'Страница, мс'     => $result['page_ms'],
				'Предел'           => $target['limited'] ? ( $over ? 'превышен' : 'в норме' ) : '—',
			);

			if ( $over || isset( $assoc_args['queries'] ) ) {
				foreach ( $result['queries'] as $query ) {
					\WP_CLI::log( sprintf( '  %s: %s мс, %s — %s', $target['label'], $query['ms'], $query['caller'], $query['sql'] ) );
				}
			}

			$failed = $failed || $over;
		}

		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}

		if ( $errors > 0 ) {
			\WP_CLI::error( sprintf( 'Не удалось замерить страниц: %d. Причины — выше.', $errors ) );
		}

		if ( $failed ) {
			\WP_CLI::error( sprintf( 'Предел — %d запроса плагина на страницу записи или рубрики. Список запросов — выше.', QueryLog::LIMIT ) );
		}

		\WP_CLI::success( sprintf( 'Плагин укладывается в предел: не больше %d запросов на страницу.', QueryLog::LIMIT ) );
	}

	/**
	 * Работа со своими редиректами.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: import — перенести правила из плагина Redirection (повторный перенос ничего не дублирует).
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot redirects import
	 *
	 * @param list<string> $args Позиционные аргументы.
	 */
	public function redirects( $args ): void {
		if ( 'import' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Неизвестное действие. Доступно: import.' );
		}

		\IndexPilot\Redirects\Schema::maybe_install( $this->plugin->db() );

		$adapter = $this->plugin->redirects()->adapter();

		if ( ! $adapter->available() ) {
			\WP_CLI::error( 'Таблицы плагина Redirection на сайте нет.' );
		}

		$store  = $this->plugin->redirect_store();
		$result = ( new \IndexPilot\Import\RedirectionImporter( $store, $this->plugin->redirect_manager() ) )->import( $adapter->rows() );

		\WP_CLI::success(
			sprintf(
				'Перенесено правил: %d. Уже были: %d. Не переносятся (условия, случайная цель): %d. Цепочек схлопнуто: %d. Петель выключено: %d.',
				$result['imported'],
				$result['duplicates'],
				$result['skipped'],
				$result['collapsed'],
				$result['loops']
			)
		);
	}

	/**
	 * Самопроверка сайта и проверка внешних адресов.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: run — проверить все адреса из карты сайта; outbound — собрать и проверить внешние ссылки, картинки и ролики.
	 *
	 * [--limit=<number>]
	 * : Для outbound: сколько адресов проверить. По умолчанию 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot health run
	 *     wp indexpilot health outbound --limit=300
	 *
	 * @param list<string>          $args       Позиционные аргументы.
	 * @param array<string, string> $assoc_args Именованные аргументы.
	 */
	public function health( $args, $assoc_args ): void {
		$health = $this->plugin->health();

		\IndexPilot\Health\WpStore::maybe_install( $this->plugin->db() );

		if ( 'run' === ( $args[0] ?? '' ) ) {
			$check = $health->self_check();
			$check->start( $health->sitemap_urls() );

			do {
				$result = $check->run_batch( 20 );
				\WP_CLI::log( sprintf( 'Проверено %d из %d.', $result['cursor'], $result['total'] ) );
				$this->free_memory();
			} while ( ! $result['done'] );

			\WP_CLI::success( 'Самопроверка закончена. Отчёт — в админке: IndexPilot → Техника → Самопроверка.' );

			return;
		}

		if ( 'outbound' === ( $args[0] ?? '' ) ) {
			do {
				$collected = $health->collect_batch( 200 );
				$this->free_memory();
			} while ( ! $collected['done'] );

			$checked = $health->outbound()->check_due( max( 1, (int) ( $assoc_args['limit'] ?? 100 ) ), time() );

			\WP_CLI::success( sprintf( 'Проверено внешних адресов: %d.', $checked ) );

			return;
		}

		\WP_CLI::error( 'Неизвестное действие. Доступно: run, outbound.' );
	}

	/**
	 * Создаёт короткую ссылку.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Действие: create.
	 *
	 * <target>
	 * : Полный адрес, куда ведёт ссылка.
	 *
	 * [--slug=<slug>]
	 * : Свой код ссылки. Без него выдаётся случайный.
	 *
	 * [--code=<code>]
	 * : Код перенаправления: 302 (по умолчанию), 301 или 307.
	 *
	 * [--campaign=<name>]
	 * : Название кампании.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexpilot shortlink create https://www.twitch.tv/manacost --slug=twitch
	 *
	 * @param list<string>          $args       Позиционные аргументы.
	 * @param array<string, string> $assoc_args Именованные аргументы.
	 */
	public function shortlink( $args, $assoc_args ): void {
		if ( 'create' !== ( $args[0] ?? '' ) || '' === (string) ( $args[1] ?? '' ) ) {
			\WP_CLI::error( 'Использование: wp indexpilot shortlink create <адрес> [--slug=<код>] [--code=302] [--campaign=<название>].' );
		}

		if ( ! $this->plugin->settings()->general( 'shortlinks_enabled' ) ) {
			\WP_CLI::error( 'Короткие ссылки выключены в настройках.' );
		}

		( new \IndexPilot\ShortLinks\WpStore( $this->plugin->db() ) )->maybe_install();

		$result = $this->plugin->shortlinks()->create( (string) $args[1], (string) ( $assoc_args['slug'] ?? '' ), (int) ( $assoc_args['code'] ?? 302 ), array(), (string) ( $assoc_args['campaign'] ?? '' ) );

		if ( is_string( $result ) ) {
			\WP_CLI::error( $result );
		}

		\WP_CLI::success( $this->plugin->shortlink_router()->url( (string) $result['slug'] ) );
	}

	/**
	 * Освобождает память между пачками: без этого долгий проход копит записи в кэше объектов.
	 */
	private function free_memory(): void {
		$this->plugin->variables()->flush();

		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
