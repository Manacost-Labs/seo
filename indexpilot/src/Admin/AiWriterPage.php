<?php
/**
 * Экран «ИИ-помощник»: сервис, ключ API и модель.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Ai\Writer\Account;
use IndexPilot\Ai\Writer\WriterException;

/**
 * Подключение модели, которая по кнопке в панели записи заполняет описание, ключевые слова, краткий ответ,
 * вопросы и alt картинок. Ключ на экран не выводится.
 */
final class AiWriterPage {

	public const SLUG = 'indexpilot-ai-writer';

	private const CAP   = 'manage_options';
	private const NONCE = 'indexpilot_ai_writer';

	/**
	 * Сообщения после действия: текст и успех.
	 *
	 * @var list<array{0: string, 1: bool}>
	 */
	private array $notices = array();

	/**
	 * Конструктор.
	 *
	 * @param Account $account Подключение.
	 */
	public function __construct( private Account $account ) {
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
			__( 'IndexPilot — ИИ-помощник', 'indexpilot' ),
			__( 'ИИ-помощник', 'indexpilot' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'handle' ) );
		}
	}

	/**
	 * Обрабатывает форму.
	 */
	public function handle(): void {
		if ( ! isset( $_POST['indexpilot_ai_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['indexpilot_ai_action'] ) );

		if ( 'remove' === $action ) {
			$this->account->remove_key();
			$this->notices[] = array( __( 'Ключ удалён.', 'indexpilot' ), true );
			return;
		}

		$provider = isset( $_POST['indexpilot_ai_provider'] ) ? sanitize_key( wp_unslash( $_POST['indexpilot_ai_provider'] ) ) : 'chatgpt';
		$model    = isset( $_POST['indexpilot_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['indexpilot_ai_model'] ) ) : '';
		$key      = isset( $_POST['indexpilot_ai_key'] ) ? sanitize_text_field( wp_unslash( $_POST['indexpilot_ai_key'] ) ) : '';

		// Сменили сервис, а модель осталась от прошлого: названия моделей у сервисов разные.
		if ( $provider !== $this->account->provider() && $model === $this->account->model() ) {
			$model = '';
		}

		$this->account->save( $provider, $model, $key );
		$this->notices[] = array( __( 'Настройки сохранены.', 'indexpilot' ), true );

		if ( 'check' === $action || '' !== $key ) {
			try {
				$this->account->client()->check();
				$this->notices[] = array( __( 'Ключ работает.', 'indexpilot' ), true );
			} catch ( WriterException $error ) {
				$this->notices[] = array( $error->getMessage(), false );
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

		$provider = $this->account->provider();
		?>
		<div class="wrap indexpilot-wrap">
			<h1><?php esc_html_e( 'ИИ-помощник', 'indexpilot' ); ?></h1>
			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice <?php echo $notice[1] ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $notice[0] ); ?></p></div>
			<?php endforeach; ?>
			<p class="indexpilot-intro">
				<?php esc_html_e( 'Когда статья дописана, нажмите в панели IndexPilot кнопку «Заполнить с ИИ». Модель прочитает текст и картинки и впишет в поля описание для поиска, ключевые слова, краткий ответ, вопросы и ответы и alt картинок без описания. Заголовок остаётся заголовком статьи. Поля, которые вы уже заполнили, без вашего согласия не меняются. Проверьте предложенное и сохраните запись как обычно.', 'indexpilot' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'ChatGPT по подписке работает через мост на этом сервере: Codex, вошедший в ваш аккаунт ChatGPT (tools/chatgpt-bridge в репозитории плагина). В поле ключа — токен моста. OpenAI API и OpenRouter — оплата за каждый запрос, обычно 1–3 цента за статью с картинками. Текст статьи и картинки уходят выбранному сервису.', 'indexpilot' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="indexpilot_ai_provider"><?php esc_html_e( 'Сервис', 'indexpilot' ); ?></label></th>
						<td>
							<select id="indexpilot_ai_provider" name="indexpilot_ai_provider">
								<?php foreach ( Account::PROVIDERS as $slug => $item ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $provider, $slug ); ?>><?php echo esc_html( $item['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Где взять ключ:', 'indexpilot' ); ?>
								<?php foreach ( array_filter( Account::PROVIDERS, static fn( array $item ): bool => '' !== $item['keys'] ) as $item ) : ?>
									<a href="<?php echo esc_url( $item['keys'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['label'] ); ?></a>
								<?php endforeach; ?>
								<?php esc_html_e( '— пополните баланс на несколько долларов и создайте ключ.', 'indexpilot' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="indexpilot_ai_key"><?php esc_html_e( 'Ключ API или токен моста', 'indexpilot' ); ?></label></th>
						<td>
							<input type="password" id="indexpilot_ai_key" name="indexpilot_ai_key" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $this->account->has_key() ? __( 'сохранён — впишите новый, чтобы заменить', 'indexpilot' ) : 'sk-…' ); ?>">
							<?php if ( $this->account->has_key() ) : ?>
								<p class="description"><?php esc_html_e( 'Ключ сохранён.', 'indexpilot' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="indexpilot_ai_model"><?php esc_html_e( 'Модель', 'indexpilot' ); ?></label></th>
						<td>
							<input type="text" id="indexpilot_ai_model" name="indexpilot_ai_model" class="regular-text code" value="<?php echo esc_attr( $this->account->model() ); ?>" autocomplete="off">
							<p class="description">
								<?php
								/* translators: 1: модель для OpenAI, 2: модель для OpenRouter. */
								echo esc_html( sprintf( __( 'Модель должна понимать картинки. По умолчанию: default для ChatGPT (модель из настроек Codex), %1$s для OpenAI, %2$s для OpenRouter. Оставьте поле пустым, чтобы вернуть модель по умолчанию.', 'indexpilot' ), Account::PROVIDERS['openai']['model'], Account::PROVIDERS['openrouter']['model'] ) );
								?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary" name="indexpilot_ai_action" value="save"><?php esc_html_e( 'Сохранить', 'indexpilot' ); ?></button>
					<?php if ( $this->account->has_key() ) : ?>
						<button type="submit" class="button" name="indexpilot_ai_action" value="check"><?php esc_html_e( 'Проверить ключ', 'indexpilot' ); ?></button>
						<button type="submit" class="button button-link-delete" name="indexpilot_ai_action" value="remove"><?php esc_html_e( 'Удалить ключ', 'indexpilot' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}
}
