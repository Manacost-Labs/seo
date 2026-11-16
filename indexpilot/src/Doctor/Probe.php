<?php
/**
 * Замер нагрузки на одной странице.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Doctor;

/**
 * Включает учёт запросов к базе на странице, открытой командой «wp indexpilot doctor», и сохраняет сводку.
 *
 * На обычных запросах посетителей класс не делает ничего: без подписанного одноразового параметра
 * в адресе он выходит сразу, не обращаясь ни к базе, ни к настройкам.
 */
final class Probe {

	public const PARAM = 'indexpilot_doctor';

	/**
	 * Опция с состоянием текущего замера: идентификатор, стадия и результат. Одна строка на все замеры.
	 */
	public const OPTION = 'indexpilot_doctor_probe';

	/**
	 * Конструктор.
	 *
	 * @param string $id Идентификатор замера.
	 */
	private function __construct( private string $id ) {
	}

	/**
	 * Начинает замер, если страница открыта с действующим параметром. Вызывается при запуске плагина.
	 */
	public static function maybe_start(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение сверяется с подписью ключом сайта и больше нигде не используется.
		$token = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? (string) wp_unslash( $_GET[ self::PARAM ] ) : '';

		if ( '' === $token ) {
			return;
		}

		$id = Token::verify( $token, time(), self::secret() );

		if ( null === $id ) {
			return;
		}

		// Параметр одноразовый: замер ждёт именно этого идентификатора и ещё не начат.
		$slot = get_option( self::OPTION );

		if ( ! is_array( $slot ) || ! hash_equals( $id, (string) ( $slot['id'] ?? '' ) ) || 'pending' !== ( $slot['state'] ?? '' ) ) {
			return;
		}

		if ( defined( 'SAVEQUERIES' ) && ! SAVEQUERIES ) {
			self::save( $id, 'done', array( 'error' => 'savequeries_off' ) );

			return;
		}

		self::save( $id, 'running' );

		if ( ! defined( 'SAVEQUERIES' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- константа WordPress: включает список запросов только для этого запроса страницы.
			define( 'SAVEQUERIES', true );
		}

		// Сам текст страницы замеру не нужен: страница собирается целиком, но в ответ не отправляется.
		ob_start( static fn(): string => '' );

		add_action( 'shutdown', array( new self( $id ), 'finish' ), PHP_INT_MAX );
	}

	/**
	 * Сохраняет сводку по запросам страницы. Вызывается в самом конце запроса.
	 */
	public function finish(): void {
		global $wpdb;

		$summary = QueryLog::summarize( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ? $wpdb->queries : array() );

		$summary['queries'] = array_slice( $summary['queries'], 0, 50 );
		$summary['page_ms'] = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 1 ) : 0.0;
		$summary['admin']   = is_admin();

		self::save( $this->id, 'done', $summary );
	}

	/**
	 * Ключ, которым подписывается параметр.
	 */
	public static function secret(): string {
		return wp_salt( 'nonce' );
	}

	/**
	 * Записывает состояние замера.
	 *
	 * @param string               $id     Идентификатор замера.
	 * @param string               $state  Стадия: pending, running или done.
	 * @param array<string, mixed> $result Результат.
	 */
	public static function save( string $id, string $state, array $result = array() ): void {
		update_option(
			self::OPTION,
			array(
				'id'     => $id,
				'state'  => $state,
				'result' => $result,
			),
			false
		);
	}
}
