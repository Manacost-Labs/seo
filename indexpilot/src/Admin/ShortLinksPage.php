<?php
/**
 * Страница «Короткие ссылки».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Plugin;
use IndexPilot\ShortLinks\Router;
use IndexPilot\ShortLinks\Service;
use IndexPilot\ShortLinks\Store;

/**
 * Свои короткие ссылки: создание, список со счётчиком переходов, удаление.
 * Формы — обычные запросы с nonce: страница работает и без JavaScript (скрипт добавляет только копирование адреса).
 */
final class ShortLinksPage {

	public const SLUG = 'indexpilot-shortlinks';

	private const NONCE    = 'indexpilot_shortlinks';
	private const CAP      = 'edit_others_posts';
	private const PER_PAGE = 100;

	/**
	 * Сообщение о результате последнего действия: текст и вид (success или error).
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private ?array $notice = null;

	/**
	 * Конструктор.
	 *
	 * @param Service $service Правила коротких ссылок.
	 * @param Store   $store   Хранилище ссылок.
	 * @param ?\IndexPilot\ShortLinks\ClickLog $click_log Журнал переходов.
	 * @param Router  $router  Адреса коротких ссылок.
	 */
	public function __construct( private Service $service, private Store $store, private Router $router, private ?\IndexPilot\ShortLinks\ClickLog $click_log = null, private ?\IndexPilot\Health\WpStore $health = null ) {
	}

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 16 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт меню и обрабатывает формы страницы до её вывода.
	 */
	public function add(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — короткие ссылки', 'indexpilot' ),
			__( 'Короткие ссылки', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Подключает скрипт копирования адреса на этой странице и в редакторе записи.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( str_ends_with( (string) $hook, '_page_' . self::SLUG ) || in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script( 'indexpilot-search', plugins_url( 'assets/js/search.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
			wp_localize_script( 'indexpilot-search', 'IndexPilotSearch', array( 'copied' => __( 'Скопировано', 'indexpilot' ) ) );
		}

		if ( str_ends_with( (string) $hook, '_page_' . self::SLUG ) ) {
			// Стили значков подсказки.
			wp_enqueue_style( 'indexpilot-panel', plugins_url( 'assets/css/panel.css', INDEXPILOT_FILE ), array(), Plugin::VERSION );
		}
	}

	/**
	 * Создаёт или удаляет ссылку по запросу формы.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_shortlink_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'indexpilot' ), 403 );
		}

		// Свои ссылки редактор меняет и удаляет сам, чужие — только администратор.
		$service = $this->service->acting_as( get_current_user_id(), current_user_can( 'manage_options' ) );

		if ( 'delete' === $_POST['indexpilot_shortlink_action'] ) {
			$result       = $service->delete( isset( $_POST['link_id'] ) ? absint( wp_unslash( $_POST['link_id'] ) ) : 0 );
			$this->notice = true === $result ? array( __( 'Ссылка удалена.', 'indexpilot' ), 'success' ) : array( $this->error_text( (string) $result ), 'error' );

			return;
		}

		if ( 'bulk' === $_POST['indexpilot_shortlink_action'] ) {
			$lines   = isset( $_POST['lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lines'] ) ) : '';
			$results = $service->create_many(
				$lines,
				isset( $_POST['code'] ) ? absint( wp_unslash( $_POST['code'] ) ) : 302,
				isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : ''
			);
			$created = array();
			$failed  = array();

			foreach ( $results as $item ) {
				if ( is_array( $item['result'] ) ) {
					$created[] = $this->router->url( (string) $item['result']['slug'] );
				} else {
					$failed[] = $item['line'] . ' — ' . $this->error_text( (string) $item['result'] );
				}
			}

			/* translators: 1: сколько создано, 2: список адресов, 3: ошибки. */
			$this->notice = array( sprintf( __( 'Создано ссылок: %1$d. %2$s %3$s', 'indexpilot' ), count( $created ), implode( ' ', $created ), array() === $failed ? '' : __( 'Не созданы:', 'indexpilot' ) . ' ' . implode( '; ', $failed ) ), array() === $failed ? 'success' : 'warning' );

			return;
		}

		$target = isset( $_POST['target'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['target'] ) ) ) : '';
		$slug   = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$code   = isset( $_POST['code'] ) ? absint( wp_unslash( $_POST['code'] ) ) : 302;
		if ( 'update' === $_POST['indexpilot_shortlink_action'] ) {
			$result       = $service->update_target( isset( $_POST['link_id'] ) ? absint( wp_unslash( $_POST['link_id'] ) ) : 0, $target );
			$this->notice = true === $result ? array( __( 'Адрес обновлён.', 'indexpilot' ), 'success' ) : array( is_string( $result ) ? $this->error_text( $result ) : __( 'Не удалось обновить адрес.', 'indexpilot' ), 'error' );
			return;
		}
		$utm = array();
		foreach ( array( 'source', 'medium', 'campaign' ) as $key ) {
			$utm[ $key ] = isset( $_POST[ 'utm_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'utm_' . $key ] ) ) : '';
		}
		$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';
		$expiry   = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '';
		$result   = $service->create( $target, $slug, $code, $utm, $campaign, $expiry );

		if ( is_string( $result ) ) {
			$this->notice = array( $this->error_text( $result ), 'error' );
		} else {
			/* translators: %s: короткий адрес. */
			$this->notice = array( sprintf( __( 'Ссылка создана: %s', 'indexpilot' ), $this->router->url( $result['slug'] ) ), 'success' );
		}
	}

	/**
	 * Печатает страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- фильтр только читает данные.
		$campaign = isset( $_GET['campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- поиск только читает данные.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$links  = $this->store->all( self::PER_PAGE, 0, '' === $campaign ? null : $campaign, '' === $search ? null : $search );
		$total  = $this->store->count();
		$dead   = null === $this->health ? array() : $this->health->dead_urls( array_map( 'strval', array_column( $links, 'target' ) ) );
		?>
		<div class="wrap" data-indexpilot-shortlinks>
			<h1><?php esc_html_e( 'IndexPilot — короткие ссылки', 'indexpilot' ); ?></h1>

			<?php if ( null !== $this->notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $this->notice[1] ); ?>"><p><?php echo esc_html( $this->notice[0] ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				/* translators: %s: пример короткого адреса записи. */
				echo esc_html( sprintf( __( 'У каждой опубликованной записи короткий адрес есть сразу, создавать его не нужно: он показан в панели IndexPilot в редакторе записи, вкладка «Дополнительно» (пример: %s). Здесь создаются короткие ссылки на любые другие адреса — стрим, видео, турнир, партнёрскую страницу — со счётчиком переходов.', 'indexpilot' ), $this->router->url( 'p1a' ) ) );
				?>
			</p>

			<h2><?php esc_html_e( 'Новая ссылка', 'indexpilot' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="indexpilot_shortlink_action" value="create">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="indexpilot-shortlink-target"><?php esc_html_e( 'Куда ведёт', 'indexpilot' ); ?></label></th>
						<td><input type="url" class="large-text" id="indexpilot-shortlink-target" name="target" required placeholder="https://www.twitch.tv/…"></td>
					</tr>
					<tr>
						<th scope="row"><label for="indexpilot-shortlink-slug"><?php esc_html_e( 'Код ссылки', 'indexpilot' ); ?></label></th>
						<td>
							<code><?php echo esc_html( $this->router->url( '' ) ); ?></code>
							<input type="text" class="regular-text" id="indexpilot-shortlink-slug" name="slug" pattern="[A-Za-z0-9][A-Za-z0-9_\-]{1,63}" placeholder="twitch">
							<p class="description"><?php esc_html_e( 'Латинские буквы, цифры, дефис. Пусто — будет выдан случайный код из шести символов.', 'indexpilot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="indexpilot-shortlink-code"><?php esc_html_e( 'Перенаправление', 'indexpilot' ); ?></label></th>
						<td>
							<select id="indexpilot-shortlink-code" name="code">
								<option value="302"><?php esc_html_e( '302 — временное: каждый переход засчитывается', 'indexpilot' ); ?></option>
								<option value="301"><?php esc_html_e( '301 — постоянное: браузер запоминает адрес, счётчик неполный; только администратор', 'indexpilot' ); ?></option>
								<option value="307"><?php esc_html_e( '307 — временное, с сохранением метода запроса', 'indexpilot' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<p><label for="ip-utm_source"><?php esc_html_e( 'UTM: источник', 'indexpilot' ); ?></label> <input id="ip-utm_source" name="utm_source" type="text"></p>
<p><label for="ip-utm_medium"><?php esc_html_e( 'UTM: канал', 'indexpilot' ); ?></label> <input id="ip-utm_medium" name="utm_medium" type="text"></p>
<p><label for="ip-utm_campaign"><?php esc_html_e( 'UTM: метка кампании', 'indexpilot' ); ?></label> <input id="ip-utm_campaign" name="utm_campaign" type="text"></p>
<p><label for="ip-campaign"><?php esc_html_e( 'Название кампании', 'indexpilot' ); ?></label> <input id="ip-campaign" name="campaign" type="text"></p>
<p><label for="ip-expires_at"><?php esc_html_e( 'Действует до (UTC)', 'indexpilot' ); ?></label> <input id="ip-expires_at" name="expires_at" type="date"></p>
		<?php $presets = apply_filters( 'indexpilot_shortlink_utm_presets', array() ); ?>
		<?php if ( is_array( $presets ) && array() !== $presets ) : ?>
<p><label for="ip-preset"><?php esc_html_e( 'Шаблон UTM', 'indexpilot' ); ?></label>
<select id="ip-preset"><option value=""><?php esc_html_e( 'Выберите шаблон', 'indexpilot' ); ?></option>
			<?php foreach ( $presets as $preset ) : ?>
<option data-source="<?php echo esc_attr( $preset['source'] ); ?>" data-medium="<?php echo esc_attr( $preset['medium'] ); ?>" data-campaign="<?php echo esc_attr( $preset['campaign'] ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
	<?php endforeach; ?></select></p>
<script>document.getElementById('ip-preset').addEventListener('change',function(){const p=this.selectedOptions[0];for(const k of ['source','medium','campaign']){document.getElementById('ip-utm_'+k).value=p.dataset[k]||'';}});</script>
		<?php endif; ?>
		<?php submit_button( __( 'Создать ссылку', 'indexpilot' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Много ссылок сразу', 'indexpilot' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="indexpilot_shortlink_action" value="bulk">
				<p>
					<label for="indexpilot-shortlink-lines"><?php esc_html_e( 'Адреса, по одному в строке', 'indexpilot' ); ?></label>
					<?php \IndexPilot\Admin\HelpTip::render( __( 'в каждой строке — полный адрес, а через пробел можно указать свой код. Пример: «https://www.twitch.tv/manacost twitch». Без кода выдаётся случайный.', 'indexpilot' ), __( 'так за один раз делаются ссылки для всей рассылки или всех площадок стрима.', 'indexpilot' ) ); ?>
				</p>
				<textarea id="indexpilot-shortlink-lines" name="lines" rows="5" class="large-text code" placeholder="https://www.twitch.tv/manacost twitch"></textarea>
				<p>
					<label for="indexpilot-shortlink-bulk-campaign"><?php esc_html_e( 'Название кампании', 'indexpilot' ); ?></label>
					<?php \IndexPilot\Admin\HelpTip::render( __( 'любое короткое название для всех ссылок пачки, например «стрим 20 ноября». Можно оставить пустым.', 'indexpilot' ), __( 'по кампании ссылки потом фильтруются в списке.', 'indexpilot' ) ); ?>
					<input id="indexpilot-shortlink-bulk-campaign" name="campaign" type="text">
					<input type="hidden" name="code" value="302">
				</p>
				<?php submit_button( __( 'Создать все', 'indexpilot' ), 'secondary' ); ?>
			</form>

			<h2>
				<?php
				/* translators: %d: число ссылок. */
				echo esc_html( sprintf( __( 'Ссылки (%d)', 'indexpilot' ), $total ) );
				?>
			</h2>
			<form method="get">
<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
<label for="ip-filter"><?php esc_html_e( 'Кампания', 'indexpilot' ); ?></label>
<select id="ip-filter" name="campaign"><option value=""><?php esc_html_e( 'Все кампании', 'indexpilot' ); ?></option>
		<?php foreach ( $this->store->campaigns() as $name ) : ?>
<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $campaign, $name ); ?>><?php echo esc_html( $name ); ?></option>
		<?php endforeach; ?></select>
<label for="ip-search"><?php esc_html_e( 'Поиск', 'indexpilot' ); ?></label>
<input id="ip-search" type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'код или адрес', 'indexpilot' ); ?>">
		<?php submit_button( __( 'Фильтровать', 'indexpilot' ), 'secondary', '', false ); ?>
</form>
		<?php $this->details(); ?>
		<?php if ( array() === $links ) : ?>
				<p><?php esc_html_e( 'Своих коротких ссылок пока нет.', 'indexpilot' ); ?></p>
					<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Короткий адрес', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Куда ведёт', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Код', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Переходов', 'indexpilot' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Последний переход', 'indexpilot' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Действия', 'indexpilot' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $links as $link ) : ?>
							<?php $url = $this->router->url( (string) $link['slug'] ); ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $url ); ?></code></a>
									<button type="button" class="button-link" data-indexpilot-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'скопировать', 'indexpilot' ); ?></button>
								</td>
								<td><?php echo esc_html( (string) $link['target'] ); ?>
								<?php if ( isset( $dead[ (string) $link['target'] ] ) ) : ?>
<br><strong class="indexpilot-dead-target" data-indexpilot-dead-target style="color:#b32d2e"><?php esc_html_e( 'Цель перестала открываться', 'indexpilot' ); ?></strong>
								<?php endif; ?>
<p><?php echo esc_html( (string) ( $link['campaign'] ?? '' ) ); ?></p>
							<?php if ( ! empty( $link['expires_at'] ) && $link['expires_at'] < gmdate( 'Y-m-d' ) ) : ?>
<strong><?php esc_html_e( 'Срок действия истёк', 'indexpilot' ); ?></strong>
							<?php endif; ?>
<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'page' => self::SLUG,
										'link' => $link['id'],
									),
									admin_url( 'admin.php' )
								)
							);
							?>
			"><?php esc_html_e( 'Подробнее / изменить адрес', 'indexpilot' ); ?></a></td>
								<td><?php echo esc_html( (string) $link['code'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $link['clicks'] ) ); ?></td>
								<td><?php echo esc_html( '' !== (string) $link['last_click'] ? get_date_from_gmt( (string) $link['last_click'], 'd.m.Y H:i' ) : '—' ); ?></td>
								<td>
									<form method="post">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="indexpilot_shortlink_action" value="delete">
										<input type="hidden" name="link_id" value="<?php echo esc_attr( (string) $link['id'] ); ?>">
										<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Удалить', 'indexpilot' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
						<?php if ( $total > self::PER_PAGE ) : ?>
					<p class="description">
							<?php
							/* translators: %d: число показанных ссылок. */
							echo esc_html( sprintf( __( 'Показаны последние %d ссылок.', 'indexpilot' ), self::PER_PAGE ) );
							?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Текст ошибки создания ссылки на языке интерфейса.
	 *
	 * @param string $error Текст ошибки от Service.
	 */
	private function error_text( string $error ): string {
		$known = array(
			'Неверная дата окончания.'           => __( 'Неверная дата окончания.', 'indexpilot' ),
			'Название кампании слишком длинное.' => __( 'Название кампании слишком длинное.', 'indexpilot' ),
			'Адрес должен быть полным и начинаться с http:// или https://.' => __( 'Адрес должен быть полным и начинаться с http:// или https://.', 'indexpilot' ),
			'Код перенаправления должен быть 301, 302 или 307.' => __( 'Код перенаправления должен быть 301, 302 или 307.', 'indexpilot' ),
			'Код ссылки: от 2 до 64 символов, латинские буквы, цифры, дефис и подчёркивание.' => __( 'Код ссылки: от 2 до 64 символов, латинские буквы, цифры, дефис и подчёркивание.', 'indexpilot' ),
			'Этот код уже занят.'                => __( 'Этот код уже занят.', 'indexpilot' ),
			'Ссылка не найдена.'                 => __( 'Ссылка не найдена.', 'indexpilot' ),
			'Чужую ссылку может изменить или удалить только администратор.' => __( 'Чужую ссылку может изменить или удалить только администратор.', 'indexpilot' ),
		);

		return $known[ $error ] ?? $error;
	}

	/**
	 * QR-код короткого адреса: картинка SVG строится здесь же, на сервере, без сторонних сервисов.
	 *
	 * @param string $url  Короткий адрес.
	 * @param string $slug Код ссылки: из него имя файла.
	 */
	private function qr( string $url, string $slug ): void {
		try {
			$svg = \IndexPilot\ShortLinks\QrCode::encode( $url )->svg( 5 );
		} catch ( \InvalidArgumentException $error ) {
			return;
		}

		echo '<h3>' . esc_html__( 'QR-код', 'indexpilot' ) . ' ';
		HelpTip::render( __( 'Ничего вписывать не нужно: код ведёт на короткий адрес выше.', 'indexpilot' ), __( 'Для заставки стрима и печати: зритель наводит камеру и попадает по короткой ссылке, переход засчитывается.', 'indexpilot' ) );
		echo '</h3>';
		// Картинка собрана кодом плагина только из чисел и постоянной разметки — пользовательского текста в ней нет.
		echo '<div data-indexpilot-qr style="max-width:240px">' . $svg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG целиком построен QrCode из модулей матрицы.
		printf(
			'<p><a class="button" download="%1$s" href="%2$s">%3$s</a></p>',
			esc_attr( 'qr-' . $slug . '.svg' ),
			esc_attr( 'data:image/svg+xml;base64,' . base64_encode( $svg ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- картинка для скачивания.
			esc_html__( 'Скачать SVG', 'indexpilot' )
		);
	}

	/** Подробности ссылки и форма изменения цели. */
	private function details(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- просмотр отчёта.
		$id = isset( $_GET['link'] ) ? absint( wp_unslash( $_GET['link'] ) ) : 0;
		if ( 0 === $id ) {
			return; }
		$link = $this->store->find_by_id( $id );
		if ( null === $link ) {
			return; }
		?>
<h2><?php echo esc_html( $link['slug'] ); ?></h2>
<form method="post">
		<?php wp_nonce_field( self::NONCE ); ?>
<input type="hidden" name="indexpilot_shortlink_action" value="update">
<input type="hidden" name="link_id" value="<?php echo esc_attr( (string) $id ); ?>">
<label for="ip-edit-target"><?php esc_html_e( 'Новый адрес', 'indexpilot' ); ?></label>
<input id="ip-edit-target" name="target" type="url" class="large-text" required value="<?php echo esc_attr( $link['target'] ); ?>">
		<?php submit_button( __( 'Изменить адрес', 'indexpilot' ) ); ?>
</form>
		<?php
		$this->qr( $this->router->url( $link['slug'] ), $link['slug'] );

		if ( null === $this->click_log ) {
			echo '<p>' . esc_html__( 'Журнал переходов не подключён.', 'indexpilot' ) . '</p>';
			return;
		}
		$daily = $this->click_log->by_day( $link['slug'], 30 );
		$days  = array();
		for ( $i = 29; $i >= 0; --$i ) {
			$day          = gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) );
			$days[ $day ] = $daily[ $day ] ?? 0;
		}
		foreach ( array(
			__( 'Переходы по дням за 30 дней (UTC)', 'indexpilot' ) => $days,
			__( 'Источники за 30 дней', 'indexpilot' ) => $this->click_log->by_referrer( $link['slug'], 30 ),
		) as $title => $rows ) {
			echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'День / источник', 'indexpilot' ) . '</th><th>' . esc_html__( 'Переходы', 'indexpilot' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $label => $hits ) {
				echo '<tr><td>' . esc_html( '' === $label ? __( 'Прямой переход', 'indexpilot' ) : (string) $label ) . '</td><td>' . esc_html( (string) $hits ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
