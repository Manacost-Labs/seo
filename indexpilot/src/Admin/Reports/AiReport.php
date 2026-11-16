<?php
/**
 * Отчёт «Ассистенты».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin\Reports;

use IndexPilot\Admin\HelpTip;
use IndexPilot\Admin\SettingsPage;
use IndexPilot\Ai\Questions;
use IndexPilot\Ai\VisitCounter;
use IndexPilot\Ai\Visits;

/**
 * Какие страницы читают роботы ассистентов (ChatGPT, Perplexity, Claude, Алиса), куда приходят читатели из
 * ассистентов, и таблица из 20 контрольных вопросов для ручной проверки раз в месяц.
 */
final class AiReport {

	public const SLUG = 'indexpilot-ai';

	private const CAPABILITY = 'edit_others_posts';

	private const ACTION = 'indexpilot_ai_questions';

	/**
	 * Конструктор.
	 *
	 * @param VisitCounter $counter Счётчик заходов.
	 */
	public function __construct( private VisitCounter $counter ) {
	}

	/**
	 * Подключает экран и сохранение вопросов.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 14 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Пункт меню.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — ассистенты', 'indexpilot' ),
			__( 'Ассистенты', 'indexpilot' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Вопросы: сохранённые или по умолчанию.
	 *
	 * @return list<array{question: string, status: string, who: string, checked: string}>
	 */
	public static function questions(): array {
		$saved = Questions::sanitize( get_option( Questions::OPTION, array() ) );

		return array() !== $saved ? $saved : Questions::defaults();
	}

	/**
	 * Сохраняет таблицу вопросов.
	 */
	public function save(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'indexpilot' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- очищается в Questions::sanitize.
		$rows = isset( $_POST['q'] ) && is_array( $_POST['q'] ) ? wp_unslash( $_POST['q'] ) : array();

		update_option( Questions::OPTION, Questions::sanitize( $rows ), false );
		wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Печатает экран.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Свежие заходы ещё в копилке: перед отчётом она выгружается в таблицу.
		$this->counter->flush();
		?>
		<div class="wrap" data-indexpilot-ai>
			<h1><?php esc_html_e( 'Ассистенты: ChatGPT, Perplexity, Claude, Алиса', 'indexpilot' ); ?></h1>
			<p><?php esc_html_e( 'Данные за 30 дней. Страницы, которые отдаёт плагин кеширования без PHP, здесь не видны.', 'indexpilot' ); ?></p>
			<?php
			$this->table(
				__( 'Какие страницы читают роботы ассистентов', 'indexpilot' ),
				$this->counter->top( Visits::BOT ),
				__( 'Робот', 'indexpilot' ),
				__( 'Роботов ассистентов ещё не было.', 'indexpilot' )
			);
			$this->table(
				__( 'Куда приходят читатели из ассистентов', 'indexpilot' ),
				$this->counter->top( Visits::REFERRAL ),
				__( 'Ассистент', 'indexpilot' ),
				__( 'Переходов из ассистентов ещё не было.', 'indexpilot' )
			);
			$this->questions_form();
			?>
		</div>
		<?php
	}

	/**
	 * Таблица счётчиков.
	 *
	 * @param string                                              $title Заголовок.
	 * @param list<array{path: string, agent: string, hits: int}> $rows  Строки.
	 * @param string                                              $who   Название столбца источника.
	 * @param string                                              $nothing Текст для пустой таблицы.
	 */
	private function table( string $title, array $rows, string $who, string $nothing ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html( $nothing ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Страница', 'indexpilot' ) . '</th><th>' . esc_html( $who ) . '</th><th>' . esc_html__( 'Заходов', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$d</td></tr>',
				esc_url( home_url( $row['path'] ) ),
				esc_html( $row['path'] ),
				esc_html( $row['agent'] ),
				(int) $row['hits']
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Таблица контрольных вопросов.
	 */
	private function questions_form(): void {
		$rows = self::questions();

		$rows = array_pad(
			$rows,
			Questions::COUNT,
			array(
				'question' => '',
				'status'   => '',
				'who'      => '',
				'checked'  => '',
			)
		);
		?>
		<h2>
			<?php esc_html_e( 'Контрольные вопросы (проверка раз в месяц)', 'indexpilot' ); ?>
			<?php HelpTip::render( __( 'Задайте каждый вопрос ChatGPT, Perplexity и Алисе. Отметьте, есть ли в ответе ссылка на наш сайт, и впишите, кого цитируют вместо нас.', 'indexpilot' ), __( 'Так видно, по каким темам ассистенты нас уже знают, а где нужен более прямой ответ или свежие данные на странице.', 'indexpilot' ) ); ?>
		</h2>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только сообщение после сохранения. ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Таблица сохранена.', 'indexpilot' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<table class="widefat striped" data-indexpilot-questions="<?php echo esc_attr( (string) count( array_filter( array_column( $rows, 'question' ), 'strlen' ) ) ); ?>">
				<thead><tr><th>#</th><th><?php esc_html_e( 'Вопрос', 'indexpilot' ); ?></th><th><?php esc_html_e( 'Цитируют нас?', 'indexpilot' ); ?></th><th><?php esc_html_e( 'Кого цитируют', 'indexpilot' ); ?></th><th><?php esc_html_e( 'Дата проверки', 'indexpilot' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $index => $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
						<td><input type="text" class="large-text" name="q[<?php echo esc_attr( (string) $index ); ?>][question]" value="<?php echo esc_attr( $row['question'] ); ?>" maxlength="200"></td>
						<td>
							<select name="q[<?php echo esc_attr( (string) $index ); ?>][status]">
								<option value=""><?php esc_html_e( '— не проверено —', 'indexpilot' ); ?></option>
								<option value="<?php echo esc_attr( Questions::CITED ); ?>" <?php selected( $row['status'], Questions::CITED ); ?>><?php esc_html_e( 'цитируют', 'indexpilot' ); ?></option>
								<option value="<?php echo esc_attr( Questions::NOT_CITED ); ?>" <?php selected( $row['status'], Questions::NOT_CITED ); ?>><?php esc_html_e( 'нет', 'indexpilot' ); ?></option>
							</select>
						</td>
						<td><input type="text" class="regular-text" name="q[<?php echo esc_attr( (string) $index ); ?>][who]" value="<?php echo esc_attr( $row['who'] ); ?>" maxlength="300" placeholder="<?php esc_attr_e( 'например, hearthpwn.com', 'indexpilot' ); ?>"></td>
						<td><input type="date" name="q[<?php echo esc_attr( (string) $index ); ?>][checked]" value="<?php echo esc_attr( $row['checked'] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить таблицу', 'indexpilot' ) ); ?>
		</form>
		<?php
	}
}
