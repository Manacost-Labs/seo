<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Promo;

use IndexPilot\Promo\Blocks;
use IndexPilot\Promo\FollowBlock;
use PHPUnit\Framework\TestCase;

final class FollowBlockTest extends TestCase {

	private const LABELS = array(
		'title'    => 'Мы в соцсетях',
		'share'    => 'Поделиться',
		'telegram' => 'Telegram',
		'vk'       => 'ВКонтакте',
	);

	public function test_block_lists_only_social_destinations_through_short_codes(): void {
		$html = FollowBlock::html(
			array(
				$this->destination( 'Boosty', 'boosty', 'paid', 'https://boosty.to/manacost' ),
				$this->destination( 'Telegram', 'tg', 'social', 'https://t.me/manacost' ),
				$this->destination( 'kolodahearthstone.com', 'koloda', 'own', 'https://kolodahearthstone.com/' ),
				$this->destination( 'ВКонтакте', 'vk', 'social', 'https://vk.com/manacost' ),
			),
			static fn( string $code ): string => 'https://hs-manacost.ru/to/' . $code . '/15-0-0',
			'',
			'',
			self::LABELS
		);

		self::assertStringContainsString( '<a href="https://hs-manacost.ru/to/tg/15-0-0" rel="nofollow">Telegram</a>', $html );
		self::assertStringContainsString( '<a href="https://hs-manacost.ru/to/vk/15-0-0" rel="nofollow">ВКонтакте</a>', $html );
		self::assertStringNotContainsString( 'boosty', $html );
		self::assertStringNotContainsString( 'koloda', $html );
		// Прямых адресов площадок в блоке нет: иначе переход не будет засчитан.
		self::assertStringNotContainsString( 't.me/manacost', $html );
		self::assertStringNotContainsString( 'vk.com/manacost', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	public function test_share_links_are_plain_links(): void {
		$html = FollowBlock::html( array(), static fn( string $code ): string => $code, 'https://hs-manacost.ru/gajd/?a=1&b=2', 'Гайд «Маг» & Ко', self::LABELS );

		self::assertStringContainsString( 'href="https://t.me/share/url?url=https%3A%2F%2Fhs-manacost.ru%2Fgajd%2F%3Fa%3D1%26b%3D2&amp;text=', $html );
		self::assertStringContainsString( 'href="https://vk.com/share.php?url=https%3A%2F%2Fhs-manacost.ru%2Fgajd%2F%3Fa%3D1%26b%3D2"', $html );
		self::assertStringNotContainsString( 'Мы в соцсетях', $html );
	}

	public function test_nothing_to_show_gives_empty_string(): void {
		self::assertSame( '', FollowBlock::html( array( $this->destination( 'Boosty', 'boosty', 'paid', 'https://boosty.to/a' ) ), static fn( string $code ): string => $code, '', '', self::LABELS ) );
	}

	public function test_promo_block_escapes_text_and_has_no_scripts(): void {
		$html = Blocks::html( 'Разборы <b>меты</b> & колод', 'На Boosty "сейчас"', 'https://hs-manacost.ru/to/boosty/15-3-0' );

		self::assertStringStartsWith( '<aside class="indexpilot-promo"', $html );
		self::assertStringContainsString( 'Разборы &lt;b&gt;меты&lt;/b&gt; &amp; колод', $html );
		self::assertStringContainsString( 'href="https://hs-manacost.ru/to/boosty/15-3-0"', $html );
		self::assertStringContainsString( 'На Boosty &quot;сейчас&quot;</a>', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/**
	 * Площадка в том виде, в каком её отдаёт справочник.
	 *
	 * @return array{name: string, code: string, kind: string, url: string}
	 */
	private function destination( string $name, string $code, string $kind, string $url ): array {
		return compact( 'name', 'code', 'kind', 'url' );
	}
}
