<?php
/**
 * Поиск страниц сайта с тем же основным ключом.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Analysis;

use IndexPilot\Game\SiteGames;
use IndexPilot\Meta\MetaKeys;

/**
 * Находит опубликованные записи, у которых основной ключ тот же — с учётом форм слов и игровых синонимов:
 * «дека приста» занимает ключ «колода жреца». Ключи читаются одним запросом, сравнивает их KeywordMatcher.
 * Вызывается только при анализе записи и по запросу второго сайта, не на страницах сайта.
 */
final class KeywordUsage {

	/**
	 * Сколько ключей просматривается за один раз: самые новые записи.
	 */
	private const SCAN_LIMIT = 5000;

	/**
	 * Конструктор.
	 *
	 * @param \wpdb          $db      База WordPress.
	 * @param KeywordMatcher $matcher Сравнение ключей.
	 */
	public function __construct( private \wpdb $db, private KeywordMatcher $matcher ) {
	}

	/**
	 * ID опубликованных записей с тем же основным ключом, новые первыми.
	 *
	 * @param string $keyword     Основной ключ.
	 * @param int    $exclude     ID записи, которую не учитывать; 0 — учитывать все.
	 * @param int    $limit       Сколько записей вернуть.
	 * @param bool   $public_only Пропускать записи под паролем.
	 * @param string $game        Игра записи: записи других игр ключ не занимают; пусто — все игры.
	 * @param string $default     Игра записей без отметки игры.
	 * @return list<int>
	 */
	public function posts( string $keyword, int $exclude = 0, int $limit = 3, bool $public_only = false, string $game = '', string $default = '' ): array {
		$keyword = trim( $keyword );

		if ( '' === $keyword || $limit <= 0 ) {
			return array();
		}

		$password = $public_only ? " AND p.post_password = ''" : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- в запрос подставляется только готовое условие без данных пользователя.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- три столбца по индексу ключа меты; только при анализе одной записи.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT m.post_id, m.meta_value, g.meta_value AS game FROM {$this->db->postmeta} m JOIN {$this->db->posts} p ON p.ID = m.post_id
				LEFT JOIN {$this->db->postmeta} g ON g.post_id = m.post_id AND g.meta_key = %s
				WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_status = 'publish' AND p.ID <> %d{$password}
				ORDER BY m.post_id DESC LIMIT %d",
				SiteGames::META,
				MetaKeys::FOCUS_KEYWORD,
				$exclude,
				self::SCAN_LIMIT
			),
			ARRAY_A
		);
		// phpcs:enable

		return self::matching( (array) $rows, $keyword, $this->matcher, $limit, $game, $default );
	}

	/**
	 * Отбирает строки с тем же ключом.
	 *
	 * @param array<int, mixed> $rows    Строки: post_id, meta_value, game.
	 * @param string            $keyword Основной ключ.
	 * @param KeywordMatcher    $matcher Сравнение ключей.
	 * @param int               $limit   Сколько записей вернуть.
	 * @param string            $game    Игра: строки других игр пропускаются; пусто — все игры.
	 * @param string            $default Игра строк без отметки игры.
	 * @return list<int>
	 */
	public static function matching( array $rows, string $keyword, KeywordMatcher $matcher, int $limit, string $game = '', string $default = '' ): array {
		$found = array();
		$known = array();

		foreach ( $rows as $row ) {
			$other = is_array( $row ) ? trim( (string) ( $row['meta_value'] ?? '' ) ) : '';

			if ( '' === $other ) {
				continue;
			}

			// «Гайд на жреца» в World of Warcraft не занимает тот же ключ в Hearthstone.
			if ( '' !== $game && $game !== ( '' !== (string) ( $row['game'] ?? '' ) ? (string) $row['game'] : $default ) ) {
				continue;
			}

			// Один и тот же ключ у сотен колод сравнивается один раз.
			$known[ $other ] ??= $matcher->same( $other, $keyword );

			if ( $known[ $other ] ) {
				$found[] = (int) $row['post_id'];

				if ( count( $found ) >= $limit ) {
					break;
				}
			}
		}

		return $found;
	}
}
