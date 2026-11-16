<?php
/**
 * Экран «Данные поиска»: подключение Google Search Console и Яндекс Вебмастера.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\SearchData\Module;
use IndexPilot\SearchData\SearchDataException;

/**
 * Загрузка файла ключа служебной учётной записи Google, ресурс в Search Console, токен Яндекс Вебмастера,
 * проверка подключения, загрузка данных и проверка индексации по кнопке. Ключ и токен на экран не выводятся:
 * видны только адрес служебной учётной записи и отметка «токен сохранён».
 */
final class SearchDataPage {

	public const SLUG = 'indexpilot-search-data';

	private const CAP   = 'manage_options';
	private const NONCE = 'indexpilot_search_data';

	/**
	 * Сообщения после действия: текст и успех.
	 *
	 * @var list<array{0: string, 1: bool}>
	 */
	private array $notices = array();

	public function __construct( private Module $module ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 13 );
	}

	/**
	 * Добавляет страницу.
	 */
	public function add(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — данные поиска', 'indexpilot' ),
			__( 'Данные поиска', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Обрабатывает формы.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_search_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action      = sanitize_key( wp_unslash( $_POST['indexpilot_search_action'] ) );
		$credentials = $this->module->credentials();

		if ( 'save' === $action ) {
			$file = isset( $_FILES['indexpilot_google_key']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['indexpilot_google_key']['tmp_name'] ) : '';

			if ( '' !== $file && is_uploaded_file( $file ) ) {
				try {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- временный файл загрузки на этом сервере.
					$email = $credentials->save_google_key( (string) file_get_contents( $file, false, null, 0, $credentials::MAX_KEY_SIZE + 1 ) );
					/* translators: %s: адрес служебной учётной записи. */
					$this->notices[] = array( sprintf( __( 'Файл ключа сохранён. Добавьте адрес %s пользователем в Search Console (права «Ограниченный» достаточно).', 'indexpilot' ), $email ), true );
				} catch ( SearchDataException $error ) {
					$this->notices[] = array( $error->getMessage(), false );
				}
			}

			if ( isset( $_POST['indexpilot_google_site'] ) ) {
				$credentials->set_google_site( sanitize_text_field( wp_unslash( $_POST['indexpilot_google_site'] ) ) );
			}

			$token = isset( $_POST['indexpilot_yandex_token'] ) ? sanitize_text_field( wp_unslash( $_POST['indexpilot_yandex_token'] ) ) : '';

			if ( '' !== $token ) {
				$credentials->set_yandex_token( $token );
				$this->notices[] = array( __( 'Токен Яндекс Вебмастера сохранён.', 'indexpilot' ), true );
			}

			$this->notices[] = array( __( 'Настройки сохранены.', 'indexpilot' ), true );

			return;
		}

		if ( 'remove_google' === $action ) {
			$credentials->remove_google_key();
			$this->notices[] = array( __( 'Файл ключа Google удалён.', 'indexpilot' ), true );
		}

		if ( 'remove_yandex' === $action ) {
			$credentials->set_yandex_token( '' );
			$this->notices[] = array( __( 'Токен Яндекс Вебмастера удалён.', 'indexpilot' ), true );
		}

		if ( 'check' === $action ) {
			$result = $this->module->check();

			if ( array() === $result ) {
				$this->notices[] = array( __( 'Сначала загрузите файл ключа Google или впишите токен Яндекс Вебмастера.', 'indexpilot' ), false );
			}

			foreach ( $result as $item ) {
				$this->notices[] = array( $item['message'], $item['ok'] );
			}
		}

		if ( 'sync' === $action ) {
			$state = $this->module->start_sync( 10 );

			if ( array() === $state ) {
				$this->notices[] = array( __( 'Поисковики не подключены: загружать нечего.', 'indexpilot' ), false );
				return;
			}

			/* translators: %d: число строк. */
			$this->notices[] = array( sprintf( __( 'Данные загружены: строк %d.', 'indexpilot' ), (int) $state['rows'] ), true );

			foreach ( (array) $state['errors'] as $message ) {
				$this->notices[] = array( (string) $message, false );
			}
		}

		if ( 'inspect' === $action ) {
			$result = $this->module->inspect_batch( 10 );
			/* translators: %d: число страниц. */
			$this->notices[] = array( sprintf( __( 'Проверено страниц в индексе Google: %d. Остальные проверятся в фоне, по 40 в сутки.', 'indexpilot' ), $result['checked'] ), '' === $result['error'] );

			if ( '' !== $result['error'] ) {
				$this->notices[] = array( $result['error'], false );
			}
		}
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$credentials = $this->module->credentials();
		$state       = $this->module->state();

		echo '<div class="wrap" data-indexpilot-search-data>';
		echo '<h1>' . esc_html__( 'IndexPilot — данные поиска', 'indexpilot' ) . '</h1>';

		foreach ( $this->notices as $notice ) {
			printf( '<div class="notice notice-%1$s" data-indexpilot-notice="%1$s"><p>%2$s</p></div>', $notice[1] ? 'success' : 'error', esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'Плагин раз в сутки берёт из Google Search Console и Яндекс Вебмастера запросы, по которым показывают страницы сайта, — за последние 28 дней и за предыдущие 28. Они появляются на вкладке «Запросы» в панели записи, в форме рубрики и в отчётах «Запросы», «Карта тем» и «Без жизни». На страницах сайта эти данные не читаются и сайт не замедляют.', 'indexpilot' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="indexpilot_search_action" value="save">';
		echo '<h2>' . esc_html__( 'Google Search Console', 'indexpilot' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="indexpilot_google_key">' . esc_html__( 'Файл ключа', 'indexpilot' ) . '</label> ';
		HelpTip::render(
			__( 'JSON-файл ключа служебной учётной записи: Google Cloud → IAM → Сервисные аккаунты → Ключи → Добавить ключ → JSON. Затем адрес учётной записи (…@….iam.gserviceaccount.com) добавьте пользователем в Search Console обоих сайтов.', 'indexpilot' ),
			__( 'по ключу плагин сам, без вашего входа, забирает отчёт «Эффективность» и проверяет индексацию важных страниц. Ключ хранится в базе и на экран не выводится.', 'indexpilot' )
		);
		echo '</th><td>';

		if ( $credentials->has_google() ) {
			/* translators: %s: адрес служебной учётной записи. */
			echo '<p data-indexpilot-google-email>' . esc_html( sprintf( __( 'Ключ загружен. Служебная учётная запись: %s', 'indexpilot' ), $credentials->google_email() ) ) . '</p>';
			echo '<p><label>' . esc_html__( 'Заменить файлом:', 'indexpilot' ) . ' <input type="file" id="indexpilot_google_key" name="indexpilot_google_key" accept=".json,application/json"></label></p>';
		} else {
			echo '<input type="file" id="indexpilot_google_key" name="indexpilot_google_key" accept=".json,application/json">';
		}

		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="indexpilot_google_site">' . esc_html__( 'Ресурс в Search Console', 'indexpilot' ) . '</label></th><td>';
		printf( '<input type="text" class="regular-text" id="indexpilot_google_site" name="indexpilot_google_site" value="%s">', esc_attr( $credentials->google_site() ) );
		/* translators: %s: пример ресурса-домена. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Адрес сайта, как он записан в Search Console, со слэшем в конце. Для ресурса-домена — %s', 'indexpilot' ), 'sc-domain:' . wp_parse_url( home_url(), PHP_URL_HOST ) ) ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'Яндекс Вебмастер', 'indexpilot' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="indexpilot_yandex_token">' . esc_html__( 'Токен', 'indexpilot' ) . '</label> ';
		HelpTip::render(
			__( 'OAuth-токен владельца сайта в Вебмастере: создайте приложение на oauth.yandex.ru с правом «Яндекс.Вебмастер: получение информации», откройте ссылку получения токена и скопируйте его сюда.', 'indexpilot' ),
			__( 'по токену плагин забирает популярные запросы сайта в Яндексе. Токен хранится в базе и на экран не выводится.', 'indexpilot' )
		);
		echo '</th><td>';
		printf(
			'<input type="password" class="regular-text" id="indexpilot_yandex_token" name="indexpilot_yandex_token" value="" autocomplete="new-password" placeholder="%s">',
			esc_attr( $credentials->has_yandex() ? __( 'токен сохранён — впишите новый, чтобы заменить', 'indexpilot' ) : '' )
		);
		echo '</td></tr></table>';
		submit_button( __( 'Сохранить', 'indexpilot' ) );
		echo '</form>';

		echo '<div>';
		$this->button( 'check', __( 'Проверить подключение', 'indexpilot' ) );
		$this->button( 'sync', __( 'Обновить данные сейчас', 'indexpilot' ) );
		$this->button( 'inspect', __( 'Проверить индексацию важных страниц', 'indexpilot' ) );

		if ( $credentials->has_google() ) {
			$this->button( 'remove_google', __( 'Удалить ключ Google', 'indexpilot' ) );
		}

		if ( $credentials->has_yandex() ) {
			$this->button( 'remove_yandex', __( 'Удалить токен Яндекса', 'indexpilot' ) );
		}

		echo '</div>';

		echo '<h2>' . esc_html__( 'Загрузка', 'indexpilot' ) . '</h2>';

		if ( array() === $state ) {
			echo '<p>' . esc_html__( 'Данные ещё не загружались.', 'indexpilot' ) . '</p>';
		} else {
			echo '<p data-indexpilot-sync-state>';
			echo esc_html(
				empty( $state['done'] )
					/* translators: 1: дата начала, 2: шагов сделано, 3: всего шагов. */
					? sprintf( __( 'Загрузка начата %1$s, сделано шагов %2$d из %3$d — продолжается в фоне.', 'indexpilot' ), wp_date( 'd.m.Y H:i', (int) $state['started'] ), (int) $state['step'], (int) $state['total'] )
					/* translators: 1: дата, 2: число строк. */
					: sprintf( __( 'Последняя загрузка закончена %1$s. Строк в таблице: %2$s.', 'indexpilot' ), wp_date( 'd.m.Y H:i', (int) $state['finished'] ), number_format_i18n( $this->module->store()->count() ) )
			);
			echo '</p>';

			foreach ( (array) ( $state['errors'] ?? array() ) as $engine => $message ) {
				printf( '<p class="notice notice-error inline" data-indexpilot-sync-error="%1$s">%2$s</p>', esc_attr( (string) $engine ), esc_html( (string) $message ) );
			}
		}

		echo '</div>';
	}

	/**
	 * Кнопка действия.
	 *
	 * @param string $action Действие.
	 * @param string $label  Надпись.
	 */
	private function button( string $action, string $label ): void {
		echo '<form method="post" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="indexpilot_search_action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}
}
