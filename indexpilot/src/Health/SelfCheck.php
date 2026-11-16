<?php
/**
 * Самопроверка сайта: адреса из карты сайта запрашиваются с самого сайта.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Health;

/**
 * Обходит адреса пачками, не чаще одного запроса в секунду, и запоминает место остановки.
 * Отмечает: ответ не 200, неожиданное перенаправление, слишком долгий ответ, почти пустую страницу
 * и архив, где тема выводит записи целиком вместо выдержек.
 */
final class SelfCheck {

	public const STATE = 'self_check';
	public const URLS  = 'self_check_urls';
	public const KIND  = 'page';

	/**
	 * Меньше стольких слов в основном тексте — страница почти пустая.
	 */
	public const EMPTY_WORDS = 50;

	/**
	 * Дольше стольких миллисекунд — ответ слишком долгий.
	 */
	public const SLOW_MS = 3000;

	/**
	 * Пауза между запросами, секунд.
	 */
	private const GAP = 1.0;

	/**
	 * Запись короче — её выдержка и полный текст совпадают, по ней не судим.
	 */
	private const FULL_TEXT_MIN_WORDS = 80;

	/**
	 * Сколько последних слов записи ищется в тексте архива.
	 */
	private const TAIL_WORDS = 12;

	/**
	 * @var callable(string): array{status: int, location: string, ms: int, body: string}
	 */
	private $fetch;

	/**
	 * @var callable(string, string): list<string>
	 */
	private $post_texts;

	/**
	 * @var callable(float): void
	 */
	private $sleep;

	/**
	 * @var callable(): float
	 */
	private $clock;

	/**
	 * @var callable(): int
	 */
	private $now;

	/**
	 * Время начала последнего запроса.
	 *
	 * @var float|null
	 */
	private ?float $last = null;

	/**
	 * @param Store                                                                  $store      Хранилище результатов.
	 * @param callable(string): array{status: int, location: string, ms: int, body: string} $fetch Запрос адреса.
	 * @param callable(string, string): list<string>                                 $post_texts Тексты записей, на которые ссылается архив (HTML архива, адрес).
	 * @param callable(float): void                                                  $sleep      Пауза в секундах.
	 * @param callable(): float                                                      $clock      Точное время для пауз.
	 * @param callable(): int                                                        $now        Текущее время для записи результатов.
	 */
	public function __construct( private Store $store, callable $fetch, callable $post_texts, callable $sleep, callable $clock, callable $now ) {
		$this->fetch      = $fetch;
		$this->post_texts = $post_texts;
		$this->sleep      = $sleep;
		$this->clock      = $clock;
		$this->now        = $now;
	}

	/**
	 * Начинает новый обход.
	 *
	 * @param list<array{url: string, archive?: bool}> $urls Адреса из карты сайта; archive — архив рубрики или автора.
	 */
	public function start( array $urls ): void {
		// Список адресов записывается один раз, а после каждого адреса сохраняется только короткое место остановки.
		$this->store->save_state( self::URLS, array_values( $urls ) );
		$this->store->save_state(
			self::STATE,
			array(
				'cursor'  => 0,
				'total'   => count( $urls ),
				'started' => ( $this->now )(),
				'done'    => array() === $urls,
			)
		);
	}

	/**
	 * Состояние обхода.
	 *
	 * @return array{cursor: int, total: int, started: int, done: bool}
	 */
	public function state(): array {
		$state = $this->store->state( self::STATE );

		return array(
			'cursor'  => (int) ( $state['cursor'] ?? 0 ),
			'total'   => (int) ( $state['total'] ?? 0 ),
			'started' => (int) ( $state['started'] ?? 0 ),
			'done'    => (bool) ( $state['done'] ?? true ),
		);
	}

	/**
	 * Проверяет очередную пачку адресов с места прошлой остановки.
	 *
	 * @param int $limit Сколько адресов проверить.
	 * @return array{checked: int, done: bool, cursor: int, total: int}
	 */
	public function run_batch( int $limit ): array {
		$state   = $this->state();
		$urls    = $state['done'] ? array() : $this->store->state( self::URLS );
		$total   = count( $urls );
		$checked = 0;

		while ( ! $state['done'] && $checked < $limit && $state['cursor'] < $total ) {
			$item = (array) $urls[ $state['cursor'] ];

			$this->check( (string) $item['url'], ! empty( $item['archive'] ) );

			++$state['cursor'];
			++$checked;

			// Место остановки запоминается после каждого адреса: если задачу прервут, обход продолжится со следующего.
			$this->store->save_state( self::STATE, $state );
		}

		if ( ! $state['done'] && $state['cursor'] >= $total ) {
			$state['done'] = true;
			$this->store->save_state( self::STATE, $state );
			// Адреса, которых больше нет в карте сайта, из отчёта убираются.
			$this->store->remove_older( self::KIND, $state['started'] );
		}

		return array(
			'checked' => $checked,
			'done'    => $state['done'],
			'cursor'  => $state['cursor'],
			'total'   => $state['total'],
		);
	}

	/**
	 * Проблемы страницы по ответу.
	 *
	 * @param array{status: int, location?: string, ms?: int, body?: string} $response   Ответ.
	 * @param bool                                                           $archive    Это архив рубрики или автора.
	 * @param list<string>                                                   $post_texts Тексты записей архива.
	 * @return array{problems: list<string>, words: int}
	 */
	public static function evaluate( array $response, bool $archive = false, array $post_texts = array() ): array {
		$status = (int) $response['status'];
		$text   = self::text( (string) ( $response['body'] ?? '' ) );
		$words  = self::count_words( $text );
		$found  = array();

		if ( $status >= 300 && $status < 400 ) {
			$found[] = 'redirect';
		} elseif ( 200 !== $status ) {
			$found[] = 'status';
		}

		if ( (int) ( $response['ms'] ?? 0 ) > self::SLOW_MS ) {
			$found[] = 'slow';
		}

		if ( 200 === $status && $words < self::EMPTY_WORDS ) {
			$found[] = 'empty';
		}

		if ( 200 === $status && $archive && self::full_texts( $text, $post_texts ) >= 2 ) {
			$found[] = 'full_text';
		}

		return array(
			'problems' => $found,
			'words'    => $words,
		);
	}

	/**
	 * Основной текст страницы: содержимое <main> (или <body>) без скриптов, стилей, меню, шапки и подвала.
	 *
	 * @param string $html Разметка страницы.
	 */
	public static function text( string $html ): string {
		$html = (string) preg_replace( '#<[^>]+>#u', ' ', self::main_html( $html ) );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $html ) );
	}

	/**
	 * Разметка основной части страницы: <main> (или <body>) без скриптов, стилей, меню, шапки и подвала.
	 *
	 * @param string $html Разметка страницы.
	 */
	public static function main_html( string $html ): string {
		if ( 1 === preg_match( '#<main\b[^>]*>(.*)</main>#isu', $html, $match ) || 1 === preg_match( '#<body\b[^>]*>(.*)</body>#isu', $html, $match ) ) {
			$html = $match[1];
		}

		return (string) preg_replace( '#<(script|style|noscript|svg|nav|header|footer|aside|form|template)\b[^>]*>.*?</\1>#isu', ' ', $html );
	}

	/**
	 * Сколько записей архива выведено целиком: в тексте архива есть последние слова записи.
	 *
	 * @param string       $archive_text Текст архива (см. text()).
	 * @param list<string> $post_texts   Тексты записей.
	 */
	public static function full_texts( string $archive_text, array $post_texts ): int {
		$haystack = ' ' . implode( ' ', self::tokens( $archive_text ) ) . ' ';
		$count    = 0;

		foreach ( $post_texts as $post_text ) {
			$tokens = self::tokens( self::text( '<body>' . $post_text . '</body>' ) );

			if ( count( $tokens ) < self::FULL_TEXT_MIN_WORDS ) {
				continue;
			}

			if ( str_contains( $haystack, ' ' . implode( ' ', array_slice( $tokens, -self::TAIL_WORDS ) ) . ' ' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Проверяет один адрес и сохраняет результат.
	 *
	 * @param string $url     Адрес.
	 * @param bool   $archive Это архив.
	 */
	private function check( string $url, bool $archive ): void {
		$this->wait();

		$response = ( $this->fetch )( $url );
		$texts    = $archive && 200 === (int) $response['status'] ? ( $this->post_texts )( (string) ( $response['body'] ?? '' ), $url ) : array();
		$result   = self::evaluate( $response, $archive, $texts );

		$this->store->save(
			self::KIND,
			$url,
			array(
				'status'   => (int) $response['status'],
				'problems' => implode( ',', $result['problems'] ),
				'ms'       => (int) ( $response['ms'] ?? 0 ),
				'words'    => $result['words'],
				'checked'  => ( $this->now )(),
			)
		);
	}

	/**
	 * Выдерживает паузу: от начала прошлого запроса проходит не меньше секунды.
	 */
	private function wait(): void {
		$now = ( $this->clock )();

		if ( null !== $this->last && $now - $this->last < self::GAP ) {
			( $this->sleep )( self::GAP - ( $now - $this->last ) );
		}

		$this->last = ( $this->clock )();
	}

	/**
	 * Число слов в тексте.
	 *
	 * @param string $text Текст.
	 */
	private static function count_words( string $text ): int {
		return (int) preg_match_all( '/[\p{L}\p{N}]+/u', $text );
	}

	/**
	 * Слова текста в нижнем регистре.
	 *
	 * @param string $text Текст.
	 * @return list<string>
	 */
	private static function tokens( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $text ), $matches );

		return $matches[0];
	}
}
