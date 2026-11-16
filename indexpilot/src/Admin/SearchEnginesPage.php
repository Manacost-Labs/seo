<?php
/**
 * Страница «Поисковики».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Frontend\Verification;
use IndexPilot\IndexNow\Notifier;
use IndexPilot\Plugin;
use IndexPilot\SearchEngines\Links;
use IndexPilot\Settings\Settings;
use IndexPilot\Sitemap\Router;

/**
 * Всё для работы с Google, Яндексом и Bing в одном месте: подтверждён ли сайт, прямые ссылки в кабинеты,
 * адреса карты сайта и robots.txt, состояние IndexNow и отправка адресов вручную.
 * У каждой записи в списке — ссылки «проверить в Google» и «проверить в Яндексе».
 */
final class SearchEnginesPage {

	public const SLUG = 'indexpilot-search';

	/**
	 * Конструктор.
	 *
	 * @param Settings $settings Настройки.
	 * @param Notifier $notifier IndexNow: очередь, журнал, ключ.
	 */
	public function __construct( private Settings $settings, private Notifier $notifier ) {
	}

	/**
	 * Подключает страницу и ссылки в списке записей.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 20, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 20, 2 );
	}

	/**
	 * Добавляет пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — поисковики', 'indexpilot' ),
			__( 'Поисковики', 'indexpilot' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Подключает скрипт страницы.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( ! str_ends_with( (string) $hook, '_page_' . self::SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'indexpilot-search', plugins_url( 'assets/js/search.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
		wp_localize_script(
			'indexpilot-search',
			'IndexPilotSearch',
			array(
				'working'  => __( 'Отправка…', 'indexpilot' ),
				/* translators: 1: отправлено адресов, 2: код ответа поисковика. */
				'sent'     => __( 'Отправлено адресов: %1$d. Ответ: %2$d.', 'indexpilot' ),
				/* translators: 1: обработано файлов карты, 2: всего файлов. */
				'progress' => __( 'Отправлено файлов карты сайта: %1$d из %2$d…', 'indexpilot' ),
				/* translators: %d: код ответа поисковика. */
				'refused'  => __( 'Поисковик не принял запрос. Ответ: %d. Проверьте, открывается ли файл ключа.', 'indexpilot' ),
				'nothing'  => __( 'Нет адресов этого сайта для отправки.', 'indexpilot' ),
				'failed'   => __( 'Запрос не выполнен.', 'indexpilot' ),
				'copied'   => __( 'Скопировано', 'indexpilot' ),
				'confirm'  => __( 'Сообщить поисковикам обо всех страницах сайта из карты сайта?', 'indexpilot' ),
			)
		);
	}

	/**
	 * Добавляет к записи в списке ссылки проверки в поиске.
	 *
	 * @param array<string, string> $actions Действия строки.
	 * @param \WP_Post              $post    Запись.
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ) {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return $actions;
		}

		$links = Links::for_url( home_url( '/' ), (string) get_permalink( $post ) );

		$actions['indexpilot_google'] = sprintf( '<a href="%s" target="_blank" rel="noopener" aria-label="%s">Google</a>', esc_url( $links['google_index'] ), esc_attr__( 'Проверить, есть ли страница в Google', 'indexpilot' ) );
		$actions['indexpilot_yandex'] = sprintf( '<a href="%s" target="_blank" rel="noopener" aria-label="%s">%s</a>', esc_url( $links['yandex_index'] ), esc_attr__( 'Проверить, есть ли страница в Яндексе', 'indexpilot' ), esc_html__( 'Яндекс', 'indexpilot' ) );

		return $actions;
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$links    = Links::for_site( home_url( '/' ) );
		$settings = admin_url( 'admin.php?page=' . SettingsPage::SLUG );
		$sitemap  = home_url( '/' . Router::INDEX );
		$engines  = array(
			'google' => array( 'Google', 'verify_google', __( 'Google не поддерживает IndexNow: о новых страницах он узнаёт из карты сайта. Добавьте её один раз в Search Console.', 'indexpilot' ) ),
			'yandex' => array( __( 'Яндекс', 'indexpilot' ), 'verify_yandex', __( 'Яндекс принимает IndexNow и карту сайта. Срочно переобойти отдельные страницы можно в разделе «Переобход страниц».', 'indexpilot' ) ),
			'bing'   => array( 'Bing', 'verify_bing', __( 'Bing принимает IndexNow и карту сайта. Его данными пользуются и другие поисковики и AI-ассистенты.', 'indexpilot' ) ),
		);
		?>
		<div class="wrap" data-indexpilot-search>
			<h1><?php esc_html_e( 'IndexPilot — поисковики', 'indexpilot' ); ?></h1>

			<h2><?php esc_html_e( 'Адреса для поисковиков', 'indexpilot' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->address_row( __( 'Карта сайта', 'indexpilot' ), $sitemap );
				$this->address_row( 'robots.txt', home_url( '/robots.txt' ) );
				$this->address_row( 'llms.txt', home_url( '/llms.txt' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Кабинеты', 'indexpilot' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Поисковик', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Подтверждение сайта', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Перейти', 'indexpilot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Что важно знать', 'indexpilot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $engines as $key => $engine ) : ?>
						<?php $verified = '' !== Verification::code( (string) $this->settings->general( $engine[1] ) ); ?>
						<tr data-indexpilot-engine="<?php echo esc_attr( $key ); ?>">
							<td><strong><?php echo esc_html( $engine[0] ); ?></strong></td>
							<td>
								<?php if ( $verified ) : ?>
									<?php esc_html_e( 'Код задан: тег выводится на главной странице.', 'indexpilot' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Код не задан.', 'indexpilot' ); ?>
									<a href="<?php echo esc_url( $links[ $key ]['add'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Получить код', 'indexpilot' ); ?></a>,
									<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'вставить в настройках', 'indexpilot' ); ?></a>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $links[ $key ]['cabinet'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Кабинет', 'indexpilot' ); ?></a> ·
								<a href="<?php echo esc_url( $links[ $key ]['sitemaps'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Карты сайта', 'indexpilot' ); ?></a>
								<?php if ( isset( $links[ $key ]['recrawl'] ) ) : ?>
									· <a href="<?php echo esc_url( $links[ $key ]['recrawl'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Переобход страниц', 'indexpilot' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $engine[2] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Проверить отдельную страницу можно из списка записей: под названием записи есть ссылки «Google» и «Яндекс».', 'indexpilot' ); ?></p>

			<h2>IndexNow</h2>
			<?php $this->render_indexnow( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Блок IndexNow: состояние, кнопки и журнал.
	 *
	 * @param string $settings_url Адрес страницы настроек.
	 */
	private function render_indexnow( string $settings_url ): void {
		$enabled = $this->notifier->enabled();
		$key     = $this->notifier->key()->value();
		$queue   = $this->notifier->queue()->count();
		$log     = $this->notifier->log()->entries();
		?>
		<p><?php esc_html_e( 'IndexNow сразу сообщает Яндексу и Bing об опубликованных, изменённых и удалённых страницах — не нужно ждать, пока робот сам их найдёт.', 'indexpilot' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Состояние', 'indexpilot' ); ?></th>
				<td data-indexpilot-indexnow-state="<?php echo esc_attr( $enabled ? 'on' : 'off' ); ?>">
					<?php if ( $enabled ) : ?>
						<strong><?php esc_html_e( 'Включён', 'indexpilot' ); ?></strong>
					<?php else : ?>
						<strong><?php esc_html_e( 'Выключен', 'indexpilot' ); ?></strong> —
						<?php esc_html_e( 'ничего не отправляется. Включайте только на боевом сайте:', 'indexpilot' ); ?>
						<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'настройки', 'indexpilot' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<?php $this->address_row( __( 'Файл ключа', 'indexpilot' ), home_url( '/' . $key . '.txt' ) ); ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ждут отправки', 'indexpilot' ); ?></th>
				<td>
					<span data-indexpilot-queue><?php echo esc_html( number_format_i18n( $queue ) ); ?></span>
					<button type="button" class="button" data-indexpilot-indexnow="send_queue" <?php disabled( ! $enabled || 0 === $queue ); ?>><?php esc_html_e( 'Отправить сейчас', 'indexpilot' ); ?></button>
					<p class="description"><?php esc_html_e( 'Обычно очередь уходит сама в течение минуты после сохранения записи.', 'indexpilot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="indexpilot-indexnow-urls"><?php esc_html_e( 'Отправить адреса', 'indexpilot' ); ?></label></th>
				<td>
					<textarea id="indexpilot-indexnow-urls" class="large-text code" rows="4" placeholder="<?php echo esc_attr( home_url( '/primer-stranicy/' ) ); ?>" data-indexpilot-urls <?php disabled( ! $enabled ); ?>></textarea>
					<p class="description"><?php esc_html_e( 'Адреса страниц этого сайта, по одному в строке.', 'indexpilot' ); ?></p>
					<p>
						<button type="button" class="button" data-indexpilot-indexnow="submit" <?php disabled( ! $enabled ); ?>><?php esc_html_e( 'Отправить эти адреса', 'indexpilot' ); ?></button>
						<button type="button" class="button" data-indexpilot-indexnow="submit_all" <?php disabled( ! $enabled ); ?>><?php esc_html_e( 'Отправить все страницы сайта', 'indexpilot' ); ?></button>
						<strong data-indexpilot-indexnow-status aria-live="polite"></strong>
					</p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Последние отправки', 'indexpilot' ); ?></h3>
		<?php
		if ( array() === $log ) {
			echo '<p>' . esc_html__( 'Отправок ещё не было.', 'indexpilot' ) . '</p>';

			return;
		}
		?>
		<table class="widefat striped" style="max-width:900px" data-indexpilot-log>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Когда', 'indexpilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Адресов', 'indexpilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Результат', 'indexpilot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Первый адрес', 'indexpilot' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd.m.Y H:i', $entry['time'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $entry['count'] ) ); ?></td>
						<td>
							<?php
							if ( $entry['ok'] ) {
								/* translators: %d: код ответа. */
								echo esc_html( sprintf( __( 'Принято (%d)', 'indexpilot' ), $entry['code'] ) );
							} elseif ( 0 === $entry['code'] ) {
								esc_html_e( 'Нет ответа', 'indexpilot' );
							} else {
								/* translators: %d: код ответа. */
								echo esc_html( sprintf( __( 'Отказ (%d)', 'indexpilot' ), $entry['code'] ) );
							}
							?>
						</td>
						<td><?php echo esc_html( $entry['sample'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Строка с адресом: ссылка и кнопка копирования.
	 *
	 * @param string $label Подпись.
	 * @param string $url   Адрес.
	 */
	private function address_row( string $label, string $url ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $url ); ?></code></a>
				<button type="button" class="button-link" data-indexpilot-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'скопировать', 'indexpilot' ); ?></button>
			</td>
		</tr>
		<?php
	}
}
