<?php
/**
 * Колонка «SEO» в списке записей.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Admin;

use IndexPilot\Analysis\Report;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Plugin;
use IndexPilot\Redirects\RedirectedPosts;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;

/**
 * Показывает в списке записей оценку и то, что требует внимания: нет описания, страница закрыта от индексации.
 */
final class PostListColumns {

	private const COLUMN = 'indexpilot';

	/**
	 * Конструктор.
	 *
	 * @param MetaResolver         $resolver   Резолвер мета-данных.
	 * @param RedirectedPosts|null $redirected Записи под редиректом.
	 */
	public function __construct( private MetaResolver $resolver, private ?RedirectedPosts $redirected = null ) {
	}

	/**
	 * Подключает колонку ко всем типам записей, у которых есть страница на сайте.
	 * Вызывается после регистрации типов записей.
	 */
	public function register(): void {
		foreach ( get_post_types( array( 'show_ui' => true ) ) as $post_type ) {
			if ( ! is_post_type_viewable( $post_type ) || 'attachment' === $post_type ) {
				continue;
			}

			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render' ), 10, 2 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет колонку перед датой.
	 *
	 * @param array<string, string> $columns Колонки списка.
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = (array) $columns;
		$result  = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$result[ self::COLUMN ] = __( 'SEO', 'indexpilot' );
			}

			$result[ $key ] = $label;
		}

		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = __( 'SEO', 'indexpilot' );
		}

		return $result;
	}

	/**
	 * Печатает ячейку колонки.
	 *
	 * @param string $column  Имя колонки.
	 * @param int    $post_id ID записи.
	 */
	public function render( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$score = get_post_meta( $post->ID, MetaKeys::SCORE, true );
		$meta  = $this->resolver->resolve( new Context( Context::POST, (int) $post->ID, $post->post_type ) );

		if ( '' === $score ) {
			printf( '<span class="indexpilot-score" title="%s">—</span>', esc_attr__( 'Оценка появится после сохранения записи', 'indexpilot' ) );
		} else {
			printf(
				'<span class="indexpilot-score" data-level="%1$s" title="%2$s">%3$d</span>',
				esc_attr( Report::level( (int) $score ) ),
				esc_attr__( 'Оценка SEO от 0 до 100', 'indexpilot' ),
				(int) $score
			);
		}

		if ( in_array( $meta->description_source, array( 'auto', 'none' ), true ) ) {
			printf(
				'<span class="indexpilot-flag" data-flag="no-description" title="%1$s">%2$s</span>',
				esc_attr__( 'Описание не заполнено: поисковик покажет начало текста или выберет фрагмент сам', 'indexpilot' ),
				esc_html__( 'нет описания', 'indexpilot' )
			);
		}

		$redirect = null !== $this->redirected ? $this->redirected->target( (int) $post->ID ) : '';

		if ( '' !== $redirect ) {
			printf(
				'<span class="indexpilot-flag" data-flag="redirect" title="%1$s">%2$s</span>',
				/* translators: %s: адрес, на который уходит посетитель. */
				esc_attr( sprintf( __( 'С адреса записи стоит редирект: посетитель попадает на %s', 'indexpilot' ), $redirect ) ),
				/* translators: %s: домен, на который уходит посетитель. */
				esc_html( sprintf( __( 'редирект → %s', 'indexpilot' ), (string) wp_parse_url( $redirect, PHP_URL_HOST ) ) )
			);
		}

		if ( ! $meta->is_indexable() ) {
			printf(
				'<span class="indexpilot-flag" data-flag="noindex" title="%1$s">%2$s</span>',
				esc_attr__( 'Страница закрыта от индексации', 'indexpilot' ),
				esc_html__( 'noindex', 'indexpilot' )
			);
		}

		/**
		 * Дополнительные отметки в колонке SEO, например «устарело после патча».
		 *
		 * @param \WP_Post $post Запись.
		 */
		do_action( 'indexpilot_post_list_flags', $post );
	}

	/**
	 * Подключает стили колонки только на экране списка записей.
	 *
	 * @param string $hook Текущий экран админки.
	 */
	public function enqueue( $hook ): void {
		if ( 'edit.php' === $hook ) {
			wp_enqueue_style( 'indexpilot-list', plugins_url( 'assets/css/list.css', INDEXPILOT_FILE ), array(), Plugin::VERSION );
		}
	}
}
