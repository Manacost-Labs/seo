<?php
/**
 * Подключение ИИ-помощника: сервис, ключ API и модель.
 *
 * @package IndexPilot
 */

declare( strict_types=1 );

namespace IndexPilot\Ai\Writer;

use IndexPilot\SearchData\Http;
use IndexPilot\SearchData\WpHttp;

/**
 * Хранит сервис, ключ и модель в отдельной опции без автозагрузки: на страницах сайта она не читается.
 * Ключ на экран не выводится — только отметка «ключ сохранён».
 */
final class Account {

	public const OPTION = 'indexpilot_ai_writer';

	/**
	 * Сервисы: название, адрес API, модель по умолчанию, где взять ключ.
	 */
	public const PROVIDERS = array(
		'chatgpt'    => array(
			'label' => 'ChatGPT по подписке (мост Codex на сервере)',
			'base'  => 'http://127.0.0.1:8787/v1',
			'model' => 'default',
			'keys'  => '',
		),
		'openai'     => array(
			'label' => 'OpenAI API',
			'base'  => 'https://api.openai.com/v1',
			'model' => 'gpt-6.1-terra',
			'keys'  => 'https://platform.openai.com/api-keys',
		),
		'openrouter' => array(
			'label' => 'OpenRouter',
			'base'  => 'https://openrouter.ai/api/v1',
			'model' => 'openai/gpt-6.1-terra',
			'keys'  => 'https://openrouter.ai/settings/keys',
		),
	);

	/**
	 * Сколько секунд ждать ответа модели: с картинками ответ собирается до минуты.
	 */
	public const TIMEOUT = 120;

	/**
	 * Прочитанные значения.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $data = null;

	/**
	 * Сервис.
	 */
	public function provider(): string {
		$provider = $this->get( 'provider' );

		return isset( self::PROVIDERS[ $provider ] ) ? $provider : 'chatgpt';
	}

	/**
	 * Модель; по умолчанию — модель сервиса.
	 */
	public function model(): string {
		$model = $this->get( 'model' );

		return '' !== $model ? $model : self::PROVIDERS[ $this->provider() ]['model'];
	}

	/**
	 * Сохранён ли ключ.
	 */
	public function has_key(): bool {
		return '' !== $this->get( 'key' );
	}

	/**
	 * Сохраняет сервис, модель и — если передан — новый ключ.
	 *
	 * @param string $provider Сервис.
	 * @param string $model    Модель; пусто — по умолчанию.
	 * @param string $key      Новый ключ; пусто — оставить прежний.
	 */
	public function save( string $provider, string $model, string $key ): void {
		$this->get( 'provider' );
		$this->data['provider'] = isset( self::PROVIDERS[ $provider ] ) ? $provider : 'chatgpt';
		$this->data['model']    = (string) preg_replace( '/[^A-Za-z0-9_.:\/\-]/', '', $model );

		if ( '' !== $key ) {
			$this->data['key'] = (string) preg_replace( '/[^\x21-\x7E]/', '', $key );
		}

		$this->write();
	}

	/**
	 * Удаляет ключ.
	 */
	public function remove_key(): void {
		$this->get( 'key' );
		unset( $this->data['key'] );
		$this->write();
	}

	/**
	 * Клиент модели.
	 *
	 * @param Http|null $http Сеть; null — запросы WordPress.
	 * @throws WriterException Ключ не сохранён.
	 */
	public function client( ?Http $http = null ): ChatClient {
		if ( ! $this->has_key() ) {
			throw new WriterException( 'ИИ-помощник не подключён: впишите ключ API в IndexPilot → Техника → ИИ-помощник.' );
		}

		$provider = $this->provider();
		$headers  = 'openrouter' === $provider
			? array(
				'HTTP-Referer' => home_url( '/' ),
				'X-Title'      => 'IndexPilot',
			)
			: array();

		// Мост к ChatGPT работает на этом же сервере: адрес 127.0.0.1 обычный безопасный запрос WordPress не пропускает.
		$local = 'chatgpt' === $provider;
		$base  = $local && defined( 'INDEXPILOT_AI_BRIDGE' ) ? (string) constant( 'INDEXPILOT_AI_BRIDGE' ) : self::PROVIDERS[ $provider ]['base'];

		return new ChatClient( rtrim( $base, '/' ), $this->get( 'key' ), $this->model(), $http ?? new WpHttp( self::TIMEOUT, ! $local ), $headers );
	}

	/**
	 * Значение по имени.
	 *
	 * @param string $name provider, model или key.
	 */
	private function get( string $name ): string {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$this->data = is_array( $stored ) ? array_map( 'strval', array_filter( $stored, 'is_scalar' ) ) : array();
		}

		return (string) ( $this->data[ $name ] ?? '' );
	}

	/**
	 * Записывает опцию без автозагрузки.
	 */
	private function write(): void {
		$data = array_filter( (array) $this->data, 'strlen' );

		if ( array() === $data ) {
			delete_option( self::OPTION );
			return;
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $data, '', false );
		} else {
			update_option( self::OPTION, $data, false );
		}
	}
}
