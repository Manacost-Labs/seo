<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Ai;

use IndexPilot\Ai\Writer\ChatClient;
use IndexPilot\Ai\Writer\Draft;
use IndexPilot\Ai\Writer\WriterException;
use IndexPilot\SearchData\Http;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {

	private function http( callable $callback ): Http {
		return new class( $callback ) implements Http {
			/** @var callable */
			private $callback;

			public function __construct( callable $callback ) {
				$this->callback = $callback;
			}

			public function request( string $method, string $url, array $headers, string $body ): array {
				return ( $this->callback )( $method, $url, $headers, $body );
			}
		};
	}

	public function test_images_needing_alt_are_found_once(): void {
		$html = '<p><img class="alignnone wp-image-12 size-large" src="https://s.test/a-1024x576.jpg" alt=""></p>'
			. '<img src="https://s.test/b.png" alt="Колода Берн мага">'
			. '<img src="https://s.test/a-1024x576.jpg">'
			. "<img src='https://s.test/screenshot_3.png' alt='screenshot_3'>"
			. '<img src="https://s.test/c.png" alt="IMG 2">'
			. '<img src="data:image/gif;base64,R0lGOD">';

		$images = Draft::images( $html );
		self::assertCount( 5, $images );
		self::assertSame( 12, $images[0]['id'] );

		$need = Draft::without_alt( $images );
		self::assertSame( array( 'https://s.test/a-1024x576.jpg', 'https://s.test/screenshot_3.png', 'https://s.test/c.png' ), array_column( $need, 'src' ), 'Свой alt остаётся, имя файла вместо alt — нет, повтор адреса — один раз.' );
	}

	public function test_messages_carry_rules_text_and_images(): void {
		$messages = Draft::messages(
			array(
				'site'            => 'Manacost',
				'about'           => 'Hearthstone',
				'title'           => 'Гайд на Берн мага',
				'text'            => 'Текст статьи про мага.',
				'description_min' => 120,
				'description_max' => 155,
			),
			array( 'data:image/png;base64,AAA' )
		);

		self::assertSame( 'system', $messages[0]['role'] );
		self::assertStringContainsString( 'не выдумываешь', $messages[0]['content'] );
		$text = $messages[1]['content'][0]['text'];
		self::assertStringContainsString( '120–155 знаков', $text );
		self::assertStringContainsString( 'Гайд на Берн мага', $text );
		self::assertStringContainsString( '"alts"', $text );
		self::assertSame( 'image_url', $messages[1]['content'][2]['type'] );
		self::assertSame( 'data:image/png;base64,AAA', $messages[1]['content'][2]['image_url']['url'] );
	}

	public function test_answer_is_normalized(): void {
		$raw = "Вот ответ:\n```json\n" . json_encode(
			array(
				'focus_keyword' => 'Гайд на Берн мага',
				'keywords'      => array( 'гайд на берн мага', 'колода, берн мага', 'Burn Mage', 'burn mage', 'а', 'б', 'в' ),
				'description'   => '«' . str_repeat( 'Берн маг наносит урон за ход ', 10 ) . '»',
				'answer'        => "Берн маг —\n<b>агро</b>.",
				'faq'           => array(
					array(
						'question' => 'Какие карты?',
						'answer'   => 'Огненный шар.',
					),
					array( 'question' => 'Пусто' ),
				),
				'alts'          => array(
					array(
						'image' => 1,
						'alt'   => 'Колода "Берн" мага',
					),
					array(
						'image' => 7,
						'alt'   => 'лишняя',
					),
				),
			),
			JSON_UNESCAPED_UNICODE
		) . "\n```";

		$result = Draft::parse( $raw, 2, 155 );

		self::assertSame( 'гайд на берн мага', $result['focus_keyword'] );
		self::assertSame( array( 'колода берн мага', 'burn mage', 'а', 'б' ), $result['keywords'], 'Без повтора главного ключа и дублей, запятые убраны, не больше четырёх.' );
		self::assertLessThanOrEqual( 155, mb_strlen( $result['description'] ) );
		self::assertStringEndsWith( '…', $result['description'] );
		self::assertStringStartsNotWith( '«', $result['description'] );
		self::assertSame( 'Берн маг — агро.', $result['answer'] );
		self::assertCount( 1, $result['faq'] );
		self::assertSame( array( 0 => 'Колода Берн мага' ), $result['alts'] );
	}

	public function test_focus_keyword_comes_from_title(): void {
		$raw    = (string) json_encode(
			array(
				'focus_keyword' => 'колода берн мага',
				'keywords'      => array( 'берн маг hearthstone', 'Гайд на берн мага' ),
			)
		);
		$result = Draft::parse( $raw, 0, 160, 'Масса урона за один ход: гайд на Берн Мага от TrueBench' );

		self::assertSame( 'гайд на берн мага', $result['focus_keyword'], 'Ключа нет в заголовке, а дополнительный там есть — они меняются местами.' );
		self::assertContains( 'колода берн мага', $result['keywords'] );
		self::assertTrue( Draft::in_title( 'гайды на берн магов', 'Гайд на Берн Мага' ) );
		self::assertFalse( Draft::in_title( 'колода мага', 'Гайд на Берн Мага' ) );
		self::assertSame( 'колода берн мага', Draft::parse( $raw, 0, 160, 'Колода Берн мага' )['focus_keyword'] );
	}

	public function test_broken_answer_is_an_error(): void {
		$this->expectException( WriterException::class );
		Draft::parse( 'Извините, не могу.', 0, 160 );
	}

	public function test_client_asks_json_and_retries_without_format(): void {
		$bodies = array();
		$client = new ChatClient(
			'https://api.test/v1',
			'sk-1',
			'm',
			$this->http(
				function ( $method, $url, $headers, $body ) use ( &$bodies ) {
					$bodies[] = json_decode( $body, true );
					self::assertSame( 'https://api.test/v1/chat/completions', $url );
					self::assertSame( 'Bearer sk-1', $headers['Authorization'] );

					return 1 === count( $bodies )
						? array(
							'code' => 400,
							'body' => '{"error":{"message":"response_format unsupported"}}',
						)
						: array(
							'code' => 200,
							'body' => '{"choices":[{"message":{"content":"{\"a\":1}"}}]}',
						);
				}
			)
		);

		self::assertSame( '{"a":1}', $client->complete( array( array( 'role' => 'user', 'content' => 'x' ) ) ) );
		self::assertArrayHasKey( 'response_format', $bodies[0] );
		self::assertArrayNotHasKey( 'response_format', $bodies[1] );
	}

	public function test_errors_are_readable(): void {
		self::assertStringContainsString( 'Ключ API не подошёл', ChatClient::error( 401, '', 'm' ) );
		self::assertStringContainsString( 'закончились деньги', ChatClient::error( 402, '', 'm' ) );
		self::assertStringContainsString( '«gpt-x» не найдена', ChatClient::error( 404, '', 'gpt-x' ) );
		self::assertStringContainsString( 'Codex не вошёл', ChatClient::error( 503, '{"error":{"message":"Codex не вошёл в ChatGPT"}}', 'm' ) );
		self::assertStringContainsString( 'не ответил', ChatClient::error( 0, '', 'm' ) );
	}
}
