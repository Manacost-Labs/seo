<?php
/**
 * Запросы из поиска в панели записи и в форме рубрики.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\SearchData\Module;
use IndexPilot\SearchData\QueryAdvisor;
use IndexPilot\SearchData\TermQueries;

/**
 * Вкладка «Запросы» панели записи: по каким запросам страницу показывают, позиция, клики, показы,
 * подсказки и кнопка «сделать главным запросом». В форме рубрики, метки, класса и архетипа — запросы самой
 * страницы и её записей, предложенные основной и дополнительные ключи, темы для вводного текста.
 * Данные читаются только здесь, в админке, из своей таблицы.
 */
final class QueriesPanel {

	private const ROWS = 30;

	public function __construct( private Module $module ) {
	}

	/**
	 * Подключает хуки.
	 */
	public function register(): void {
		add_action( 'indexpilot_metabox_queries', array( $this, 'render_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term' ), 25, 2 );
		}
	}

	/**
	 * Скрипт кнопки «сделать главным запросом» — только на экранах записи и термина.
	 *
	 * @param string $hook Экран.
	 */
	public function enqueue( $hook ): void {
		if ( in_array( $hook, array( 'post.php', 'post-new.php', 'term.php' ), true ) ) {
			wp_enqueue_script( 'indexpilot-queries', plugins_url( 'assets/js/queries.js', INDEXPILOT_FILE ), array(), Plugin::VERSION, true );
		}
	}

	/**
	 * Вкладка «Запросы» в панели записи.
	 *
	 * @param \WP_Post $post Запись.
	 */
	public function render_post( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		echo '<div data-indexpilot-queries>';
		echo '<h4>' . esc_html__( 'Запросы из поиска за 28 дней', 'indexpilot' ) . ' ';
		HelpTip::render(
			__( 'ничего вписывать не нужно. Здесь запросы, по которым Google показывал эту страницу. Кнопка «Сделать главным запросом» вписывает запрос в основной ключ на вкладке «Вид в поиске» — потом сохраните запись.', 'indexpilot' ),
			__( 'настоящие запросы показывают, как люди ищут статью: самый частый запрос стоит поставить в заголовок, а если позиция высокая, но кликов мало, — переписать заголовок и описание.', 'indexpilot' )
		);
		echo '</h4>';

		if ( ! $this->ready() ) {
			echo '</div>';
			return;
		}

		$rows = $this->module->store()->for_objects( 'post', array( (int) $post->ID ), 'current', self::ROWS );

		if ( array() === $rows ) {
			echo '<p class="description" data-indexpilot-queries-empty>' . esc_html__( 'За последние 28 дней Google не показывал эту страницу ни по одному запросу. У новых страниц данные появляются через несколько дней после того, как поисковик их найдёт.', 'indexpilot' ) . '</p></div>';
			return;
		}

		$matcher = $this->module->plugin()->analyzer()->matcher();
		$same    = static fn( string $query, string $text ): bool => '' !== trim( $text ) && $matcher->contains( $text, $query );
		$title   = (string) get_post_meta( $post->ID, MetaKeys::TITLE, true );
		$hints   = ( new QueryAdvisor() )->hints(
			$rows,
			'' !== $title ? $title : (string) $post->post_title,
			(string) get_post_meta( $post->ID, MetaKeys::DESCRIPTION, true ),
			(string) get_post_meta( $post->ID, MetaKeys::FOCUS_KEYWORD, true ),
			$same
		);

		if ( array() !== $hints ) {
			echo '<ul class="indexpilot-query-hints">';

			foreach ( $hints as $hint ) {
				printf( '<li data-indexpilot-query-hint="%1$s">%2$s</li>', esc_attr( $hint['type'] ), esc_html( $this->hint( $hint ) ) );
			}

			echo '</ul>';
		}

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Запрос', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Позиция', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Клики', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Показы', 'indexpilot' ) . '</th><th scope="col"></th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr data-indexpilot-query-row><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) $row['query'] ),
				esc_html( number_format_i18n( (float) $row['position'], 1 ) ),
				esc_html( number_format_i18n( (int) $row['clicks'] ) ),
				esc_html( number_format_i18n( (int) $row['impressions'] ) ),
				$this->use_button( (string) $row['query'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- экранировано в use_button().
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Строки формы термина: запросы страницы архива и её записей, предложенные ключи.
	 *
	 * @param \WP_Term $term     Термин.
	 * @param string   $taxonomy Таксономия.
	 */
	public function render_term( $term, $taxonomy ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		echo '<tr class="form-field" data-indexpilot-term-queries><th scope="row">' . esc_html__( 'Запросы из поиска', 'indexpilot' ) . ' ';
		HelpTip::render(
			__( 'ничего вписывать не нужно. Здесь собраны запросы самой страницы рубрики и её записей за 28 дней; одинаковые по смыслу («дека приста» и «колода жреца») сведены в один. Кнопка вписывает запрос в поле Focus Keyword выше.', 'indexpilot' ),
			__( 'страница рубрики должна отвечать на самые частые запросы раздела: основной ключ — в заголовок, дополнительные и темы — во вступительный текст.', 'indexpilot' )
		);
		echo '</th><td>';

		if ( ! $this->ready() ) {
			echo '</td></tr>';
			return;
		}

		$store  = $this->module->store();
		$posts  = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'publish',
				'numberposts' => 100,
				'fields'      => 'ids',
				'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query -- форма термина в админке.
					array(
						'taxonomy'         => (string) $taxonomy,
						'terms'            => array( (int) $term->term_id ),
						'include_children' => false,
					),
				),
			)
		);
		$result = TermQueries::collect(
			$store->for_objects( 'term', array( (int) $term->term_id ), 'current', 100 ),
			$store->for_objects( 'post', array_map( 'intval', $posts ), 'current', 2000 ),
			(string) $term->name,
			$this->module->normalizer()
		);

		if ( array() === $result['queries'] ) {
			echo '<p class="description" data-indexpilot-queries-empty>' . esc_html__( 'За последние 28 дней ни страницу рубрики, ни её записи Google не показывал.', 'indexpilot' ) . '</p></td></tr>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Основной ключ:', 'indexpilot' ) . '</strong> ' . esc_html( $result['main'] ) . ' ' . $this->use_button( $result['main'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- экранировано в use_button().

		if ( array() !== $result['extra'] ) {
			echo '<p><strong>' . esc_html__( 'Дополнительные ключи:', 'indexpilot' ) . '</strong> ' . esc_html( implode( ', ', $result['extra'] ) ) . '</p>';
		}

		if ( array() !== $result['topics'] ) {
			echo '<p><strong>' . esc_html__( 'Темы для вступительного текста:', 'indexpilot' ) . '</strong> ' . esc_html( implode( ', ', $result['topics'] ) ) . '</p>';
		}

		if ( ! $result['name_found'] ) {
			echo '<p class="description" data-indexpilot-no-name-shows>' . esc_html__( 'По названию рубрики показов нет: люди ищут этот раздел другими словами — возьмите их в заголовок и описание.', 'indexpilot' ) . '</p>';
		}

		echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th scope="col">' . esc_html__( 'Запрос', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Позиция', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Клики', 'indexpilot' ) . '</th><th scope="col">' . esc_html__( 'Показы', 'indexpilot' ) . '</th></tr></thead><tbody>';

		foreach ( array_slice( $result['queries'], 0, 15 ) as $query ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $query['query'] ),
				esc_html( number_format_i18n( $query['position'], 1 ) ),
				esc_html( number_format_i18n( $query['clicks'] ) ),
				esc_html( number_format_i18n( $query['impressions'] ) )
			);
		}

		echo '</tbody></table></td></tr>';
	}

	/**
	 * Готовы ли данные; если нет — печатает, как подключить.
	 */
	private function ready(): bool {
		if ( array() === $this->module->credentials()->engines() ) {
			echo '<p class="description" data-indexpilot-queries-off>' . esc_html__( 'Данные поиска не подключены. Администратор подключает Google Search Console и Яндекс Вебмастер в меню IndexPilot → Техника → Данные поиска: загружает файл ключа служебной учётной записи Google и вписывает токен Вебмастера. После первой загрузки здесь появятся запросы, позиции и клики.', 'indexpilot' );

			if ( current_user_can( 'manage_options' ) ) {
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . SearchDataPage::SLUG ) ) . '">' . esc_html__( 'Подключить', 'indexpilot' ) . '</a>';
			}

			echo '</p>';

			return false;
		}

		if ( ! $this->module->has_data() ) {
			echo '<p class="description" data-indexpilot-queries-wait>' . esc_html__( 'Поисковики подключены, данные ещё загружаются: это происходит раз в сутки в фоне. Загрузить сразу можно кнопкой «Обновить данные сейчас» в меню IndexPilot → Техника → Данные поиска.', 'indexpilot' ) . '</p>';

			return false;
		}

		return true;
	}

	/**
	 * Текст подсказки.
	 *
	 * @param array{type: string, query: string, impressions: int, position: float} $hint Подсказка.
	 */
	private function hint( array $hint ): string {
		switch ( $hint['type'] ) {
			case 'missing_in_title':
				/* translators: 1: запрос, 2: число показов. */
				return sprintf( __( 'Запрос «%1$s» с %2$s показами не встречается в заголовке — добавьте его в заголовок.', 'indexpilot' ), $hint['query'], number_format_i18n( $hint['impressions'] ) );
			case 'low_ctr':
				/* translators: 1: запрос, 2: позиция. */
				return sprintf( __( '«%1$s»: позиция %2$s, а кликов мало — перепишите заголовок и описание.', 'indexpilot' ), $hint['query'], number_format_i18n( $hint['position'], 1 ) );
			default:
				/* translators: %s: запрос. */
				return sprintf( __( 'Попробуйте основной ключ «%s»: по нему страницу показывают чаще всего.', 'indexpilot' ), $hint['query'] );
		}
	}

	/**
	 * Кнопка «сделать главным запросом».
	 *
	 * @param string $query Запрос.
	 */
	private function use_button( string $query ): string {
		return sprintf(
			'<button type="button" class="button button-small" data-indexpilot-use-query="%1$s" data-done="%2$s">%3$s</button> <span data-indexpilot-use-status aria-live="polite"></span>',
			esc_attr( $query ),
			esc_attr__( 'Вписано в основной ключ — сохраните.', 'indexpilot' ),
			esc_html__( 'Сделать главным запросом', 'indexpilot' )
		);
	}
}
