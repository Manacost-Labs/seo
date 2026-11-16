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
	 * Ключи опубликованных записей, прочитанные в этом запросе; null — ещё не читались.
	 *
	 * @var array<int, array{keyword: string, protected: bool, game: string}>|null
	 */
	private static ?array $known = null;

	/**
	 * Подключены ли хуки, которые держат список в актуальном виде.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

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
	 * @param string $default_game Игра записей без отметки игры.
	 * @return list<int>
	 */
	public function posts( string $keyword, int $exclude = 0, int $limit = 3, bool $public_only = false, string $game = '', string $default_game = '' ): array {
		$keyword = trim( $keyword );

		if ( '' === $keyword || $limit <= 0 ) {
			return array();
		}

		return self::matching( self::candidates( $this->known(), $exclude, $public_only ), $keyword, $this->matcher, $limit, $game, $default_game );
	}

	/**
	 * Ключи опубликованных записей: ID записи → ключ и признак пароля, новые записи первыми.
	 *
	 * Читаются одним запросом на весь PHP-запрос, а не на каждую запись: импорт и пересчёт оценки
	 * анализируют сотни записей подряд, и чтение тысяч ключей на каждую делало пачку квадратичной.
	 * Новые ключи, записанные в этом же запросе, дописываются в список хуками меты; сохранение записи
	 * (смена статуса или пароля) сбрасывает список.
	 *
	 * @return array<int, array{keyword: string, protected: bool, game: string}>
	 */
	private function known(): array {
		if ( null !== self::$known ) {
			return self::$known;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- три столбца по индексу ключа меты; один раз на запрос.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT m.post_id, m.meta_value, p.post_password, g.meta_value AS game FROM {$this->db->postmeta} m JOIN {$this->db->posts} p ON p.ID = m.post_id
				LEFT JOIN {$this->db->postmeta} g ON g.post_id = m.post_id AND g.meta_key = %s
				WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_status = 'publish'
				ORDER BY m.post_id DESC LIMIT %d",
				SiteGames::META,
				MetaKeys::FOCUS_KEYWORD,
				self::SCAN_LIMIT + 1
			),
			ARRAY_A
		);

		self::$known = array();

		foreach ( (array) $rows as $row ) {
			self::$known[ (int) $row['post_id'] ] ??= array(
				'keyword'   => (string) $row['meta_value'],
				'protected' => '' !== (string) $row['post_password'],
				'game'      => (string) ( $row['game'] ?? '' ),
			);
		}

		if ( ! self::$hooked && function_exists( 'add_action' ) ) {
			self::$hooked = true;

			add_action( 'added_post_meta', array( self::class, 'on_meta' ), 10, 4 );
			add_action( 'updated_post_meta', array( self::class, 'on_meta' ), 10, 4 );
			add_action( 'deleted_post_meta', array( self::class, 'on_meta_deleted' ), 10, 3 );
			add_action( 'save_post', array( self::class, 'forget' ) );
			add_action( 'deleted_post', array( self::class, 'forget' ) );
		}

		return self::$known;
	}

	/**
	 * Дописывает в список ключ, записанный в этом запросе.
	 *
	 * @param int    $meta_id    ID меты.
	 * @param int    $post_id    ID записи.
	 * @param string $meta_key   Ключ меты.
	 * @param mixed  $meta_value Значение.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id );

		if ( null === self::$known ) {
			return;
		}

		if ( SiteGames::META === $meta_key ) {
			if ( isset( self::$known[ (int) $post_id ] ) ) {
				self::$known[ (int) $post_id ]['game'] = is_scalar( $meta_value ) ? (string) $meta_value : '';
			}

			return;
		}

		if ( MetaKeys::FOCUS_KEYWORD !== $meta_key ) {
			return;
		}

		$post = get_post( (int) $post_id );

		self::$known = self::patch(
			self::$known,
			(int) $post_id,
			$post instanceof \WP_Post && 'publish' === $post->post_status && is_scalar( $meta_value ) ? (string) $meta_value : '',
			$post instanceof \WP_Post && '' !== $post->post_password,
			(string) get_post_meta( (int) $post_id, SiteGames::META, true )
		);
	}

	/**
	 * Убирает из списка удалённый ключ.
	 *
	 * @param mixed  $meta_ids ID удалённой меты.
	 * @param int    $post_id  ID записи.
	 * @param string $meta_key Ключ меты.
	 */
	public static function on_meta_deleted( $meta_ids, $post_id, $meta_key ): void {
		unset( $meta_ids );

		if ( MetaKeys::FOCUS_KEYWORD === $meta_key && null !== self::$known ) {
			self::$known = self::patch( self::$known, (int) $post_id, '', false );
		}

		if ( SiteGames::META === $meta_key && isset( self::$known[ (int) $post_id ] ) ) {
			self::$known[ (int) $post_id ]['game'] = '';
		}
	}

	/**
	 * Сбрасывает список: он перечитается при следующем анализе.
	 */
	public static function forget(): void {
		self::$known = null;
	}

	/**
	 * Список с изменённым ключом одной записи; пустой ключ убирает запись из списка.
	 *
	 * @param array<int, array{keyword: string, protected: bool, game: string}> $known     Список.
	 * @param int                                                 $post_id   ID записи.
	 * @param string                                              $keyword   Ключ; пустой — записи нет.
	 * @param bool                                                $locked    Запись под паролем.
	 * @param string                                              $game      Игра записи; пусто — игра по умолчанию.
	 * @return array<int, array{keyword: string, protected: bool, game: string}>
	 */
	public static function patch( array $known, int $post_id, string $keyword, bool $locked, string $game = '' ): array {
		unset( $known[ $post_id ] );

		if ( '' !== trim( $keyword ) ) {
			$known[ $post_id ] = array(
				'keyword'   => $keyword,
				'protected' => $locked,
				'game'      => $game,
			);
		}

		krsort( $known, SORT_NUMERIC );

		return $known;
	}

	/**
	 * Строки для сравнения: без исключённой записи, без закрытых паролем (если нужно), не больше SCAN_LIMIT.
	 *
	 * @param array<int, array{keyword: string, protected: bool, game: string}> $known       Список, новые записи первыми.
	 * @param int                                                 $exclude     ID записи, которую не учитывать.
	 * @param bool                                                $public_only Пропускать записи под паролем.
	 * @return list<array{post_id: int, meta_value: string, game: string}>
	 */
	public static function candidates( array $known, int $exclude, bool $public_only ): array {
		$rows = array();

		foreach ( $known as $post_id => $item ) {
			if ( $post_id === $exclude || ( $public_only && $item['protected'] ) ) {
				continue;
			}

			$rows[] = array(
				'post_id'    => (int) $post_id,
				'meta_value' => $item['keyword'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- строка уже прочитанного списка, а не запрос.
				'game'       => $item['game'] ?? '',
			);

			if ( count( $rows ) >= self::SCAN_LIMIT ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Отбирает строки с тем же ключом.
	 *
	 * @param array<int, mixed> $rows    Строки: post_id, meta_value, game.
	 * @param string            $keyword Основной ключ.
	 * @param KeywordMatcher    $matcher Сравнение ключей.
	 * @param int               $limit   Сколько записей вернуть.
	 * @param string            $game    Игра: строки других игр пропускаются; пусто — все игры.
	 * @param string            $default_game Игра строк без отметки игры.
	 * @return list<int>
	 */
	public static function matching( array $rows, string $keyword, KeywordMatcher $matcher, int $limit, string $game = '', string $default_game = '' ): array {
		$found = array();
		$known = array();

		foreach ( $rows as $row ) {
			$other = is_array( $row ) ? trim( (string) ( $row['meta_value'] ?? '' ) ) : '';

			if ( '' === $other ) {
				continue;
			}

			// «Гайд на жреца» в World of Warcraft не занимает тот же ключ в Hearthstone.
			$row_game = '' !== (string) ( $row['game'] ?? '' ) ? (string) $row['game'] : $default_game;

			if ( '' !== $game && $row_game !== $game ) {
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
