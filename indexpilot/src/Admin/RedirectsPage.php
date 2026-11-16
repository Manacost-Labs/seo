<?php
/**
 * Экран управления своими редиректами.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Import\RedirectionImporter;
use IndexPilot\Plugin;
use IndexPilot\Redirects\Csv;
use IndexPilot\Redirects\Manager;
use IndexPilot\Redirects\Matcher;
use IndexPilot\Redirects\RedirectService;
use IndexPilot\Redirects\WpNotFoundStore;
use IndexPilot\Redirects\WpStore;

/**
 * Поиск, добавление, правка, выключение и удаление правил; перенос из Redirection; выгрузка и загрузка CSV.
 */
final class RedirectsPage {

	public const SLUG = 'indexpilot-redirect-rules';

	private const CAP      = 'manage_options';
	private const NONCE    = 'indexpilot_redirect_rules';
	private const PER_PAGE = 100;

	/**
	 * Сообщение после действия: текст и вид (success, error, warning).
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private ?array $notice = null;

	/**
	 * Введённые данные формы, если сохранить не удалось.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $draft = null;

	/**
	 * Создаёт менеджер редиректов.
	 *
	 * @var callable(): Manager
	 */
	private $manager;

	/**
	 * @param WpStore             $store   Правила.
	 * @param callable(): Manager $manager Создаёт менеджер редиректов.
	 * @param RedirectService     $service Редиректы сайта (для сверки с Redirection).
	 * @param WpNotFoundStore     $log     Журнал 404.
	 */
	public function __construct( private WpStore $store, callable $manager, private RedirectService $service, private WpNotFoundStore $log ) {
		$this->manager = $manager;
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет страницу в меню.
	 */
	public function add(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — редиректы', 'indexpilot' ),
			__( 'Редиректы', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Стили значков подсказки.
	 *
	 * @param string $hook Текущий экран.
	 */
	public function enqueue( $hook ): void {
		if ( str_ends_with( (string) $hook, '_page_' . self::SLUG ) ) {
			wp_enqueue_style( 'indexpilot-panel', plugins_url( 'assets/css/panel.css', INDEXPILOT_FILE ), array(), Plugin::VERSION );
		}
	}

	/**
	 * Выполняет действия формы до вывода страницы.
	 */
	public function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- одноразовый ключ проверяется ниже.
		if ( isset( $_GET['export'] ) ) {
			check_admin_referer( self::NONCE . '_export' );
			$this->export();
		}

		if ( ! isset( $_POST['indexpilot_redirect_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['indexpilot_redirect_action'] ) );
		$id     = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;

		switch ( $action ) {
			case 'save':
				$this->save( $id );
				break;
			case 'toggle':
				$rule = $this->store->find( $id );
				if ( null !== $rule ) {
					( $this->manager )()->set_enabled( $id, ! $rule['enabled'] );
					$this->notice = array( $rule['enabled'] ? __( 'Правило выключено.', 'indexpilot' ) : __( 'Правило включено.', 'indexpilot' ), 'success' );
				}
				break;
			case 'delete':
				( $this->manager )()->delete( $id );
				$this->notice = array( __( 'Правило удалено.', 'indexpilot' ), 'success' );
				break;
			case 'import_redirection':
				$this->import_redirection();
				break;
			case 'import_csv':
				$this->import_csv();
				break;
		}
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- поиск и листание только читают данные.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable

		$total   = $this->store->count( $search );
		$rules   = $this->store->search( $search, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$foreign = $this->service->adapter()->available() ? $this->service->foreign_keys() : array();

		echo '<div class="wrap" data-indexpilot-redirect-rules>';
		echo '<h1>' . esc_html__( 'IndexPilot — редиректы', 'indexpilot' ) . '</h1>';

		if ( null !== $this->notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $this->notice[1] ), esc_html( $this->notice[0] ) );
		}

		echo '<p>' . esc_html__( 'Редирект переводит посетителя и поисковик со старого адреса на новый. Правила срабатывают раньше плагина Redirection; цепочки не сохраняются — цель сразу заменяется конечным адресом.', 'indexpilot' ) . '</p>';

		$this->form();
		$this->import_box( $foreign );

		echo '<h2>' . esc_html( sprintf( /* translators: %d: число правил. */ __( 'Правила (%d)', 'indexpilot' ), $total ) ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<p class="search-box"><label for="indexpilot-redirect-search">' . esc_html__( 'Найти правило', 'indexpilot' ) . '</label> ';
		echo '<input type="search" id="indexpilot-redirect-search" name="s" value="' . esc_attr( $search ) . '"> ';
		submit_button( __( 'Найти', 'indexpilot' ), 'secondary', '', false );
		echo '</p></form>';

		if ( array() === $rules ) {
			echo '<p>' . esc_html__( 'Правил пока нет.', 'indexpilot' ) . '</p></div>';

			return;
		}

		$this->table( $rules, $foreign );

		if ( $total > self::PER_PAGE ) {
			echo '<p>';
			$pages = (int) ceil( $total / self::PER_PAGE );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$url = add_query_arg(
					array(
						'page'  => self::SLUG,
						's'     => $search,
						'paged' => $page,
					),
					admin_url( 'admin.php' )
				);
				printf( $page === $paged ? '<strong>%2$d</strong> ' : '<a href="%1$s">%2$d</a> ', esc_url( $url ), (int) $page );
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Сохраняет правило из формы.
	 *
	 * @param int $id Номер правила при правке.
	 */
	private function save( int $id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- одноразовый ключ проверен в handle().
		$input    = array(
			'source'  => isset( $_POST['source'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['source'] ) ) ) : '',
			'target'  => isset( $_POST['target'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : '',
			'code'    => isset( $_POST['code'] ) ? absint( wp_unslash( $_POST['code'] ) ) : 301,
			'regex'   => ! empty( $_POST['regex'] ),
			'enabled' => ! empty( $_POST['enabled'] ),
			'note'    => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
		);
		$from_404 = isset( $_POST['from_404'] ) ? sanitize_text_field( wp_unslash( $_POST['from_404'] ) ) : '';
		// phpcs:enable

		$result = ( $this->manager )()->save( $input, $id );

		if ( is_string( $result ) ) {
			$this->draft  = $input + array( 'id' => $id );
			$this->notice = array( self::error_text( $result ), 'error' );

			return;
		}

		// Адрес из журнала 404 исправлен — строка журнала больше не нужна.
		if ( '' !== $from_404 ) {
			$this->log->delete_url( $from_404 );
		}

		/* translators: 1: исходный адрес, 2: цель или код ответа. */
		$this->notice = array( sprintf( __( 'Сохранено: %1$s → %2$s', 'indexpilot' ), $result['source'], '' !== $result['target'] ? $result['target'] : (string) $result['code'] ), 'success' );
	}

	/**
	 * Переносит правила из плагина Redirection.
	 */
	private function import_redirection(): void {
		$result = ( new RedirectionImporter( $this->store, ( $this->manager )() ) )->import( $this->service->adapter()->rows() );

		$this->notice = array(
			sprintf(
				/* translators: 1: перенесено, 2: уже были, 3: не переносятся, 4: цепочек схлопнуто, 5: петель выключено. */
				__( 'Перенесено правил: %1$d. Уже были: %2$d. Не переносятся (условия, случайная цель): %3$d. Цепочек схлопнуто: %4$d. Петель выключено: %5$d.', 'indexpilot' ),
				$result['imported'],
				$result['duplicates'],
				$result['skipped'],
				$result['collapsed'],
				$result['loops']
			),
			'success',
		);
	}

	/**
	 * Загружает правила из файла CSV.
	 */
	private function import_csv(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- одноразовый ключ проверен в handle(); путь к загруженному файлу выдаёт PHP.
		$file = isset( $_FILES['csv']['tmp_name'] ) ? (string) $_FILES['csv']['tmp_name'] : '';

		if ( '' === $file || ! is_uploaded_file( $file ) ) {
			$this->notice = array( __( 'Выберите файл CSV.', 'indexpilot' ), 'error' );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- чтение загруженного файла.
		$rows    = Csv::parse( (string) file_get_contents( $file, false, null, 0, 5 * MB_IN_BYTES ) );
		$manager = ( $this->manager )();
		$saved   = 0;
		$errors  = 0;

		foreach ( $rows as $row ) {
			$existing = $row['regex'] ? null : $this->store->find_by_source( Matcher::key( $row['source'] ) );
			$result   = $manager->save( $row, null !== $existing ? (int) $existing['id'] : 0 );

			if ( is_array( $result ) ) {
				++$saved;
			} else {
				++$errors;
			}
		}

		/* translators: 1: сохранено правил, 2: с ошибками. */
		$this->notice = array( sprintf( __( 'Загружено правил: %1$d. С ошибками (пропущены): %2$d.', 'indexpilot' ), $saved, $errors ), $errors > 0 ? 'warning' : 'success' );
	}

	/**
	 * Отдаёт все правила файлом CSV.
	 */
	private function export(): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="indexpilot-redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- это файл CSV, а не HTML.
		echo "\xEF\xBB\xBF" . Csv::build( $this->store->all() );
		exit;
	}

	/**
	 * Форма добавления и правки.
	 */
	private function form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- данные только подставляются в форму.
		$edit     = isset( $_GET['edit'] ) ? $this->store->find( absint( wp_unslash( $_GET['edit'] ) ) ) : null;
		$rule     = $this->draft ?? $edit ?? array(
			'id'      => 0,
			'source'  => isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '',
			'target'  => isset( $_GET['target'] ) ? esc_url_raw( wp_unslash( $_GET['target'] ) ) : '',
			'code'    => 301,
			'regex'   => false,
			'enabled' => true,
			'note'    => '',
		);
		$from_404 = isset( $_GET['from_404'] ) ? sanitize_text_field( wp_unslash( $_GET['from_404'] ) ) : '';
		// phpcs:enable

		$codes = array(
			301 => __( '301 — переехала навсегда (обычный выбор)', 'indexpilot' ),
			302 => __( '302 — временно в другом месте', 'indexpilot' ),
			307 => __( '307 — временно, с сохранением метода запроса', 'indexpilot' ),
			410 => __( '410 — удалена навсегда, без перехода', 'indexpilot' ),
			451 => __( '451 — недоступна по закону, без перехода', 'indexpilot' ),
		);

		echo '<h2 id="new">' . esc_html( (int) $rule['id'] > 0 ? __( 'Правка правила', 'indexpilot' ) : __( 'Новое правило', 'indexpilot' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="indexpilot_redirect_action" value="save"><input type="hidden" name="rule_id" value="' . esc_attr( (string) (int) $rule['id'] ) . '">';
		echo '<input type="hidden" name="from_404" value="' . esc_attr( $from_404 ) . '">';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="indexpilot-redirect-source">' . esc_html__( 'Старый адрес', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'адрес, с которого нужно уводить, например /staryj-gajd/. Регистр, слэш в конце и метки utm не важны.', 'indexpilot' ), __( 'по старому адресу приходят люди из закладок, соцсетей и поиска — без редиректа они увидят «не найдено».', 'indexpilot' ) );
		echo '</th><td><input type="text" class="large-text code" id="indexpilot-redirect-source" name="source" required value="' . esc_attr( (string) $rule['source'] ) . '">';
		echo '<p><label><input type="checkbox" name="regex" value="1"' . checked( ! empty( $rule['regex'] ), true, false ) . '> ' . esc_html__( 'Это шаблон (регулярное выражение)', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'отметьте, если старых адресов много и они похожи. Пример: ^/old/(.*)$ и цель /new/$1 — часть адреса в скобках переносится в цель.', 'indexpilot' ), __( 'одно правило заменяет сотню одинаковых. Если не уверены — не отмечайте.', 'indexpilot' ) );
		echo '</p></td></tr>';

		echo '<tr><th scope="row"><label for="indexpilot-redirect-target">' . esc_html__( 'Куда вести', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'новый адрес: путь на сайте (/novyj-gajd/) или полный адрес другого сайта. Для ответов 410 и 451 оставьте пустым.', 'indexpilot' ), __( 'посетитель сразу попадёт на нужную страницу, а поисковик перенесёт на неё вес старой.', 'indexpilot' ) );
		echo '</th><td><input type="text" class="large-text code" id="indexpilot-redirect-target" name="target" value="' . esc_attr( (string) $rule['target'] ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="indexpilot-redirect-code">' . esc_html__( 'Ответ', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'почти всегда 301. 410 — страницы больше нет и не будет: перехода нет, поисковик быстрее уберёт адрес из поиска.', 'indexpilot' ), __( 'код подсказывает поисковику, переехала страница навсегда, временно или удалена.', 'indexpilot' ) );
		echo '</th><td><select id="indexpilot-redirect-code" name="code">';
		foreach ( $codes as $code => $label ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $code, selected( (int) $rule['code'], $code, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="indexpilot-redirect-note">' . esc_html__( 'Пометка', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'любое короткое слово для себя: «перенос 2025», «акция». Можно не заполнять.', 'indexpilot' ), __( 'по пометке правила легко найти поиском и понять, откуда они взялись.', 'indexpilot' ) );
		echo '</th><td><input type="text" class="regular-text" id="indexpilot-redirect-note" name="note" maxlength="100" value="' . esc_attr( (string) $rule['note'] ) . '"></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Включено', 'indexpilot' ) . ' ';
		HelpTip::render( __( 'снимите галочку, чтобы правило временно не работало, но осталось в списке.', 'indexpilot' ), __( 'удобно проверить, что будет без правила, не удаляя его.', 'indexpilot' ) );
		echo '</th><td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $rule['enabled'] ), true, false ) . '> ' . esc_html__( 'Правило работает', 'indexpilot' ) . '</label></td></tr>';
		echo '</table>';

		submit_button( (int) $rule['id'] > 0 ? __( 'Сохранить правило', 'indexpilot' ) : __( 'Добавить правило', 'indexpilot' ) );
		echo '</form>';
	}

	/**
	 * Перенос из Redirection, выгрузка и загрузка CSV.
	 *
	 * @param array<string, true> $foreign Ключи адресов правил Redirection.
	 */
	private function import_box( array $foreign ): void {
		echo '<h2>' . esc_html__( 'Перенос и файлы', 'indexpilot' ) . '</h2>';

		if ( $this->service->adapter()->available() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="indexpilot_redirect_action" value="import_redirection"><p>';
			/* translators: %d: число правил в Redirection. */
			echo esc_html( sprintf( __( 'В плагине Redirection правил: %d. Перенос копирует их сюда с теми же признаками (шаблон, выключено, код), цепочки схлопываются. Повторный перенос ничего не дублирует. Пока Redirection включён, свои правила срабатывают первыми — двойных перенаправлений не будет.', 'indexpilot' ), count( $this->service->adapter()->rows() ) ) );
			echo '</p>';
			submit_button( __( 'Перенести правила из Redirection', 'indexpilot' ), 'secondary', 'submit', false );
			echo '</form>';

			if ( array() !== $foreign ) {
				echo '<p class="description">' . esc_html__( 'Правила, у которых тот же адрес есть в Redirection, помечены в списке: срабатывает своё правило, правило Redirection можно выключить.', 'indexpilot' ) . '</p>';
			}
		}

		$export = wp_nonce_url( add_query_arg( 'export', '1', admin_url( 'admin.php?page=' . self::SLUG ) ), self::NONCE . '_export' );

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '"><p class="indexpilot-filter">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="indexpilot_redirect_action" value="import_csv">';
		echo '<a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Выгрузить в CSV', 'indexpilot' ) . '</a> ';
		echo '<label for="indexpilot-redirect-csv">' . esc_html__( 'Загрузить CSV:', 'indexpilot' ) . '</label> ';
		HelpTip::render( __( 'файл со столбцами source, target, code, regex, enabled, note — такой же, как при выгрузке. Правило с тем же адресом обновляется.', 'indexpilot' ), __( 'так переносят сразу много правил из таблицы или с другого сайта.', 'indexpilot' ) );
		echo ' <input type="file" id="indexpilot-redirect-csv" name="csv" accept=".csv,text/csv"> ';
		submit_button( __( 'Загрузить', 'indexpilot' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * Таблица правил.
	 *
	 * @param list<array<string, mixed>> $rules   Правила.
	 * @param array<string, true>        $foreign Ключи адресов правил Redirection.
	 */
	private function table( array $rules, array $foreign ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Старый адрес', 'indexpilot' ), __( 'Куда ведёт', 'indexpilot' ), __( 'Ответ', 'indexpilot' ), __( 'Срабатываний', 'indexpilot' ), __( 'Последнее', 'indexpilot' ), __( 'Пометка', 'indexpilot' ), __( 'Действия', 'indexpilot' ) ) as $title ) {
			echo '<th scope="col">' . esc_html( $title ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rules as $rule ) {
			$also = ! $rule['regex'] && isset( $foreign[ Matcher::key( (string) $rule['source'] ) ] );

			printf( '<tr data-indexpilot-rule="%1$d"%2$s><td><code>%3$s</code>', (int) $rule['id'], $rule['enabled'] ? '' : ' style="opacity:.6"', esc_html( (string) $rule['source'] ) );
			if ( $rule['regex'] ) {
				echo ' <span class="description">' . esc_html__( 'шаблон', 'indexpilot' ) . '</span>';
			}
			if ( ! $rule['enabled'] ) {
				echo ' <strong>' . esc_html__( 'выключено', 'indexpilot' ) . '</strong>';
			}
			if ( $also ) {
				echo '<br><span class="description" data-indexpilot-also-redirection>' . esc_html__( 'такой адрес есть и в Redirection — срабатывает это правило', 'indexpilot' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( '' !== (string) $rule['target'] ? (string) $rule['target'] : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $rule['code'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $rule['hits'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $rule['last_hit'] ? get_date_from_gmt( (string) $rule['last_hit'], 'd.m.Y H:i' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $rule['note'] ) . '</td><td>';
			printf( '<a href="%1$s">%2$s</a> ', esc_url( add_query_arg( 'edit', (int) $rule['id'], admin_url( 'admin.php?page=' . self::SLUG ) ) . '#new' ), esc_html__( 'Изменить', 'indexpilot' ) );
			$this->row_button( (int) $rule['id'], 'toggle', $rule['enabled'] ? __( 'Выключить', 'indexpilot' ) : __( 'Включить', 'indexpilot' ), 'button-link' );
			$this->row_button( (int) $rule['id'], 'delete', __( 'Удалить', 'indexpilot' ), 'button-link button-link-delete' );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Кнопка действия в строке таблицы.
	 *
	 * @param int    $id     Номер правила.
	 * @param string $action Действие.
	 * @param string $label  Надпись.
	 * @param string $css    Классы кнопки.
	 */
	private function row_button( int $id, string $action, string $label, string $css ): void {
		echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">';
		wp_nonce_field( self::NONCE, '_wpnonce', true, true );
		printf(
			'<input type="hidden" name="indexpilot_redirect_action" value="%1$s"><input type="hidden" name="rule_id" value="%2$d"><button type="submit" class="%3$s">%4$s</button></form> ',
			esc_attr( $action ),
			(int) $id,
			esc_attr( $css ),
			esc_html( $label )
		);
	}

	/**
	 * Понятный текст ошибки менеджера.
	 *
	 * @param string $error Код ошибки.
	 */
	public static function error_text( string $error ): string {
		$texts = array(
			Manager::ERROR_SOURCE    => __( 'Старый адрес должен начинаться с «/» или быть адресом этого сайта.', 'indexpilot' ),
			Manager::ERROR_PATTERN   => __( 'Шаблон записан с ошибкой: проверьте скобки и спецсимволы.', 'indexpilot' ),
			Manager::ERROR_TARGET    => __( 'Цель должна начинаться с «/» или с http:// либо https://.', 'indexpilot' ),
			Manager::ERROR_CODE      => __( 'Ответ должен быть 301, 302, 307, 410 или 451.', 'indexpilot' ),
			Manager::ERROR_DUPLICATE => __( 'Для этого адреса уже есть правило — измените его.', 'indexpilot' ),
			Manager::ERROR_LOOP      => __( 'Правило замкнуло бы петлю: цель в итоге ведёт обратно на старый адрес.', 'indexpilot' ),
		);

		return $texts[ $error ] ?? $error;
	}
}
