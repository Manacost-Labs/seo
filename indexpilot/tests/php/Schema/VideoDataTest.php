<?php

declare( strict_types=1 );

namespace IndexPilot\Tests\Schema;

use IndexPilot\Schema\GraphBuilder;
use IndexPilot\Schema\VideoData;
use IndexPilot\Schema\VideoDetector;
use IndexPilot\Sitemap\PostTypeProvider;
use PHPUnit\Framework\TestCase;

final class VideoDataTest extends TestCase {

	private const POST = array(
		'title'       => 'Жрец на воскрешении — гайд',
		'description' => 'Как играть жрецом на воскрешении.',
		'date'        => '2026-11-10T12:00:00+00:00',
		'thumbnail'   => '',
	);

	public function test_youtube_video_takes_title_from_oembed_and_dates_from_the_post(): void {
		$detected = VideoDetector::detect( '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>' );
		$fetched  = VideoData::parse_oembed( '{"title":"Жрец на воскрешении: 12 побед подряд","thumbnail_url":"https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg","author_name":"Manacost"}' );

		self::assertNotNull( $fetched );

		$videos = VideoData::build( $detected, array( 'youtube:dQw4w9WgXcQ' => $fetched ), self::POST );

		self::assertCount( 1, $videos );
		self::assertSame( 'Жрец на воскрешении: 12 побед подряд', $videos[0]['title'] );
		self::assertTrue( $videos[0]['fetched'] );
		self::assertSame( '2026-11-10T12:00:00+00:00', $videos[0]['upload_date'] );
		self::assertSame( 'Как играть жрецом на воскрешении.', $videos[0]['description'] );
		self::assertTrue( VideoData::is_complete( $videos[0] ) );

		$node = VideoData::node( $videos[0], 'https://example.test/a/#video-1' );

		self::assertSame( 'VideoObject', $node['@type'] );
		self::assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $node['embedUrl'] );
		self::assertSame( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $node['contentUrl'] );
		self::assertSame( array( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' ), $node['thumbnailUrl'] );
	}

	public function test_without_network_data_comes_from_the_post(): void {
		$videos = VideoData::build( VideoDetector::detect( 'https://youtu.be/dQw4w9WgXcQ' ), array(), self::POST );

		self::assertSame( 'Жрец на воскрешении — гайд', $videos[0]['title'] );
		self::assertFalse( $videos[0]['fetched'] );
		self::assertTrue( VideoData::needs_fetch( $detected = VideoDetector::detect( 'https://youtu.be/dQw4w9WgXcQ' )[0], array() ) );
		self::assertFalse( VideoData::needs_fetch( $detected, array( 'youtube:dQw4w9WgXcQ' => array( 'title' => 'x' ) ) ) );
	}

	public function test_vk_video_without_any_thumbnail_is_incomplete(): void {
		$videos = VideoData::build( VideoDetector::detect( '<iframe src="https://vk.com/video_ext.php?oid=-123&id=456"></iframe>' ), array(), self::POST );

		self::assertFalse( VideoData::is_complete( $videos[0] ) );
		self::assertFalse( VideoData::needs_fetch( VideoDetector::detect( 'https://vk.com/video-123_456' )[0], array() ), 'У VK нет открытого oEmbed.' );

		// Миниатюра записи подходит как превью.
		$with_thumb = VideoData::build( VideoDetector::detect( 'https://vk.com/video-123_456' ), array(), array( 'thumbnail' => 'https://example.test/t.jpg' ) + self::POST );
		self::assertTrue( VideoData::is_complete( $with_thumb[0] ) );
		self::assertArrayNotHasKey( 'contentUrl', VideoData::node( $with_thumb[0], 'x' ) );
	}

	public function test_sitemap_gets_only_complete_videos(): void {
		$youtube = VideoData::build( VideoDetector::detect( 'https://youtu.be/dQw4w9WgXcQ' ), array(), self::POST );
		$vk      = VideoData::build( VideoDetector::detect( 'https://vk.com/video-123_456' ), array(), self::POST );

		$videos = PostTypeProvider::videos( array_merge( $youtube, $vk, array( 'мусор' ) ) );

		self::assertSame(
			array(
				array(
					'thumbnail_loc' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
					'title'         => 'Жрец на воскрешении — гайд',
					'description'   => 'Как играть жрецом на воскрешении.',
					'player_loc'    => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
				),
			),
			$videos
		);
		self::assertSame( array(), PostTypeProvider::videos( '' ) );
	}

	public function test_broken_oembed_answer_is_ignored(): void {
		self::assertNull( VideoData::parse_oembed( '<html>' ) );
		self::assertNull( VideoData::parse_oembed( '{"title":""}' ) );
	}

	public function test_graph_gets_a_video_node_only_for_complete_videos(): void {
		$complete = VideoData::build( VideoDetector::detect( 'https://youtu.be/dQw4w9WgXcQ' ), array(), self::POST );
		$broken   = array( array( 'title' => '' ) + $complete[0] );
		$page     = array(
			'site' => array( 'url' => 'https://example.test/' ),
			'page' => array( 'url' => 'https://example.test/a/' ),
		);

		$graph = ( new GraphBuilder() )->build( $page + array( 'videos' => array_merge( $complete, $broken ) ) );
		$nodes = array_values( array_filter( $graph->nodes(), static fn( array $node ): bool => 'VideoObject' === $node['@type'] ) );

		self::assertSame( array(), $graph->dangling() );

		self::assertCount( 1, $nodes );
		self::assertSame( 'https://example.test/a/#video-1', $nodes[0]['@id'] );
		self::assertSame( '2026-11-10T12:00:00+00:00', $nodes[0]['uploadDate'] );
		self::assertSame( array( '@id' => 'https://example.test/a/#webpage' ), $nodes[0]['isPartOf'] );
	}
}
