<?php
/**
 * Цифры для экрана «Обзор».
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Overview;

use IndexPilot\Audit\Runner as AuditRunner;
use IndexPilot\Links\Scanner as LinkScanner;
use IndexPilot\Links\Schema as LinkSchema;
use IndexPilot\Health\WpStore as HealthStore;
use IndexPilot\Redirects\RedirectService;
use IndexPilot\Redirects\WpNotFoundStore;

/**
 * Собирает числа из уже готовых данных: таблиц аудита и ссылок, сохранённых состояний обходов и результатов
 * проверки редиректов. Записи сайта здесь не обходятся — только подсчёты по собственным таблицам плагина.
 */
final class Facts {

	/**
	 * Сколько непроверенных адресов сверяется с таблицей ссылок одним запросом.
	 */
	private const ADDRESS_LIMIT = 200;

	/**
	 * С какого числа обращений адрес из журнала 404 считается частым.
	 */
	public const FREQUENT_404 = 5;

	/**
	 * Конструктор.
	 *
	 * @param \wpdb           $db        База WordPress.
	 * @param AuditRunner     $audit     Аудит записей.
	 * @param LinkScanner     $scanner   Обход записей для графа ссылок.
	 * @param RedirectService $redirects Редиректы сайта.
	 */
	public function __construct(
		private \wpdb $db,
		private AuditRunner $audit,
		private LinkScanner $scanner,
		private RedirectService $redirects
	) {
	}

	/**
	 * Числа по ключам дел из TaskList::KEYS.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$audit   = $this->audit->state();
		$links   = $this->scanner->state();
		$audited = $audit['processed'] > 0 && $audit['done'];
		$scanned = $links['processed'] > 0 && $links['done'];
		$counts  = array(
			'run_audit'  => $audited ? 0 : 1,
			'scan_links' => $scanned ? 0 : 1,
		);

		if ( $audit['processed'] > 0 ) {
			$summary = $this->audit->summary();

			$counts += array(
				'duplicate_titles'       => (int) ( $summary['duplicate_title_hash'] ?? 0 ),
				'duplicate_descriptions' => (int) ( $summary['duplicate_description_hash'] ?? 0 ),
				'no_description'         => (int) ( $summary['no_description'] ?? 0 ),
				'auto_description'       => (int) ( $summary['auto_description'] ?? 0 ),
				'thin'                   => (int) ( $summary['thin'] ?? 0 ),
				'h1'                     => (int) ( $summary['h1'] ?? 0 ),
				'stale'                  => (int) ( $summary['stale'] ?? 0 ),
			);
		}

		if ( $links['processed'] > 0 ) {
			$counts['broken_links'] = $this->broken_links();

			// «Сирота» — открытая для индексации запись без входящих ссылок: открытость известна только после аудита.
			if ( $audit['processed'] > 0 ) {
				$counts['orphans'] = $this->orphans();
			}
		}

		$health = ( new HealthStore( $this->db ) )->counts();

		// Данные поиска: цифры пересчитаны после загрузки и лежат в опции с автозагрузкой — без запроса к базе.
		$queries = \IndexPilot\SearchData\WpQueryStore::summary();

		return $counts + $this->redirect_counts() + array(
			'queries_near_top' => (int) ( $queries['near_top'] ?? 0 ),
			'queries_cannibal' => (int) ( $queries['cannibal'] ?? 0 ),
			'frequent_404'     => ( new WpNotFoundStore( $this->db ) )->count( self::FREQUENT_404 ),
			'dead_outbound'    => $health['dead'],
			'health_problems'  => $health['problems'],
		);
	}

	/**
	 * Открытые для индексации записи, на которые не ссылается ни одна другая запись.
	 */
	private function orphans(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт по собственным таблицам для экрана админки.
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM %i a WHERE a.indexable = 1 AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.target_post_id = a.post_id AND l.type = 'internal' AND l.nofollow = 0 )",
				$this->audit->table(),
				LinkSchema::table( $this->db )
			)
		);
	}

	/**
	 * Внутренние ссылки, которые ведут на снятую с публикации или удалённую запись либо на адрес,
	 * который при проверке не открылся.
	 */
	private function broken_links(): int {
		$table = LinkSchema::table( $this->db );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт по собственной таблице для экрана админки.
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT( DISTINCT l.url ) FROM %i l LEFT JOIN {$this->db->posts} p ON p.ID = l.target_post_id WHERE l.type = 'internal' AND l.target_post_id > 0 AND ( p.ID IS NULL OR p.post_status <> 'publish' )",
				$table
			)
		);

		$failed = array();

		foreach ( $this->redirects->targets()->results() as $url => $result ) {
			$status = (int) ( $result['status'] ?? 0 );

			if ( 0 === $status || $status >= 400 ) {
				$failed[] = (string) $url;
			}
		}

		$failed = array_slice( $failed, 0, self::ADDRESS_LIMIT );

		if ( array() === $failed ) {
			return $count;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $failed ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- в запрос подставляется только список заполнителей %s по числу адресов.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- подсчёт по собственной таблице для экрана админки.
		$count += (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT( DISTINCT url ) FROM %i WHERE type = 'internal' AND target_post_id = 0 AND url IN ( {$placeholders} )",
				array_merge( array( $table ), $failed )
			)
		);
		// phpcs:enable

		return $count;
	}

	/**
	 * Цепочки редиректов и редиректы, цель которых не открывается. Считаются по редиректам плагина Redirection
	 * и сохранённым результатам проверки целей; сами адреса здесь не запрашиваются.
	 *
	 * @return array<string, int>
	 */
	private function redirect_counts(): array {
		if ( ! $this->redirects->available() ) {
			return array();
		}

		$resolver = $this->redirects->resolver();
		$targets  = $this->redirects->targets();
		$dead     = 0;

		foreach ( $this->redirects->all() as $redirect ) {
			$result = $resolver->resolve( $redirect->source );
			$status = $result['loop'] ? null : $targets->status( $result['final'] );

			if ( null !== $status && ( 0 === $status || $status >= 400 ) ) {
				++$dead;
			}
		}

		return array(
			'redirect_chains' => count( $resolver->problems() ),
			'dead_redirects'  => $dead,
		);
	}
}
