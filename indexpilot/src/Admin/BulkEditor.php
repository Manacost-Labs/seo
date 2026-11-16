<?php
/**
 * Массовый редактор заголовков и описаний.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Plugin;

/**
 * Страница со списком записей или терминов: заголовок и описание правятся прямо в таблице
 * и сохраняются пачкой. Данные загружает и сохраняет скрипт через REST (см. BulkController).
 */
final class BulkEditor {

	public const SLUG = 'indexpilot-bulk';

	/**
	 * Подключает страницу.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 14 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт меню. Страница доступна редакторам: право на каждую запись проверяется при сохранении.
	 */
	public function add(): void {
		add_submenu_page(
			SettingsPage::SLUG,
			__( 'IndexPilot — массовый редактор', 'indexpilot' ),
			__( 'Массовый редактор', 'indexpilot' ),
			'edit_others_posts',
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

		wp_enqueue_script( 'indexpilot-bulk', plugins_url( 'assets/js/bulk.js', INDEXPILOT_FILE ), array( 'wp-api-fetch' ), Plugin::VERSION, true );
		wp_localize_script(
			'indexpilot-bulk',
			'IndexPilotBulk',
			array(
				'text' => array(
					'name'        => __( 'Страница', 'indexpilot' ),
					'title'       => __( 'SEO-заголовок', 'indexpilot' ),
					'description' => __( 'Описание', 'indexpilot' ),
					'score'       => __( 'Оценка', 'indexpilot' ),
					'now'         => __( 'Сейчас на сайте:', 'indexpilot' ),
					'empty'       => __( 'Ничего не найдено.', 'indexpilot' ),
					'loading'     => __( 'Загрузка…', 'indexpilot' ),
					'failed'      => __( 'Запрос не выполнен. Попробуйте ещё раз.', 'indexpilot' ),
					'nothing'     => __( 'Изменений нет.', 'indexpilot' ),
					/* translators: 1: сохранено строк, 2: отклонено строк. */
					'saved'       => __( 'Сохранено строк: %1$d. Отклонено (нет прав): %2$d.', 'indexpilot' ),
					/* translators: 1: номер страницы, 2: всего страниц, 3: всего строк. */
					'pages'       => __( 'Страница %1$d из %2$d, всего строк: %3$d', 'indexpilot' ),
					'chars'       => __( 'симв.', 'indexpilot' ),
					'noindex'     => __( 'закрыта от индексации', 'indexpilot' ),
					'auto'        => __( 'собрано автоматически', 'indexpilot' ),
					'unsaved'     => __( 'Есть несохранённые изменения. Уйти со страницы списка?', 'indexpilot' ),
				),
			)
		);
	}

	/**
	 * Печатает страницу: элементы управления и пустую таблицу, которую заполняет скрипт.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		?>
		<div class="wrap" data-indexpilot-bulk>
			<h1><?php esc_html_e( 'IndexPilot — массовый редактор', 'indexpilot' ); ?></h1>
			<p><?php esc_html_e( 'Заголовки и описания правятся прямо в таблице. Пустое поле означает, что значение собирается по шаблону или из текста. Сохраняются только изменённые строки.', 'indexpilot' ); ?></p>

			<p>
				<label class="screen-reader-text" for="indexpilot-bulk-type"><?php esc_html_e( 'Что показывать', 'indexpilot' ); ?></label>
				<select id="indexpilot-bulk-type" data-indexpilot-bulk-type>
					<optgroup label="<?php esc_attr_e( 'Записи', 'indexpilot' ); ?>">
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
							<?php if ( 'attachment' !== $type->name && is_post_type_viewable( $type ) && current_user_can( $type->cap->edit_posts ) ) : ?>
								<option value="post:<?php echo esc_attr( $type->name ); ?>"><?php echo esc_html( $type->labels->name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</optgroup>
					<optgroup label="<?php esc_attr_e( 'Архивы терминов', 'indexpilot' ); ?>">
						<?php foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) : ?>
							<?php if ( 'post_format' !== $taxonomy->name && is_taxonomy_viewable( $taxonomy ) && current_user_can( $taxonomy->cap->edit_terms ) ) : ?>
								<option value="term:<?php echo esc_attr( $taxonomy->name ); ?>"><?php echo esc_html( $taxonomy->labels->name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</optgroup>
				</select>

				<label class="screen-reader-text" for="indexpilot-bulk-filter"><?php esc_html_e( 'Фильтр', 'indexpilot' ); ?></label>
				<select id="indexpilot-bulk-filter" data-indexpilot-bulk-filter>
					<option value="all"><?php esc_html_e( 'Все', 'indexpilot' ); ?></option>
					<option value="no_description"><?php esc_html_e( 'Нет описания', 'indexpilot' ); ?></option>
					<option value="long" data-posts-only><?php esc_html_e( 'Слишком длинно', 'indexpilot' ); ?></option>
					<option value="duplicate" data-posts-only><?php esc_html_e( 'Повторяются', 'indexpilot' ); ?></option>
				</select>

				<label class="screen-reader-text" for="indexpilot-bulk-search"><?php esc_html_e( 'Поиск по названию', 'indexpilot' ); ?></label>
				<input type="search" id="indexpilot-bulk-search" placeholder="<?php esc_attr_e( 'Поиск по названию', 'indexpilot' ); ?>" data-indexpilot-bulk-search>
				<button type="button" class="button" data-indexpilot-bulk-load><?php esc_html_e( 'Показать', 'indexpilot' ); ?></button>
			</p>

			<table class="widefat striped">
				<thead data-indexpilot-bulk-head></thead>
				<tbody data-indexpilot-bulk-rows></tbody>
			</table>

			<p>
				<button type="button" class="button button-primary" data-indexpilot-bulk-save><?php esc_html_e( 'Сохранить изменения', 'indexpilot' ); ?></button>
				<button type="button" class="button" data-indexpilot-bulk-prev>&larr; <?php esc_html_e( 'Назад', 'indexpilot' ); ?></button>
				<button type="button" class="button" data-indexpilot-bulk-next><?php esc_html_e( 'Дальше', 'indexpilot' ); ?> &rarr;</button>
				<span data-indexpilot-bulk-pages></span>
				<strong data-indexpilot-bulk-status aria-live="polite"></strong>
			</p>
		</div>
		<?php
	}
}
