<?php
/**
 * Запрос к модели в формате OpenAI Chat Completions.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- класс работает и без WordPress; текст исключения выводится через esc_html.

namespace IndexPilot\Ai\Writer;

use IndexPilot\SearchData\Http;

/**
 * Один запрос к /chat/completions. Формат одинаковый у OpenAI API и OpenRouter: меняются только адрес и ключ.
 * Ответ просится в виде JSON; модели, которые такой режим не принимают, получают повтор запроса без него.
 */
final class ChatClient {

	/**
	 * Конструктор.
	 *
	 * @param string                $base    Адрес API без косой черты в конце: https://api.openai.com/v1.
	 * @param string                $key     Ключ API.
	 * @param string                $model   Модель.
	 * @param Http                  $http    Сеть.
	 * @param array<string, string> $headers Дополнительные заголовки.
	 */
	public function __construct(
		private string $base,
		private string $key,
		private string $model,
		private Http $http,
		private array $headers = array()
	) {
	}

	/**
	 * Текст ответа модели.
	 *
	 * @param list<array<string, mixed>> $messages Сообщения.
	 * @throws WriterException Сервис отказал или не ответил.
	 */
	public function complete( array $messages ): string {
		$body = array(
			'model'           => $this->model,
			'messages'        => $messages,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = $this->post( $body );

		if ( 400 === $response['code'] ) {
			unset( $body['response_format'] );
			$response = $this->post( $body );
		}

		if ( 200 !== $response['code'] ) {
			throw new WriterException( self::error( $response['code'], $response['body'], $this->model ) );
		}

		$data    = json_decode( $response['body'], true );
		$content = is_array( $data ) ? ( $data['choices'][0]['message']['content'] ?? null ) : null;

		if ( is_array( $content ) ) {
			$content = implode( '', array_map( static fn( $part ): string => is_array( $part ) ? (string) ( $part['text'] ?? '' ) : '', $content ) );
		}

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			throw new WriterException( 'Модель вернула пустой ответ. Попробуйте ещё раз или выберите другую модель.' );
		}

		return $content;
	}

	/**
	 * Проверка ключа: список моделей открывается только с верным ключом.
	 *
	 * @throws WriterException Ключ не подошёл.
	 */
	public function check(): void {
		$response = $this->http->request( 'GET', $this->base . '/models', $this->auth(), '' );

		if ( 200 !== $response['code'] ) {
			throw new WriterException( self::error( $response['code'], $response['body'], $this->model ) );
		}
	}

	/**
	 * Понятный текст ошибки по ответу сервиса.
	 *
	 * @param int    $code  Код ответа; 0 — сервис не ответил.
	 * @param string $body  Тело ответа.
	 * @param string $model Модель.
	 */
	public static function error( int $code, string $body, string $model ): string {
		$data    = json_decode( $body, true );
		$message = is_array( $data ) ? ( $data['error']['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : '' ) ) : '';
		$message = is_string( $message ) ? trim( mb_substr( $message, 0, 300 ) ) : '';

		return match ( true ) {
			0 === $code   => 'Сервис ИИ не ответил вовремя. Повторите через минуту.',
			401 === $code => 'Ключ API не подошёл. Проверьте его на экране «ИИ-помощник».',
			402 === $code => 'На счёте сервиса ИИ закончились деньги. Пополните баланс и повторите.',
			403 === $code => 'Сервис ИИ отказал в доступе' . ( '' !== $message ? ': ' . $message : '.' ),
			404 === $code => sprintf( 'Модель «%s» не найдена. Проверьте название на экране «ИИ-помощник».', $model ),
			429 === $code => 'Сервис ИИ просит подождать: слишком много запросов или исчерпан лимит счёта. Повторите позже.',
			default       => sprintf( 'Сервис ИИ ответил ошибкой %d', $code ) . ( '' !== $message ? ': ' . $message : '.' ),
		};
	}

	/**
	 * POST к /chat/completions.
	 *
	 * @param array<string, mixed> $body Тело.
	 * @return array{code: int, body: string}
	 */
	private function post( array $body ): array {
		$response = $this->http->request(
			'POST',
			$this->base . '/chat/completions',
			array_merge( $this->auth(), array( 'Content-Type' => 'application/json' ) ),
			(string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		return array(
			'code' => (int) ( $response['code'] ?? 0 ),
			'body' => (string) ( $response['body'] ?? '' ),
		);
	}

	/**
	 * Заголовки с ключом.
	 *
	 * @return array<string, string>
	 */
	private function auth(): array {
		return array_merge( array( 'Authorization' => 'Bearer ' . $this->key ), $this->headers );
	}
}
