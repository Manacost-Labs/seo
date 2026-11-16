<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Authors;

use IndexPilot\Authors\AuthorData;
use IndexPilot\Meta\MetaKeys;
use IndexPilot\Resolver\Context;
use IndexPilot\Resolver\MetaResolver;
use IndexPilot\Schema\GraphBuilder;
use IndexPilot\Settings\Settings;
use IndexPilot\Tests\Support\ArrayDataSource;
use IndexPilot\Variables\Renderer;
use IndexPilot\Variables\VariableRegistry;
use PHPUnit\Framework\TestCase;

final class AuthorDataTest extends TestCase {

	public function test_only_valid_addresses_become_profile_links(): void {
		$links = AuthorData::links(
			"https://www.twitch.tv/streamer\n twitch.tv/no-scheme \njavascript:alert(1)\nhttps://youtube.com/@streamer, https://www.twitch.tv/streamer\nhttp://t.me/streamer не адрес"
		);

		self::assertSame(
			array( 'https://www.twitch.tv/streamer', 'https://youtube.com/@streamer', 'http://t.me/streamer' ),
			$links
		);
		self::assertSame( array( 'https://vk.com/a' ), AuthorData::links( array( 'https://vk.com/a', '', 'ftp://x.test/' ) ) );
		self::assertCount( AuthorData::MAX_LINKS, AuthorData::links( implode( "\n", array_map( static fn( int $i ): string => 'https://site.test/' . $i, range( 1, 30 ) ) ) ) );
	}

	public function test_links_get_readable_names(): void {
		self::assertSame( 'Twitch', AuthorData::link_label( 'https://www.twitch.tv/streamer' ) );
		self::assertSame( 'YouTube', AuthorData::link_label( 'https://youtu.be/abc' ) );
		self::assertSame( 'Telegram', AuthorData::link_label( 'https://t.me/streamer' ) );
		self::assertSame( 'ВКонтакте', AuthorData::link_label( 'https://vk.com/streamer' ) );
		self::assertSame( 'X', AuthorData::link_label( 'https://twitter.com/streamer' ) );
		self::assertSame( 'example.org', AuthorData::link_label( 'https://www.example.org/me' ) );
	}

	public function test_profile_is_complete_when_it_has_a_bio_or_links(): void {
		self::assertFalse( AuthorData::is_complete( array( 'name' => 'Иван' ) ) );
		self::assertTrue( AuthorData::is_complete( array( 'description' => 'Стример Hearthstone с 2015 года.' ) ) );
		self::assertTrue( AuthorData::is_complete( array( 'same_as' => array( 'https://twitch.tv/x' ) ) ) );
	}

	private function resolver( ArrayDataSource $data ): MetaResolver {
		$registry = new VariableRegistry();
		$registry->register( 'title', static fn(): string => 'Иван Стример' );
		$registry->register( 'sitename', static fn(): string => 'Manacost' );
		$registry->register( 'page', static fn(): string => '' );
		$registry->register( 'author_role', static fn(): string => 'стример' );

		return new MetaResolver( new Settings(), new Renderer( $registry, '—' ), $data );
	}

	public function test_author_without_posts_is_closed_from_indexing(): void {
		$context = new Context( Context::AUTHOR, 7 );

		self::assertFalse( $this->resolver( new ArrayDataSource( count: 0 ) )->resolve( $context )->is_indexable() );
		self::assertTrue( $this->resolver( new ArrayDataSource( count: 1 ) )->resolve( $context )->is_indexable() );
		self::assertTrue( $this->resolver( new ArrayDataSource( count: -1 ) )->resolve( $context )->is_indexable(), 'Неизвестное число записей страницу не закрывает.' );
	}

	public function test_author_page_gets_its_own_title_and_description(): void {
		$context = new Context( Context::AUTHOR, 7 );

		$default = $this->resolver( new ArrayDataSource( count: 3 ) )->resolve( $context );
		self::assertSame( 'Иван Стример, стример — Manacost', $default->title );
		self::assertStringContainsString( 'Иван Стример', $default->description );
		self::assertNotSame( '', $default->description );

		$with_bio = $this->resolver( new ArrayDataSource( texts: array( 'author_bio' => 'Играю в Hearthstone с бета-теста. Беру легенду каждый сезон.' ), count: 3 ) )->resolve( $context );
		self::assertSame( 'Играю в Hearthstone с бета-теста. Беру легенду каждый сезон.', $with_bio->description );

		$custom = $this->resolver( new ArrayDataSource( meta: array( MetaKeys::DESCRIPTION => 'Описание из профиля.' ), texts: array( 'author_bio' => 'Биография.' ), count: 3 ) )->resolve( $context );
		self::assertSame( 'Описание из профиля.', $custom->description );
	}

	public function test_person_node_carries_role_photo_and_profile_links(): void {
		$person = array(
			'name'        => 'Иван Стример',
			'url'         => 'https://site.test/author/ivan/',
			'description' => 'Стример Hearthstone.',
			'role'        => 'стример',
			'image'       => array( 'url' => 'https://site.test/ivan.jpg', 'width' => 400, 'height' => 400 ),
			'same_as'     => array( 'https://twitch.tv/ivan', 'https://youtube.com/@ivan' ),
		);
		$base = array(
			'site'         => array( 'url' => 'https://site.test/', 'name' => 'Manacost', 'language' => 'ru-RU' ),
			'organization' => array(),
			'breadcrumbs'  => array(),
		);

		$article = ( new GraphBuilder() )->build(
			$base + array(
				'page'    => array( 'type' => 'WebPage', 'url' => 'https://site.test/gajd/', 'title' => 'Гайд' ),
				'article' => array( 'type' => 'Article', 'headline' => 'Гайд', 'author' => $person ),
			)
		);
		$node = $article->first( 'Person' );

		self::assertSame( array(), $article->dangling() );
		self::assertSame( 'стример', $node['jobTitle'] );
		self::assertSame( 'Стример Hearthstone.', $node['description'] );
		self::assertSame( array( 'https://twitch.tv/ivan', 'https://youtube.com/@ivan' ), $node['sameAs'] );
		self::assertArrayHasKey( '@id', $node['image'] );
		self::assertSame( $node['@id'], $article->first( 'Article' )['author']['@id'] );

		// На странице автора тот же узел Person — главный объект страницы.
		$profile = ( new GraphBuilder() )->build(
			$base + array(
				'page'    => array( 'type' => 'ProfilePage', 'url' => 'https://site.test/author/ivan/', 'title' => 'Иван Стример' ),
				'profile' => $person,
			)
		);

		self::assertSame( array(), $profile->dangling() );
		self::assertSame( $node['@id'], $profile->first( 'Person' )['@id'], 'У автора один @id на всех страницах сайта.' );
		self::assertSame( $node['@id'], $profile->first( 'ProfilePage' )['mainEntity']['@id'] );
	}
}
