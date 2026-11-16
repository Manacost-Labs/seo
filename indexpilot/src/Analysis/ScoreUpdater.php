<?php
/**
 * Пересчёт оценки при сохранении.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;

/**
 * Оценка считается на сервере при каждом сохранении, поэтому она есть и у записей,
 * созданных импортом, через REST или WP-CLI, а не только у открытых в редакторе.
 */
final class ScoreUpdater {

	/**
	 * Конструктор.
	 *
	 * @param PaperFactory $papers   Сборка данных страницы.
	 * @param Analyzer     $analyzer Анализатор.
	 */
	public function __construct(
		private PaperFactory $papers,
		private Analyzer $analyzer
	) {
	}

	/**
	 * Подключает пересчёт.
	 */
	public function register(): void {
		// wp_after_insert_post срабатывает, когда мета и термины записи уже сохранены.
		add_action( 'wp_after_insert_post', array( $this, 'update_post' ), 20, 2 );
		add_action( 'edited_term', array( $this, 'update_term' ), 20, 3 );
	}

	/**
	 * Пересчитывает оценку записи.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 */
	public function update_post( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$report = $this->analyze( new Context( Context::POST, (int) $post_id, $post->post_type ) );

		update_post_meta( (int) $post_id, MetaKeys::SCORE, $report->score );
		update_post_meta( (int) $post_id, MetaKeys::ANALYSIS, wp_slash( (string) wp_json_encode( $report->to_array(), JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * Пересчитывает оценку термина.
	 *
	 * @param int    $term_id  ID термина.
	 * @param int    $tt_id    ID связи термина с таксономией.
	 * @param string $taxonomy Таксономия.
	 */
	public function update_term( $term_id, $tt_id, $taxonomy ): void {
		unset( $tt_id );

		if ( ! is_taxonomy_viewable( (string) $taxonomy ) ) {
			return;
		}

		$report = $this->analyze( new Context( Context::TERM, (int) $term_id, (string) $taxonomy ) );

		update_term_meta( (int) $term_id, MetaKeys::SCORE, $report->score );
		update_term_meta( (int) $term_id, MetaKeys::ANALYSIS, wp_slash( (string) wp_json_encode( $report->to_array(), JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * Анализирует страницу по сохранённым данным.
	 *
	 * @param Context $context Контекст страницы.
	 */
	public function analyze( Context $context ): Report {
		return $this->analyzer->analyze( $this->papers->create( $context ) );
	}
}
